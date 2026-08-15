<?php

namespace Unified\SsoClient\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Removes the throwaway "Sample User" crew logins a trial signup creates, once
 * SSO confirms the owning company is no longer on a trial.
 *
 * Converting a trial does not clean up its seeded demo data, so converted
 * customers keep carrying the sample accounts in crew pickers, rosters and
 * assignment lists indefinitely. This command is the supported way to clear
 * them, in place of hand-run tinker snippets: it is idempotent, transactional,
 * and refuses to guess.
 *
 * Three properties matter more than convenience here:
 *
 *  - It fails closed. SSO owns companies.status, so an unreachable or
 *    malformed classification response aborts the run rather than falling back
 *    to a local guess (DEV_GUIDELINES 19). Deleting a live trial's crew mid
 *    evaluation is worse than leaving residue.
 *  - It never destroys a record it did not create. Anything referencing a
 *    purged account is unlinked, not deleted, so ledgers keep their arithmetic
 *    and history keeps its rows. Only membership/assignment rows go away.
 *  - It aborts on anything it does not understand. A NOT NULL reference with
 *    no declared policy is reported as a blocker instead of being force-nulled
 *    or cascaded, because the safe resolution differs per app.
 */
class PurgeFakeUsersCommand extends Command
{
    protected $signature = 'sso:purge-fake-users
        {--dry-run : Report the plan and roll back without writing}
        {--allow-blockers : Skip accounts held by a NOT NULL reference rather than aborting on them}';

    protected $description = 'Remove trial sample-crew accounts that SSO reports are no longer on a live trial.';

    /** @var array<int, string> */
    private array $blockers = [];

    public function handle(): int
    {
        $classification = $this->fetchClassification();

        if ($classification === null) {
            return self::FAILURE;
        }

        $domain = (string) $classification['email_domain'];
        $protected = collect($classification['protected'])
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->flip();

        $usersTable = (string) config('sso.fake_user_purge.users_table', 'users');

        $targets = DB::table($usersTable)
            ->where('email', 'like', '%'.$domain)
            ->get(['id', 'email'])
            ->reject(fn (object $u): bool => $protected->has(mb_strtolower(trim((string) $u->email))));

        $this->line(sprintf(
            'SSO protects %d sample account(s) on live trials; %d of this app\'s %d matching account(s) are purgeable.',
            $protected->count(),
            $targets->count(),
            $targets->count() + $protected->count(),
        ));

        if ($targets->isEmpty()) {
            $this->info('Nothing to purge.');

            return self::SUCCESS;
        }

        $ids = $targets->pluck('id')->all();
        $plan = $this->buildPlan($usersTable, $ids);

        if ($this->blockers !== [] && ! $this->option('allow-blockers')) {
            $this->error('Aborting: references with no safe resolution.');
            foreach ($this->blockers as $blocker) {
                $this->line('  '.$blocker);
            }
            $this->line('Declare each table under sso.fake_user_purge.pivot_tables (row is membership, delete it)');
            $this->line('or make the column nullable, then re-run. --allow-blockers skips the affected accounts.');

            return self::FAILURE;
        }

        return $this->applyPlan($usersTable, $ids, $plan, $classification['company_owners'] ?? []);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchClassification(): ?array
    {
        $baseUrl = rtrim((string) config('sso.base_url'), '/');
        $key = (string) config('sso.core_api_key');

        if ($key === '') {
            $this->error('CORE_APP_API_KEY is not configured; refusing to run.');

            return null;
        }

        try {
            $response = Http::timeout((int) config('sso.timeout', 10))
                ->withHeaders(['X-API-KEY' => $key])
                ->acceptJson()
                ->get($baseUrl.'/api/internal/trial-fake-users');
        } catch (\Throwable $e) {
            $this->error('Could not reach SSO: '.$e->getMessage());

            return null;
        }

        if ($response->failed()) {
            $this->error('SSO returned HTTP '.$response->status().'; refusing to run.');

            return null;
        }

        $payload = $response->json();

        // A response missing these keys means the contract changed. Treating an
        // absent allow-list as "protect nothing" would purge every live trial.
        if (! is_array($payload) || ! array_key_exists('protected', $payload) || ! isset($payload['email_domain'])) {
            $this->error('SSO response is missing the protected/email_domain contract; refusing to run.');

            return null;
        }

        if (! is_array($payload['protected'])) {
            $this->error('SSO returned a malformed protected list; refusing to run.');

            return null;
        }

        return $payload;
    }

    /**
     * Discover every reference to the target accounts and decide what to do with it.
     *
     * Uses Laravel's schema introspection rather than information_schema so the
     * same code path runs on MySQL in production and SQLite under test.
     *
     * @param  array<int, mixed>  $ids
     * @return array<int, array{table: string, column: string, action: string, count: int, type_column: ?string}>
     */
    private function buildPlan(string $usersTable, array $ids): array
    {
        $pivots = (array) config('sso.fake_user_purge.pivot_tables', []);
        $extraColumns = (array) config('sso.fake_user_purge.reference_columns', []);
        $polymorphic = (array) config('sso.fake_user_purge.polymorphic', []);

        $plan = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'];

            if ($name === $usersTable) {
                continue;
            }

            $columns = collect(Schema::getColumns($name))->keyBy('name');
            $referencing = [];

            foreach (Schema::getForeignKeys($name) as $fk) {
                if (($fk['foreign_table'] ?? null) === $usersTable) {
                    foreach ($fk['columns'] as $column) {
                        $referencing[$column] = true;
                    }
                }
            }

            // Columns that point at users by convention without a declared
            // constraint — the common case across these apps.
            foreach ($extraColumns as $column) {
                if ($columns->has($column)) {
                    $referencing[$column] = true;
                }
            }

            if (isset($polymorphic[$name])) {
                $referencing[$polymorphic[$name][0]] = true;
            }

            foreach (array_keys($referencing) as $column) {
                $typeColumn = isset($polymorphic[$name]) && $polymorphic[$name][0] === $column
                    ? $polymorphic[$name][1]
                    : null;

                $count = $this->countRows($name, $column, $typeColumn, $ids);

                if ($count === 0) {
                    continue;
                }

                if (in_array($name, $pivots, true)) {
                    $action = 'delete';
                } elseif ($columns->get($column)['nullable'] ?? false) {
                    $action = 'unlink';
                } else {
                    $this->blockers[] = sprintf('%s.%s holds %d row(s) and is NOT NULL', $name, $column, $count);

                    continue;
                }

                $plan[] = compact('action', 'count') + [
                    'table' => $name,
                    'column' => $column,
                    'type_column' => $typeColumn,
                ];
            }
        }

        return $plan;
    }

    /**
     * @param  array<int, mixed>  $ids
     */
    private function countRows(string $table, string $column, ?string $typeColumn, array $ids): int
    {
        $query = DB::table($table)->whereIn($column, $ids);

        if ($typeColumn !== null) {
            $query->where($typeColumn, 'like', '%User');
        }

        return $query->count();
    }

    /**
     * @param  array<int, mixed>  $ids
     * @param  array<int, array{table: string, column: string, action: string, count: int, type_column: ?string}>  $plan
     * @param  array<string, mixed>  $companyOwners
     */
    private function applyPlan(string $usersTable, array $ids, array $plan, array $companyOwners): int
    {
        $dryRun = (bool) $this->option('dry-run');

        DB::beginTransaction();

        try {
            $rows = [];

            foreach ($this->reassignOwners($usersTable, $ids, $companyOwners) as $line) {
                $rows[] = $line;
            }

            foreach ($plan as $step) {
                $query = DB::table($step['table'])->whereIn($step['column'], $ids);

                if ($step['type_column'] !== null) {
                    $query->where($step['type_column'], 'like', '%User');
                }

                $affected = $step['action'] === 'delete'
                    ? $query->delete()
                    : $query->update([$step['column'] => null]);

                $rows[] = [$step['table'].'.'.$step['column'], $step['action'], (string) $affected];
            }

            $deleted = DB::table($usersTable)->whereIn('id', $ids)->delete();
            $rows[] = [$usersTable, 'delete', (string) $deleted];

            $this->table(['target', 'action', 'rows'], $rows);

            if ($dryRun) {
                DB::rollBack();
                $this->warn('Dry run: rolled back, nothing was written.');

                return self::SUCCESS;
            }

            DB::commit();
            $this->info(sprintf('Purged %d sample account(s).', $deleted));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error('Rolled back: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Repoint any company owned by a sample account at its real SSO owner.
     *
     * Nulling would be simpler, but some apps treat owner_id as present. Where
     * SSO has no owner to offer — a demo tenant SSO has since deleted — null is
     * the only honest answer.
     *
     * @param  array<int, mixed>  $ids
     * @param  array<string, mixed>  $companyOwners
     * @return array<int, array<int, string>>
     */
    private function reassignOwners(string $usersTable, array $ids, array $companyOwners): array
    {
        $companiesTable = (string) config('sso.fake_user_purge.companies_table', 'companies');

        if (! Schema::hasTable($companiesTable) || ! Schema::hasColumn($companiesTable, 'owner_id')) {
            return [];
        }

        $orphaned = DB::table($companiesTable)->whereIn('owner_id', $ids)->get(['id', 'sso_company_id']);
        $rows = [];

        foreach ($orphaned as $company) {
            $ssoOwnerId = $companyOwners[(string) $company->sso_company_id] ?? null;

            $newOwner = $ssoOwnerId === null
                ? null
                : DB::table($usersTable)->where('sso_id', (string) $ssoOwnerId)->value('id');

            DB::table($companiesTable)->where('id', $company->id)->update(['owner_id' => $newOwner]);

            $rows[] = [
                $companiesTable.'.owner_id#'.$company->id,
                $newOwner === null ? 'null' : 'reassign',
                $newOwner === null ? '1' : 'user '.$newOwner,
            ];
        }

        return $rows;
    }
}
