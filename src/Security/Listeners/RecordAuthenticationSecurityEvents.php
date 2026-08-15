<?php

declare(strict_types=1);

namespace Unified\SsoClient\Security\Listeners;

use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Contracts\Events\Dispatcher;
use Unified\SsoClient\Security\SecurityEvents;

/**
 * Records Laravel's built-in auth events as security events, so every
 * consuming app reports failed logins, lockouts, and password resets to
 * SSO without per-app wiring. Registered by the service provider unless
 * config('security.listen_auth_events') is disabled.
 */
class RecordAuthenticationSecurityEvents
{
    public function __construct(
        protected SecurityEvents $security,
    ) {}

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Failed::class => 'handleFailed',
            Lockout::class => 'handleLockout',
            PasswordReset::class => 'handlePasswordReset',
        ];
    }

    public function handleFailed(Failed $event): void
    {
        $email = $this->credentialEmail($event->credentials);

        if ($this->security->isCanaryEmail($email)) {
            $this->security->critical('auth.canary_login_attempt', [
                'email' => $email,
            ]);
        }

        $this->security->warning('auth.failed_login', array_filter([
            'email' => $email,
            'guard' => $event->guard,
            'user_known' => $event->user !== null,
            'local_user_id' => $event->user?->getAuthIdentifier(),
        ], static fn ($v) => $v !== null));
    }

    public function handleLockout(Lockout $event): void
    {
        $this->security->warning('auth.lockout', array_filter([
            'email' => $this->credentialEmail($event->request->only('email', 'username')),
        ], static fn ($v) => $v !== null));
    }

    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->security->info('auth.password_reset', [
            'local_user_id' => $event->user->getAuthIdentifier(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function credentialEmail(array $credentials): ?string
    {
        $email = $credentials['email'] ?? $credentials['username'] ?? null;

        return is_string($email) && $email !== '' ? $email : null;
    }
}
