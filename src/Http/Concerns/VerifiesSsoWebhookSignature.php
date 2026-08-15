<?php

declare(strict_types=1);

namespace Unified\SsoClient\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Unified\SsoClient\Security\SecurityEvents;

/**
 * Shared HMAC verification for the package's SSO-signed endpoints
 * (webhook, dashboard, actions). A failed verification is recorded as a
 * webhook.signature_failed security event — in normal operation that
 * count is zero, so any sustained rate means someone is probing the
 * endpoint (or a webhook secret has drifted out of sync).
 */
trait VerifiesSsoWebhookSignature
{
    protected function verifySignature(Request $request): bool
    {
        $secret = config('sso.webhook_secret');

        if (! $secret) {
            Log::warning('SSO webhook: no webhook_secret configured, rejecting request');

            return false;
        }

        $signature = $request->header('X-SSO-Signature');

        $valid = is_string($signature)
            && $signature !== ''
            && hash_equals(hash_hmac('sha256', $request->getContent(), $secret), $signature);

        if (! $valid) {
            app(SecurityEvents::class)->warning('webhook.signature_failed', [
                'sso_event' => $request->input('event'),
                'signature_present' => ! empty($signature),
            ]);
        }

        return $valid;
    }
}
