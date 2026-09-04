<?php

namespace Unified\SsoClient\Tests\Unit;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Exceptions\CompanyLinkCollisionException;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\User;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;
use Unified\SsoClient\Tests\TestCase;

/**
 * UNI-416: Pelican Ambulance was provisioned into an app before SSO linked its
 * legacy tenant id, leaving two local company rows. Once the link existed, the
 * synchronizer tried to stamp the legacy tenant id onto the sso-matched row on
 * every login, hit the unique key held by the older row, and threw. The SSO
 * callback's catch-all then redirected back into the SSO flow, which succeeded
 * instantly against the live SSO session, and the browser looped forever.
 */
class CompanyLinkCollisionTest extends TestCase
{
    /**
     * The base test schema has no unique keys. Production does, and the unique
     * key is the whole reason this guard exists, so mirror it here.
     */
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('companies', function (Blueprint $table) {
            $table->unique('sso_company_id');
            $table->unique('core_tenant_id');
        });
    }

    private function payload(array $companies): array
    {
        return [
            'user' => [
                'id' => 5001,
                'email' => 'medic@example.com',
                'displayName' => 'Jordan Medic',
            ],
            'companies' => $companies,
        ];
    }

    public function test_login_survives_a_legacy_tenant_id_already_owned_by_an_unlinked_row(): void
    {
        // The row SSO matches: linked, but never had the legacy id stamped.
        $ssoMatched = Company::create(['name' => 'Pelican Ambulance', 'sso_company_id' => 700]);
        // The older duplicate that owns the legacy tenant id and nothing else.
        $legacyOrphan = Company::create(['name' => 'Pelican Ambulance Inc', 'core_tenant_id' => '3875']);

        [$user, $selected] = (new StubSynchronizer)->synchronize($this->payload([
            ['id' => 700, 'name' => 'Pelican Ambulance', 'legacyTenantId' => 3875, 'roles' => ['Admin']],
        ]));

        $this->assertInstanceOf(User::class, $user, 'The login must complete despite the data conflict.');
        $this->assertSame($ssoMatched->id, $selected->id);

        // Neither row was touched: the merge is a human decision.
        $this->assertNull($ssoMatched->fresh()->core_tenant_id);
        $this->assertSame('3875', $legacyOrphan->fresh()->core_tenant_id);
        $this->assertNull($legacyOrphan->fresh()->sso_company_id);

        // Membership and roles still synced against the matched row.
        $this->assertDatabaseHas('company_user', ['company_id' => $ssoMatched->id, 'user_id' => $user->id]);
    }

    public function test_the_collision_is_logged_and_reported_for_a_human_to_merge(): void
    {
        $warnings = [];
        Log::listen(function (MessageLogged $entry) use (&$warnings) {
            if ($entry->level === 'warning') {
                $warnings[] = $entry;
            }
        });

        $reported = new RecordingExceptionHandler;
        $this->app->instance(ExceptionHandler::class, $reported);

        $ssoMatched = Company::create(['name' => 'Pelican Ambulance', 'sso_company_id' => 700]);
        $legacyOrphan = Company::create(['name' => 'Pelican Ambulance Inc', 'core_tenant_id' => '3875']);

        (new StubSynchronizer)->synchronize($this->payload([
            ['id' => 700, 'name' => 'Pelican Ambulance', 'legacyTenantId' => 3875, 'roles' => ['User']],
        ]));

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('company link collision', $warnings[0]->message);
        $this->assertSame([
            'column' => 'core_tenant_id',
            'value' => '3875',
            'matched_company_id' => $ssoMatched->id,
            'conflicting_company_id' => $legacyOrphan->id,
            'sso_company_id' => 700,
            'conflicting_row_is_linked_to_sso' => false,
        ], $warnings[0]->context);

        $this->assertCount(1, $reported->exceptions);
        $collision = $reported->exceptions[0];
        $this->assertInstanceOf(CompanyLinkCollisionException::class, $collision);
        $this->assertSame('core_tenant_id', $collision->column);
        $this->assertSame($legacyOrphan->id, $collision->conflictingCompanyId);
        $this->assertStringContainsString('manual merge', $collision->getMessage());
    }

    public function test_login_survives_when_the_conflicting_row_belongs_to_another_sso_company(): void
    {
        $ssoMatched = Company::create(['name' => 'Pelican Ambulance', 'sso_company_id' => 700]);
        $otherAgency = Company::create(['name' => 'Gulf EMS', 'sso_company_id' => 900, 'core_tenant_id' => '3875']);

        [$user] = (new StubSynchronizer)->synchronize($this->payload([
            ['id' => 700, 'name' => 'Pelican Ambulance', 'legacyTenantId' => 3875, 'roles' => ['User']],
        ]));

        $this->assertInstanceOf(User::class, $user);
        $this->assertNull($ssoMatched->fresh()->core_tenant_id);
        $this->assertSame('900', (string) $otherAgency->fresh()->sso_company_id);
    }

    public function test_an_uncontested_legacy_tenant_id_is_still_stamped(): void
    {
        $company = Company::create(['name' => 'Pelican Ambulance', 'sso_company_id' => 700]);

        (new StubSynchronizer)->synchronize($this->payload([
            ['id' => 700, 'name' => 'Pelican Ambulance', 'legacyTenantId' => 3875, 'roles' => ['User']],
        ]));

        $this->assertSame('3875', $company->fresh()->core_tenant_id);
    }

    /**
     * The `sso_company_id` stamp carries the same guard. In a single sync the
     * bulk preload normally matches that row first, so the collision only shows
     * up when a concurrent login stamps the id between this request's preload
     * and its save. Exercise the guard directly for that race.
     */
    public function test_the_same_guard_protects_the_sso_company_id_stamp(): void
    {
        $reported = new RecordingExceptionHandler;
        $this->app->instance(ExceptionHandler::class, $reported);

        $matched = Company::create(['name' => 'Pelican Ambulance', 'core_tenant_id' => '3875']);
        $raceWinner = Company::create(['name' => 'Pelican Ambulance Inc', 'sso_company_id' => 700]);

        $synchronizer = new ExposedStubSynchronizer;

        $this->assertFalse(
            $synchronizer->claims($matched, 'sso_company_id', 700),
            'The stamp must be refused while another row holds the id.'
        );
        $this->assertTrue(
            $synchronizer->claims($matched, 'sso_company_id', 701),
            'An unclaimed id must still be stampable.'
        );

        $this->assertCount(1, $reported->exceptions);
        $this->assertSame('sso_company_id', $reported->exceptions[0]->column);
        $this->assertSame($raceWinner->id, $reported->exceptions[0]->conflictingCompanyId);
    }
}

class RecordingExceptionHandler implements ExceptionHandler
{
    /** @var array<int, \Throwable> */
    public array $exceptions = [];

    public function report(\Throwable $e): void
    {
        $this->exceptions[] = $e;
    }

    public function shouldReport(\Throwable $e): bool
    {
        return true;
    }

    public function render($request, \Throwable $e)
    {
        throw $e;
    }

    public function renderForConsole($output, \Throwable $e): void
    {
        throw $e;
    }
}

class ExposedStubSynchronizer extends StubSynchronizer
{
    public function claims($company, string $column, int|string $value): bool
    {
        return $this->linkValueIsClaimable($this->getCompanyModelClass(), $company, $column, $value, 700);
    }
}
