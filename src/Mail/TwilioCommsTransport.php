<?php

declare(strict_types=1);

namespace Unified\SsoClient\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;
use Symfony\Component\Mime\Part\DataPart;

/**
 * Sends Laravel mail through Twilio's Comms email API (POST /v1/Emails).
 *
 * What the API refuses decides most of this class. Its documented body has
 * from, to, content (subject, html, text, headers, attachments), tags,
 * schedule and ipPoolName; there is no cc, bcc or reply-to field, and
 * To / CC / BCC / Reply-To are on its list of headers that cannot be set.
 * Each `to` entry is its own copy of the message (that is how per-recipient
 * Liquid variables work), so:
 *  - to and cc go out in one request, every address its own copy;
 *  - each bcc address goes in a request of its own, so no other recipient
 *    can ever be shown it;
 *  - a reply-to is dropped (sending it 400s the whole message) and the drop
 *    is logged with the Message-ID only.
 *
 * Nothing that can carry patient information (body, subject, addresses,
 * Twilio's error text, which echoes rejected values) goes into a log line or
 * an exception message: the status and Twilio's error code are enough to act.
 */
class TwilioCommsTransport extends AbstractTransport
{
    /** Twilio's documented limit for the whole request, attachments included. */
    public const MAX_REQUEST_BYTES = 10 * 1024 * 1024;

    /**
     * Headers Twilio refuses to take from the caller, plus the envelope and
     * MIME headers Symfony writes that Twilio builds itself.
     */
    private const RESERVED_HEADERS = [
        'bcc', 'cc', 'content-transfer-encoding', 'content-type', 'date',
        'dkim-signature', 'from', 'message-id', 'mime-version', 'received',
        'reply-to', 'return-path', 'sender', 'subject', 'to', 'x-sg-eid',
        'x-sg-id',
    ];

    private const RETRYABLE_STATUSES = [429, 503];

    public function __construct(
        private readonly ?string $accountSid,
        private readonly ?string $authToken,
        private readonly string $endpoint,
        private readonly int $connectTimeout = 5,
        private readonly int $timeout = 30,
        private readonly int $retries = 2,
        private readonly int $maxRetryWait = 5,
        private readonly ?string $defaultFromName = null,
    ) {
        parent::__construct();
    }

    /**
     * The mailer's own config entry wins for the endpoint (SSO's and the
     * website's config/mail.php set one); everything else comes from the
     * package's twilio-comms config.
     *
     * @param  array<string, mixed>  $mailer
     */
    public static function fromConfig(array $mailer): self
    {
        return new self(
            accountSid: config('twilio-comms.account_sid'),
            authToken: config('twilio-comms.auth_token'),
            endpoint: (string) ($mailer['endpoint'] ?? config('twilio-comms.endpoint')),
            connectTimeout: (int) config('twilio-comms.connect_timeout', 5),
            timeout: (int) config('twilio-comms.timeout', 30),
            retries: (int) config('twilio-comms.retries', 2),
            maxRetryWait: (int) config('twilio-comms.max_retry_wait', 5),
            defaultFromName: is_string($name = config('mail.from.name')) ? $name : null,
        );
    }

    protected function doSend(SentMessage $message): void
    {
        if (blank($this->accountSid) || blank($this->authToken)) {
            throw new TransportException('Twilio Comms email: TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN must be set.');
        }

        $original = $message->getOriginalMessage();

        if (! $original instanceof Message) {
            throw new TransportException('Twilio Comms email: only MIME messages can be sent.');
        }

        $email = MessageConverter::toEmail($original);

        if ($email->getReplyTo() !== []) {
            Log::warning('Twilio Comms email: reply-to dropped, the API does not accept one', [
                'message_id' => $message->getMessageId(),
                'reply_to_count' => count($email->getReplyTo()),
            ]);
        }

        $batches = array_map(fn (Address $bcc): array => [$bcc], $this->bccRecipients($email));

        if (($open = $this->openRecipients($email)) !== []) {
            array_unshift($batches, $open);
        }

        if ($batches === []) {
            throw new TransportException('Twilio Comms email: the message has no recipients.');
        }

        $operationIds = array_map(fn (array $recipients): string => $this->deliver($this->payload($email, $recipients)), $batches);

        if ($operationIds[0] !== '') {
            $message->setMessageId($operationIds[0]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function deliver(array $payload): string
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (strlen($body) > self::MAX_REQUEST_BYTES) {
            throw new TransportException(sprintf(
                'Twilio Comms email: the message is %.1f MB with attachments, over Twilio\'s 10 MB limit.',
                strlen($body) / 1024 / 1024,
            ));
        }

        $attempt = 0;

        while (true) {
            $attempt++;
            $response = $this->post($body);

            if ($response->successful()) {
                return (string) ($response->json('operationId') ?? '');
            }

            if ($attempt > $this->retries || ! in_array($response->status(), self::RETRYABLE_STATUSES, true)) {
                throw $this->failure($response);
            }

            Sleep::for($this->retryWait($response))->seconds();
        }
    }

    private function post(string $body): Response
    {
        try {
            return Http::withBasicAuth((string) $this->accountSid, (string) $this->authToken)
                ->connectTimeout($this->connectTimeout)
                ->timeout($this->timeout)
                ->acceptJson()
                ->withBody($body, 'application/json')
                ->post($this->endpoint);
        } catch (ConnectionException $e) {
            // Not retried: Twilio may have accepted a request whose answer timed
            // out, and the API has no idempotency key to make a resend safe.
            throw new TransportException(
                'Twilio Comms email: no answer from Twilio (connection failed or timed out); the message may or may not have been accepted.',
                0,
                $e,
            );
        }
    }

    private function failure(Response $response): TransportException
    {
        $code = $this->twilioErrorCode($response);

        return new TransportException(
            "Twilio Comms email send failed (HTTP {$response->status()}".($code !== null ? ", Twilio error {$code}" : '').').',
            $response->status(),
        );
    }

    /**
     * The error code only. Twilio's message text echoes the values it rejected
     * (an address, a header), so it never leaves this method.
     */
    private function twilioErrorCode(Response $response): ?string
    {
        $json = $response->json();

        if (! is_array($json)) {
            return null;
        }

        foreach ([$json['code'] ?? null, $json['errors'][0]['code'] ?? null, $json['error']['code'] ?? null] as $code) {
            if (is_int($code) || (is_string($code) && preg_match('/^[A-Za-z0-9_.-]{1,64}$/', $code) === 1)) {
                return (string) $code;
            }
        }

        return null;
    }

    private function retryWait(Response $response): int
    {
        $header = $response->header('Retry-After');
        $seconds = ctype_digit($header) ? (int) $header : 1;

        return max(1, min($seconds, $this->maxRetryWait));
    }

    /**
     * @param  list<Address>  $recipients
     * @return array<string, mixed>
     */
    private function payload(Email $email, array $recipients): array
    {
        $html = $email->getHtmlBody();
        $text = $email->getTextBody();
        $html = is_string($html) ? $html : (is_resource($html) ? (string) stream_get_contents($html) : null);
        $text = is_string($text) ? $text : (is_resource($text) ? (string) stream_get_contents($text) : null);

        // Twilio requires an html part; Mail::raw() and text-only mailables have none.
        if ($html === null && $text !== null) {
            $html = nl2br(htmlspecialchars($text, ENT_QUOTES), false);
        }

        $content = array_filter([
            'subject' => (string) $email->getSubject(),
            'html' => $html ?? '',
            'text' => $text,
        ], fn (?string $value): bool => $value !== null);

        if (($headers = $this->customHeaders($email)) !== []) {
            $content['headers'] = $headers;
        }

        if (($attachments = $this->attachments($email, $html ?? '')) !== []) {
            $content['attachments'] = $attachments;
        }

        return [
            'from' => $this->sender($email),
            'to' => array_map(fn (Address $address): array => $this->recipient($address), $recipients),
            'content' => $content,
        ];
    }

    /**
     * @return array{address: string, name: string}
     */
    private function sender(Email $email): array
    {
        $from = $email->getFrom()[0] ?? null;

        if ($from === null) {
            throw new TransportException('Twilio Comms email: the message has no from address.');
        }

        // Twilio requires a sender name.
        return [
            'address' => $from->getAddress(),
            'name' => $from->getName() !== '' ? $from->getName() : ($this->defaultFromName ?: $from->getAddress()),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function recipient(Address $address): array
    {
        return array_filter([
            'address' => $address->getAddress(),
            'name' => $address->getName(),
        ], fn (string $value): bool => $value !== '');
    }

    /**
     * @return list<Address>
     */
    private function openRecipients(Email $email): array
    {
        return $this->unique([...$email->getTo(), ...$email->getCc()]);
    }

    /**
     * @return list<Address>
     */
    private function bccRecipients(Email $email): array
    {
        $open = array_map(fn (Address $address): string => mb_strtolower($address->getAddress()), $this->openRecipients($email));

        return array_values(array_filter(
            $this->unique($email->getBcc()),
            fn (Address $address): bool => ! in_array(mb_strtolower($address->getAddress()), $open, true),
        ));
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return list<Address>
     */
    private function unique(array $addresses): array
    {
        $seen = [];

        foreach ($addresses as $address) {
            $seen[mb_strtolower($address->getAddress())] ??= $address;
        }

        return array_values($seen);
    }

    /**
     * Headers the caller added (List-Unsubscribe, X-* tracking headers), which
     * Twilio documents under content.headers.
     *
     * @return array<string, string>
     */
    private function customHeaders(Email $email): array
    {
        $headers = [];

        foreach ($email->getHeaders()->all() as $header) {
            if (in_array(mb_strtolower($header->getName()), self::RESERVED_HEADERS, true)) {
                continue;
            }

            $headers[$header->getName()] = str_replace(["\r", "\n"], '', $header->getBodyAsString());
        }

        return $headers;
    }

    /**
     * @return list<array<string, string>>
     */
    private function attachments(Email $email, string $html): array
    {
        return array_values(array_map(function (DataPart $part) use ($html): array {
            $attachment = [
                'filename' => $part->getFilename() ?: 'attachment',
                'contentType' => $part->getMediaType().'/'.$part->getMediaSubtype(),
                'content' => base64_encode($part->getBody()),
            ];

            if ($part->getDisposition() === 'inline') {
                $attachment['cid'] = $this->contentId($part, $html);
            }

            return $attachment;
        }, $email->getAttachments()));
    }

    /**
     * Laravel's $message->embed() writes cid:<generated id> into the HTML;
     * Symfony's Email::embed($file, 'logo') lets the HTML say cid:logo and
     * only rewrites it while building a MIME body, which this transport never
     * does. Use whichever one the HTML actually references.
     */
    private function contentId(DataPart $part, string $html): string
    {
        $name = $part->getName();

        if ($name !== null && ! str_contains($html, 'cid:'.$part->getContentId()) && str_contains($html, 'cid:'.$name)) {
            return $name;
        }

        return $part->getContentId();
    }

    public function __toString(): string
    {
        return 'twilio-comms';
    }
}
