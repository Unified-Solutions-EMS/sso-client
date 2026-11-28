<?php

declare(strict_types=1);

namespace Unified\SsoClient\Release;

use Illuminate\Bus\PendingBatch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\PendingDispatch;
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

    /** `unmapped_queues`: a named queue with no map entry goes to `queues.beta` (the default). */
    public const UNMAPPED_TO_BETA = 'beta';

    /** `unmapped_queues`: a named queue with no map entry stays on its own queue (stable's worker). */
    public const UNMAPPED_KEEP = 'keep';

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
     * "leave the job's queue as it is" (stable's default, a named queue the
     * map leaves alone, and always while routing is not active).
     *
     * `$queue` / `$connection` are the job's own (null = the connection's
     * default queue / the default connection). With no `queue_map` every
     * beta agency's job goes to `queues.beta`, whatever its own queue was.
     * With one, a beta agency's job goes to the entry for its queue (an
     * entry for its connection first), a job already on a beta queue stays,
     * a job on the connection's default queue with no entry goes to
     * `queues.beta`, and any other queue with no entry goes to
     * `queues.beta` or, with `unmapped_queues` = `keep`, stays where it is.
     * The connection is never changed.
     */
    public static function queue(string $channel, ?string $queue = null, ?string $connection = null): ?string
    {
        if (! self::routingActive()) {
            return null;
        }

        if ($channel !== self::BETA) {
            return self::configuredQueue(self::STABLE);
        }

        $beta = self::configuredQueue(self::BETA);
        $connection = self::connectionName($connection);
        $original = $queue !== null && trim($queue) !== '' ? trim($queue) : self::connectionDefaultQueue($connection);
        $map = self::queueMap();

        $mapped = $map['connections'][$connection][$original] ?? $map['any'][$original] ?? null;

        if ($mapped !== null) {
            return $mapped;
        }

        if ($original === $beta || in_array($original, self::betaQueues(), true)) {
            return $original;
        }

        if ($original === self::connectionDefaultQueue($connection) || self::unmappedQueues() !== self::UNMAPPED_KEEP) {
            return $beta;
        }

        return null;
    }

    /**
     * @param  Model|int|string|null  $company  the local company, or its local id; null = stable
     * @param  string|null  $queue  the job's own queue (null = the connection default)
     * @param  string|null  $connection  the job's own connection (null = the default one)
     */
    public static function queueForCompany(Model|int|string|null $company, ?string $queue = null, ?string $connection = null): ?string
    {
        return self::routingActive() ? self::queue(self::forCompany($company), $queue, $connection) : null;
    }

    public static function queueForSsoCompanyId(int|string|null $ssoCompanyId, ?string $queue = null, ?string $connection = null): ?string
    {
        return self::routingActive() ? self::queue(self::forSsoCompanyId($ssoCompanyId), $queue, $connection) : null;
    }

    /**
     * Puts anything that is queued for one agency on that agency's queue,
     * read from what it is set to now: a job, a queued mailable or
     * notification, `dispatch()`'s pending dispatch, a `Bus::chain()` or a
     * `Bus::batch()` (a batch's jobs all run on the batch's queue, so the
     * batch is what needs it). Call it after the queue is set. Only the
     * queue is changed, never the connection; stable agencies and apps
     * without release channels are left exactly as they were.
     *
     *     ReleaseChannel::onAgencyChannel(Bus::batch($jobs)->onQueue('imports'), $company)->dispatch();
     *     Mail::to($user)->queue(ReleaseChannel::onAgencyChannel(new ClaimPaid($claim), $company));
     *
     * @template T of object
     *
     * @param  T  $queueable
     * @param  Model|int|string|null  $company  the local company, or its local id
     * @return T
     */
    public static function onAgencyChannel(object $queueable, Model|int|string|null $company): object
    {
        return self::routingActive() ? self::applyChannel($queueable, self::forCompany($company)) : $queueable;
    }

    /**
     * @template T of object
     *
     * @param  T  $queueable
     * @return T
     */
    public static function onAgencyChannelBySsoCompanyId(object $queueable, int|string|null $ssoCompanyId): object
    {
        return self::routingActive() ? self::applyChannel($queueable, self::forSsoCompanyId($ssoCompanyId)) : $queueable;
    }

    /**
     * @template T of object
     *
     * @param  T  $queueable
     * @return T
     */
    public static function applyChannel(object $queueable, string $channel): object
    {
        if ($queueable instanceof PendingDispatch) {
            self::applyChannel($queueable->getJob(), $channel);

            return $queueable;
        }

        if (! method_exists($queueable, 'onQueue')) {
            return $queueable;
        }

        if ($queueable instanceof PendingBatch) {
            $current = $queueable->queue();
            $connection = $queueable->connection();
        } else {
            $current = $queueable->queue ?? null;
            $connection = $queueable->connection ?? null;
        }

        $queue = self::queue($channel, is_string($current) ? $current : null, is_string($connection) ? $connection : null);

        if ($queue !== null) {
            $queueable->onQueue($queue);
        }

        return $queueable;
    }

    /**
     * The queue name a channel's worker should listen on, for the command's
     * listing and the worker setup: the configured name, else the default
     * connection's own queue. With a queue map, beta also needs a worker
     * for each queue in `betaQueues()`.
     */
    public static function workerQueue(string $channel): string
    {
        return self::configuredQueue($channel === self::BETA ? self::BETA : self::STABLE)
            ?? self::connectionDefaultQueue(self::connectionName(null));
    }

    /**
     * `sso.release.queue_map`, split into entries for any connection
     * (`'dictation' => 'beta-dictation'`) and entries for one connection
     * (`'redis-long' => ['imports' => 'beta-imports']`).
     *
     * @return array{any: array<string, string>, connections: array<string, array<string, string>>}
     */
    public static function queueMap(): array
    {
        $map = ['any' => [], 'connections' => []];

        foreach ((array) config('sso.release.queue_map', []) as $key => $value) {
            $key = trim((string) $key);

            if ($key === '') {
                continue;
            }

            if (is_string($value) && trim($value) !== '') {
                $map['any'][$key] = trim($value);
            } elseif (is_array($value)) {
                foreach ($value as $queue => $target) {
                    if (is_string($target) && trim($target) !== '' && trim((string) $queue) !== '') {
                        $map['connections'][$key][trim((string) $queue)] = trim($target);
                    }
                }
            }
        }

        return $map;
    }

    /**
     * Every queue a beta agency's job can be put on: the queue map's
     * targets and `queues.beta`. Beta runs a worker on each.
     *
     * @return list<string>
     */
    public static function betaQueues(): array
    {
        $map = self::queueMap();
        $queues = array_values($map['any']);

        foreach ($map['connections'] as $entries) {
            array_push($queues, ...array_values($entries));
        }

        $beta = self::configuredQueue(self::BETA);

        if ($beta !== null) {
            $queues[] = $beta;
        }

        return array_values(array_unique($queues));
    }

    /** `beta` (default) or `keep`: what happens to a named queue the map has no entry for. */
    public static function unmappedQueues(): string
    {
        return strtolower(trim((string) config('sso.release.unmapped_queues', self::UNMAPPED_TO_BETA))) === self::UNMAPPED_KEEP
            ? self::UNMAPPED_KEEP
            : self::UNMAPPED_TO_BETA;
    }

    public static function connectionName(?string $connection): string
    {
        return $connection !== null && trim($connection) !== '' ? trim($connection) : (string) config('queue.default', 'sync');
    }

    public static function connectionDefaultQueue(string $connection): string
    {
        $queue = config("queue.connections.{$connection}.queue");

        return is_string($queue) && trim($queue) !== '' ? trim($queue) : 'default';
    }

    private static function configuredQueue(string $channel): ?string
    {
        $queue = config("sso.release.queues.{$channel}");

        return is_string($queue) && trim($queue) !== '' ? trim($queue) : null;
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
