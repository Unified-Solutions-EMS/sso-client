<?php

namespace Unified\SsoClient;

use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Cross-instance coordination for OAuth credentials that may only be spent
 * once.
 *
 * The session is the wrong place to enforce "once". Laravel loads a session at
 * the start of a request and writes it back at the end, so two requests that
 * overlap both read the payload as it was before either of them touched it,
 * and the second write silently wins. `Session::pull()` looks atomic and is
 * not: it is a read now and a write several hundred milliseconds later, with
 * a whole token exchange in between.
 *
 * The cache is the right place because `add()` is a single conditional write
 * the backing store resolves itself — SETNX on Redis, a conditional PutItem on
 * DynamoDB, an insert against a unique key on the database store. Exactly one
 * caller gets `true`, whichever app instance it happens to be running on, which
 * is what makes this safe on Laravel Cloud and Vapor where several instances
 * serve the same session.
 *
 * Everything here fails OPEN. A cache outage must not be able to lock the whole
 * platform out of logging in, so a store that throws degrades to the old
 * behaviour rather than denying the request.
 */
class SsoSingleFlight
{
    /**
     * Claim an OAuth state value for this request.
     *
     * Returns true for exactly one caller per state. The loser is a duplicate
     * of a callback another request is already handling — a browser that sent
     * the callback URL twice, a tab restore firing alongside the live
     * navigation, a client retry — and must not attempt the token exchange:
     * the authorization code the duplicate carries is the same one the winner
     * is redeeming, and SSO answers the second redemption with
     * `invalid_grant: Authorization code has been revoked` (UNI-438).
     */
    public function claimOAuthState(string $state): bool
    {
        $ttl = (int) config('sso.state_claim_ttl_seconds', 600);

        try {
            return $this->store()->add($this->key('state', $state), true, $ttl);
        } catch (\Throwable $e) {
            Log::warning('SSO single-flight: could not claim the OAuth state, allowing the callback through', [
                'message' => $e->getMessage(),
            ]);

            return true;
        }
    }

    /**
     * Run a refresh-token exchange at most once per refresh token, and hand the
     * result to every concurrent caller holding that same token.
     *
     * Passport ROTATES refresh tokens: redeeming one revokes it and issues a
     * replacement. A dashboard that polls has several requests in flight when
     * the access token ages out, they all read the same refresh token from the
     * session, and the ones that lose the race present a token SSO has already
     * revoked. Today a losing refresh returns false, the middleware treats that
     * as "signed out", and its session write lands on top of the winner's — so
     * a session with a perfectly good freshly-issued token ends mid-shift
     * (UNI-455).
     *
     * Sharing the winner's tokens means the losers store the same valid pair
     * the winner stored, so whichever write lands last is still correct.
     *
     * @param  Closure():array{access_token: string, refresh_token?: ?string, expires_in?: int}  $refresh
     * @return array{access_token: string, refresh_token?: ?string, expires_in?: int}|null null when
     *                                                                                     another request holds the refresh and did not publish a result in time
     *
     * @throws \Throwable whatever $refresh throws, for the caller that ran it
     */
    public function refreshTokens(string $refreshToken, Closure $refresh): ?array
    {
        $key = $this->key('refresh', $refreshToken);

        try {
            $store = $this->store();
        } catch (\Throwable $e) {
            Log::warning('SSO single-flight: cache unavailable, refreshing without coordination', [
                'message' => $e->getMessage(),
            ]);

            return $refresh();
        }

        if ($shared = $this->publishedResult($store, $key)) {
            return $shared;
        }

        $lock = $store->lock($key.':lock', (int) config('sso.refresh_lock_seconds', 20));

        try {
            $acquired = $lock->block((int) config('sso.refresh_lock_wait_seconds', 8));
        } catch (LockTimeoutException) {
            $acquired = false;
        } catch (\Throwable $e) {
            // A store with no lock support at all (some app's custom driver):
            // better an uncoordinated refresh than no refresh.
            Log::warning('SSO single-flight: no usable lock, refreshing without coordination', [
                'message' => $e->getMessage(),
            ]);

            return $refresh();
        }

        if (! $acquired) {
            // The winner is still working, or it failed and published nothing.
            // Either way this request must not replay a token that is already
            // spent — report no result and let the caller decide.
            return $this->publishedResult($store, $key);
        }

        try {
            // The winner may have finished while this request waited on the
            // lock, so re-read before doing the work again.
            if ($shared = $this->publishedResult($store, $key)) {
                return $shared;
            }

            $tokens = $refresh();

            $store->put($key, $tokens, (int) config('sso.refresh_share_ttl_seconds', 60));

            return $tokens;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{access_token: string, refresh_token?: ?string, expires_in?: int}|null
     */
    protected function publishedResult(Repository $store, string $key): ?array
    {
        try {
            $shared = $store->get($key);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($shared) && isset($shared['access_token']) ? $shared : null;
    }

    protected function store(): Repository
    {
        return Cache::store(config('sso.coordination_store'));
    }

    /**
     * Hash the credential into the key. Anything that can read the cache
     * keyspace learns nothing it did not already have to possess.
     */
    protected function key(string $prefix, string $value): string
    {
        return 'sso-client:'.$prefix.':'.hash('sha256', $value);
    }
}
