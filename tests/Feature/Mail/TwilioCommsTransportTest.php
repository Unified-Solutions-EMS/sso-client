<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Sleep;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use Unified\SsoClient\Mail\TwilioCommsTransport;
use Unified\SsoClient\Tests\TestCase;

class TwilioCommsTransportTest extends TestCase
{
    private const ENDPOINT = 'https://comms.twilio.com/v1/Emails';

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail.default', 'twilio-comms');
        $app['config']->set('mail.from', ['address' => 'noreply@example.test', 'name' => 'Unified']);
        $app['config']->set('twilio-comms.account_sid', 'AC-test-sid');
        $app['config']->set('twilio-comms.auth_token', 'test-token');
    }

    protected function setUp(): void
    {
        parent::setUp();

        Sleep::fake();
    }

    private function accepted(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['operationId' => 'op_123', 'operationLocation' => self::ENDPOINT.'/Operations/op_123'], 202)]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sentPayloads(): array
    {
        return Http::recorded()->map(fn (array $pair): array => $pair[0]->data())->values()->all();
    }

    public function test_the_package_registers_the_mailer_for_an_app_with_no_mail_config_of_its_own(): void
    {
        $this->assertSame(['transport' => 'twilio-comms'], config('mail.mailers.twilio-comms'));
        $this->assertInstanceOf(TwilioCommsTransport::class, Mail::mailer('twilio-comms')->getSymfonyTransport());
        $this->assertSame('twilio-comms', (string) Mail::mailer('twilio-comms')->getSymfonyTransport());
    }

    public function test_plain_text_mail_gets_an_html_part_twilio_requires(): void
    {
        $this->accepted();

        Mail::raw("Line one <b>\nLine two", function (Message $message): void {
            $message->to('crew@example.test', 'Crew Member')->subject('Shift reminder');
        });

        Http::assertSent(function (Request $request): bool {
            $data = $request->data();

            return $request->url() === self::ENDPOINT
                && $request->method() === 'POST'
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('AC-test-sid:test-token'))
                && $data['from'] === ['address' => 'noreply@example.test', 'name' => 'Unified']
                && $data['to'] === [['address' => 'crew@example.test', 'name' => 'Crew Member']]
                && $data['content']['subject'] === 'Shift reminder'
                && $data['content']['text'] === "Line one <b>\nLine two"
                && $data['content']['html'] === "Line one &lt;b&gt;<br>\nLine two";
        });
    }

    public function test_html_only_mail_sends_no_text_part(): void
    {
        $this->accepted();

        Mail::html('<p>Hello</p>', fn (Message $message) => $message->to('a@example.test')->subject('Hi'));

        $content = $this->sentPayloads()[0]['content'];
        $this->assertSame('<p>Hello</p>', $content['html']);
        $this->assertArrayNotHasKey('text', $content);
        $this->assertArrayNotHasKey('attachments', $content);
    }

    public function test_html_and_text_mail_sends_both_parts(): void
    {
        $this->accepted();

        Mail::html('<p>Hello</p>', function (Message $message): void {
            $message->to('a@example.test')->subject('Hi');
            $message->getSymfonyMessage()->text('Hello');
        });

        $content = $this->sentPayloads()[0]['content'];
        $this->assertSame('<p>Hello</p>', $content['html']);
        $this->assertSame('Hello', $content['text']);
    }

    public function test_several_to_and_cc_recipients_go_in_one_request_without_duplicates(): void
    {
        $this->accepted();

        Mail::html('<p>Hi</p>', function (Message $message): void {
            $message->to(['one@example.test', 'two@example.test'])
                ->cc(['three@example.test', 'ONE@example.test'])
                ->subject('Hi');
        });

        Http::assertSentCount(1);
        $payload = $this->sentPayloads()[0];
        $this->assertSame(
            ['one@example.test', 'two@example.test', 'three@example.test'],
            array_column($payload['to'], 'address'),
        );
        $this->assertArrayNotHasKey('cc', $payload);
        $this->assertArrayNotHasKey('bcc', $payload);
    }

    public function test_each_bcc_recipient_gets_a_request_of_their_own(): void
    {
        $this->accepted();

        Mail::html('<p>Hi</p>', function (Message $message): void {
            $message->to('open@example.test')
                ->bcc(['hidden1@example.test', 'hidden2@example.test', 'open@example.test'])
                ->subject('Hi');
        });

        $payloads = $this->sentPayloads();
        $this->assertCount(3, $payloads);
        $this->assertSame([['address' => 'open@example.test']], $payloads[0]['to']);
        $this->assertSame([['address' => 'hidden1@example.test']], $payloads[1]['to']);
        $this->assertSame([['address' => 'hidden2@example.test']], $payloads[2]['to']);

        foreach ($payloads as $payload) {
            $this->assertArrayNotHasKey('bcc', $payload);
        }
    }

    public function test_bcc_only_mail_is_sent_one_request_per_address(): void
    {
        $this->accepted();

        Mail::html('<p>Hi</p>', fn (Message $message) => $message->bcc(['h1@example.test', 'h2@example.test'])->subject('Hi'));

        $this->assertSame(
            [[['address' => 'h1@example.test']], [['address' => 'h2@example.test']]],
            array_column($this->sentPayloads(), 'to'),
        );
    }

    public function test_reply_to_is_dropped_the_mail_still_sends_and_the_drop_is_logged_without_addresses(): void
    {
        $this->accepted();
        Log::spy();

        $sent = Mail::html('<p>Hi</p>', function (Message $message): void {
            $message->to('a@example.test')->replyTo('agency.admin@example.test', 'Agency Admin')->subject('Hi');
        });

        $this->assertNotNull($sent);
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->body(), 'agency.admin@example.test')
            && ! str_contains(strtolower($request->body()), 'replyto')
            && ! str_contains(strtolower($request->body()), 'reply-to'));

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $line, array $context): bool {
            return str_contains($line, 'reply-to dropped')
                && array_keys($context) === ['message_id', 'reply_to_count']
                && is_string($context['message_id']) && $context['message_id'] !== ''
                && $context['reply_to_count'] === 1
                && ! str_contains(json_encode($context), 'agency.admin');
        });
    }

    public function test_attachments_and_inline_images_are_sent_base64_with_content_ids(): void
    {
        $this->accepted();
        $pdf = "%PDF-1.4\n%binary\x00\xff bytes";

        Mail::send([], [], function (Message $message) use ($pdf): void {
            $logo = $message->embedData('PNGDATA', 'logo.png', 'image/png');
            $message->to('a@example.test')->subject('Run report')
                ->html('<p><img src="'.$logo.'"> Your report</p>')
                ->attachData($pdf, 'run-report.pdf', ['mime' => 'application/pdf']);
        });

        $content = $this->sentPayloads()[0]['content'];
        $this->assertCount(2, $content['attachments']);

        [$inline, $file] = $content['attachments'];
        $this->assertSame('logo.png', $inline['filename']);
        $this->assertSame('image/png', $inline['contentType']);
        $this->assertSame(base64_encode('PNGDATA'), $inline['content']);
        $this->assertStringContainsString('cid:'.$inline['cid'], $content['html']);

        $this->assertSame(['filename' => 'run-report.pdf', 'contentType' => 'application/pdf', 'content' => base64_encode($pdf)], $file);
    }

    public function test_symfony_named_inline_references_keep_the_name_the_html_uses(): void
    {
        $this->accepted();

        Mail::send([], [], function (Message $message): void {
            $message->to('a@example.test')->subject('Hi')->html('<img src="cid:logo">');
            $message->getSymfonyMessage()->embed('PNGDATA', 'logo', 'image/png');
        });

        $this->assertSame('logo', $this->sentPayloads()[0]['content']['attachments'][0]['cid']);
    }

    public function test_custom_headers_are_forwarded_and_envelope_headers_are_not(): void
    {
        $this->accepted();

        Mail::html('<p>Hi</p>', function (Message $message): void {
            $message->to('a@example.test')->subject('Hi');
            $headers = $message->getSymfonyMessage()->getHeaders();
            $headers->addTextHeader('List-Unsubscribe', '<https://example.test/unsubscribe>');
            $headers->addTextHeader('X-Campaign-ID', 'weekly');
        });

        $this->assertSame(
            ['List-Unsubscribe' => '<https://example.test/unsubscribe>', 'X-Campaign-ID' => 'weekly'],
            $this->sentPayloads()[0]['content']['headers'],
        );
    }

    public function test_a_twilio_error_raises_status_and_code_but_nothing_from_the_message(): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'code' => 20001,
            'message' => "Invalid value 'patient.name@example.test' for to",
            'errors' => [['code' => 20001, 'message' => 'Jane Doe, DOB 01/02/1960']],
        ], 400)]);

        try {
            Mail::html('<p>Jane Doe, chest pain</p>', fn (Message $message) => $message->to('patient.name@example.test', 'Jane Doe')->subject('Run 2026-1182 for Jane Doe'));
            $this->fail('A 400 from Twilio must throw.');
        } catch (TransportException $e) {
            $this->assertSame('Twilio Comms email send failed (HTTP 400, Twilio error 20001).', $e->getMessage());
            $this->assertSame(400, $e->getCode());
            $this->assertNull($e->getPrevious());
            $this->assertSame('', $e->getDebug());

            foreach (['patient.name', 'Jane', 'Doe', 'chest', '2026-1182', 'Invalid value', 'DOB'] as $sensitive) {
                $this->assertStringNotContainsString($sensitive, $e->getMessage());
            }
        }

        Http::assertSentCount(1);
    }

    public function test_an_error_without_a_code_still_names_the_status(): void
    {
        Http::fake([self::ENDPOINT => Http::response('<html>Bad gateway for a@example.test</html>', 502)]);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Twilio Comms email send failed (HTTP 502).');

        Mail::html('<p>Hi</p>', fn (Message $message) => $message->to('a@example.test')->subject('Hi'));
    }

    public function test_rate_limits_and_503s_are_retried_after_retry_after_then_succeed(): void
    {
        Http::fakeSequence(self::ENDPOINT)
            ->push(['code' => 20429], 429, ['Retry-After' => '3'])
            ->push([], 503, ['Retry-After' => '60'])
            ->push(['operationId' => 'op_9'], 202);

        Mail::html('<p>Hi</p>', fn (Message $message) => $message->to('a@example.test')->subject('Hi'));

        Http::assertSentCount(3);
        Sleep::assertSequence([Sleep::for(3)->seconds(), Sleep::for(5)->seconds()]);
    }

    public function test_retries_stop_after_the_configured_count(): void
    {
        Http::fake([self::ENDPOINT => Http::response(['code' => 20429], 429)]);

        try {
            Mail::html('<p>Hi</p>', fn (Message $message) => $message->to('a@example.test')->subject('Hi'));
            $this->fail('A persistent 429 must throw.');
        } catch (TransportException $e) {
            $this->assertSame('Twilio Comms email send failed (HTTP 429, Twilio error 20429).', $e->getMessage());
        }

        Http::assertSentCount(3);
    }

    public function test_a_500_is_not_retried(): void
    {
        Http::fake([self::ENDPOINT => Http::response([], 500)]);

        $this->expectException(TransportException::class);

        try {
            Mail::html('<p>Hi</p>', fn (Message $message) => $message->to('a@example.test')->subject('Hi'));
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_a_timeout_raises_once_without_a_retry_and_without_message_details(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds for '.self::ENDPOINT));

        try {
            Mail::html('<p>Jane Doe</p>', fn (Message $message) => $message->to('patient@example.test')->subject('Jane Doe'));
            $this->fail('A timeout must throw.');
        } catch (TransportException $e) {
            $this->assertStringContainsString('no answer from Twilio', $e->getMessage());
            $this->assertStringNotContainsString('patient@example.test', $e->getMessage());
            $this->assertStringNotContainsString('Jane', $e->getMessage());
            $this->assertInstanceOf(ConnectionException::class, $e->getPrevious());
        }

        Sleep::assertNeverSlept();
    }

    public function test_the_http_client_uses_explicit_timeouts(): void
    {
        config(['twilio-comms.timeout' => 12, 'twilio-comms.connect_timeout' => 3]);
        $options = [];
        Http::fake(function (Request $request, array $requestOptions) use (&$options) {
            $options = $requestOptions;

            return Http::response(['operationId' => 'op'], 202);
        });

        Mail::html('<p>Hi</p>', fn (Message $message) => $message->to('a@example.test')->subject('Hi'));

        $this->assertSame(12, $options['timeout']);
        $this->assertSame(3, $options['connect_timeout']);
    }

    public function test_missing_credentials_fail_before_anything_is_sent(): void
    {
        config(['twilio-comms.auth_token' => null]);
        Http::fake();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('TWILIO_ACCOUNT_SID and TWILIO_AUTH_TOKEN must be set');

        try {
            Mail::html('<p>Hi</p>', fn (Message $message) => $message->to('a@example.test')->subject('Hi'));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_a_message_over_twilios_size_limit_is_refused_before_sending(): void
    {
        Http::fake();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageMatches('/over Twilio\'s 10 MB limit/');

        try {
            Mail::send([], [], fn (Message $message) => $message->to('a@example.test')->subject('Hi')->html('<p>Hi</p>')
                ->attachData(str_repeat('a', 8 * 1024 * 1024), 'big.pdf', ['mime' => 'application/pdf']));
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_the_endpoint_in_the_apps_own_mailer_config_wins(): void
    {
        config(['mail.mailers.twilio-comms' => ['transport' => 'twilio-comms', 'endpoint' => 'https://comms.example.test/v1/Emails']]);
        Http::fake(['https://comms.example.test/*' => Http::response(['operationId' => 'op'], 202)]);

        Mail::html('<p>Hi</p>', fn (Message $message) => $message->to('a@example.test')->subject('Hi'));

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://comms.example.test/v1/Emails');
    }

    public function test_an_apps_own_twilio_comms_registration_wins_over_the_packages(): void
    {
        $own = new class implements TransportInterface
        {
            public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
            {
                return null;
            }

            public function __toString(): string
            {
                return 'app-copy';
            }
        };

        Mail::extend('twilio-comms', fn (): TransportInterface => $own);

        $this->assertSame($own, Mail::mailer('twilio-comms')->getSymfonyTransport());
    }
}
