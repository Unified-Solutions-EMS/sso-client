<?php

declare(strict_types=1);

namespace Unified\SsoClient\Release;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Which release (alpha, beta, stable) this deployment runs, which one an
 * agency belongs to (`companies.release_channel`, set by SSO), and where
 * that release's pages and queued jobs go.
 *
 * Inert until configured: on a stable deployment with no beta address,
 * every queue lookup answers null without touching the database, so jobs
 * keep exactly the queue they had before.
 */
final class ReleaseChannel
{
    public const ALPHA = 'alpha';

    public const BETA = 'beta';

    public const STABLE = 'stable';

    /** What a deployment can run (APP_CHANNEL). */
    public const DEPLOYMENTS = [self::ALPHA, self::BETA, self::STABLE];

    /** What an agency can be on; the only values SSO sends and the sync accepts. */
    public const AGENCY_CHANNELS = [self::STABLE, self::BETA];

    public const COLUMN = 'release_channel';

    /** How long "the column is not there yet" is believed before asking the database again. */
    public const MISSING_COLUMN_RECHECK_SECONDS = 60;

    /** @var array<string, true> tables known to have the column */
    private static array $columnPresent = [];

    /** @var array<string, int> tables last seen without the column, and when */
    private static array $columnMissingAt = [];

    public static function current(): string
    {
        $channel = strtolower(trim((string) config('sso.release.channel', self::STABLE)));

        return in_array($channel, self::DEPLOYMENTS, true) ? $channel : self::STABLE;
    }

    /**
     * An agency's value from SSO, or null when it is not one of the two
     * known values (the caller then leaves the local value alone).
     */
    public static function agencyValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = strtolower(trim($value));

        return in_array($value, self::AGENCY_CHANNELS, true) ? $value : null;
    }

    /**
     * @param  Model|int|string|null  $company  the local company, or its local id
     */
    public static function forCompany(Model|int|string|null $company): string
    {
        if ($company === null || $company === '' || ! self::hasColumn()) {
            return self::STABLE;
        }

        if ($company instanceof Model) {
            if (array_key_exists(self::COLUMN, $company->getAttributes())) {
                return self::agencyValue($company->getAttribute(self::COLUMN)) ?? self::STABLE;
            }

            $company = $company->getKey();
        }

        return self::agencyValue(self::companyModel()::query()->whereKey($company)->value(self::COLUMN)) ?? self::STABLE;
    }

    public static function forSsoCompanyId(int|string|null $ssoCompanyId): string
    {
        if ($ssoCompanyId === null || $ssoCompanyId === '' || ! self::hasColumn()) {
            return self::STABLE;
        }

        return self::agencyValue(self::companyModel()::query()
            ->where('sso_company_id', $ssoCompanyId)
            ->value(self::COLUMN)) ?? self::STABLE;
    }

    public static function url(string $channel): ?string
    {
        $url = trim((string) config("sso.release.urls.{$channel}", ''));

        return $url === '' ? null : rtrim($url, '/');
    }

    /**
     * Whether agencies are sorted between deployments here at all: never on
     * alpha (its own database), and on stable only once a beta deployment
     * exists to send them to.
     */
    public static function routingActive(): bool
    {
        $current = self::current();

        return $current === self::BETA || ($current === self::STABLE && self::url(self::BETA) !== null);
    }

    /**
     * The queue a job for an agency on this channel goes to, or null for
     * "leave the job's queue as it is" (stable's default, and always while
     * routing is not active).
     */
    public static function queue(string $channel): ?string
    {
        if (! self::routingActive()) {
            return null;
        }

        $channel = $channel === self::BETA ? self::BETA : self::STABLE;
        $queue = config("sso.release.queues.{$channel}");

        return is_string($queue) && trim($queue) !== '' ? trim($queue) : null;
    }

    /**
     * @param  Model|int|string|null  $company  the local company, or its local id; null = stable
     */
    public static function queueForCompany(Model|int|string|null $company): ?string
    {
        return self::routingActive() ? self::queue(self::forCompany($company)) : null;
    }

    public static function queueForSsoCompanyId(int|string|null $ssoCompanyId): ?string
    {
        return self::routingActive() ? self::queue(self::forSsoCompanyId($ssoCompanyId)) : null;
    }

    /**
     * The queue name a channel's worker should listen on, for the command's
     * listing and the worker setup: the configured name, else the default
     * connection's own queue.
     */
    public static function workerQueue(string $channel): string
    {
        $queue = config('sso.release.queues.'.($channel === self::BETA ? self::BETA : self::STABLE));

        if (is_string($queue) && trim($queue) !== '') {
            return trim($queue);
        }

        $connection = (string) config('queue.default', 'sync');

        return (string) (config("queue.connections.{$connection}.queue") ?: 'default');
    }

    /** @return class-string<Model> */
    public static function companyModel(): string
    {
        return (string) config('sso.release.company_model', 'App\\Models\\Company');
    }

    /**
     * An app that has not run the package migration yet reads every agency
     * as stable and the sync skips the column.
     */
    public static function hasColumn(): bool
    {
        $model = self::companyModel();

        if (! class_exists($model)) {
            return false;
        }

        return self::tableHasColumn((new $model)->getTable());
    }

    /**
     * Only "the column is there" is remembered for the life of the process.
     * "Not there" is asked again after a minute, so a long-running Octane or
     * queue worker started before the migration picks the column up without
     * a restart. Once it exists this is a lookup with no query.
     */
    public static function tableHasColumn(string $table): bool
    {
        if (isset(self::$columnPresent[$table])) {
            return true;
        }

        $now = Carbon::now()->getTimestamp();
        $missingAt = self::$columnMissingAt[$table] ?? null;

        if ($missingAt !== null && $now - $missingAt < self::MISSING_COLUMN_RECHECK_SECONDS) {
            return false;
        }

        if (Schema::hasColumn($table, self::COLUMN)) {
            self::$columnPresent[$table] = true;
            unset(self::$columnMissingAt[$table]);

            return true;
        }

        self::$columnMissingAt[$table] = $now;

        return false;
    }

    public static function flushSchemaCache(): void
    {
        self::$columnPresent = [];
        self::$columnMissingAt = [];
    }
}
