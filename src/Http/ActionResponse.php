<?php

declare(strict_types=1);

namespace Unified\SsoClient\Http;

use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

/**
 * Typed result for an SsoActionHandler that needs a status other than 200.
 * The array convention (`http_status` in the returned array) still works;
 * this is the explicit form for new handlers.
 */
final readonly class ActionResponse
{
    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $status,
        public array $body = [],
        public array $headers = [],
    ) {
        if ($status < 200 || $status > 599) {
            throw new InvalidArgumentException("Invalid action response status: {$status}");
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function ok(array $body = []): self
    {
        return new self(200, $body);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(404, ['error' => $message]);
    }

    public static function tooManyRequests(string $message = 'Too many requests', ?int $retryAfterSeconds = null): self
    {
        if ($retryAfterSeconds === null) {
            return new self(429, ['error' => $message]);
        }

        return new self(
            429,
            ['error' => $message, 'retry_after' => $retryAfterSeconds],
            ['Retry-After' => (string) $retryAfterSeconds],
        );
    }

    public static function notImplemented(string $message = 'Not implemented'): self
    {
        return new self(501, ['error' => $message]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public static function error(int $status, array $body): self
    {
        return new self($status, $body);
    }

    /**
     * Normalise a handler's return value. An array carrying an integer
     * `http_status` (200-599) uses it as the status, with the key removed
     * from the body; any other array is a plain 200, exactly as before.
     *
     * @param  array<string, mixed>|self  $result
     */
    public static function from(array|self $result): self
    {
        if ($result instanceof self) {
            return $result;
        }

        $status = $result['http_status'] ?? null;

        if (is_int($status) && $status >= 200 && $status <= 599) {
            unset($result['http_status']);

            return new self($status, $result);
        }

        return new self(200, $result);
    }

    public function toResponse(): JsonResponse
    {
        return response()->json($this->body, $this->status, $this->headers);
    }
}
