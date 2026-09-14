<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Tests\TestCase;

class PurgeFakeUsersCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'sso.base_url' => 'https://sso.test',
            'sso.core_api_key' => 'test-key',
        ]);

        // A ledger-style table: rows must survive, only the link is cut.
        Schema::create('inventory_txns', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->integer('quantity')->default(1);
        });
    }

    private function sso(array $payload, int $status = 200): void
    {
        Http::fake([
            'https://sso.test/api/internal/trial-fake-users' => Http::response($payload, $status),
        ]);
    }

    private function classification(array $protected = [], array $owners = []): array
    {
        return [
            'email_domain' => '@fakemail.com',
            'protected' => $protected,
            'protected_count' => count($protected),
            'purgeable' => [],
            'company_owners' => $owners,
        ];
    }

    private function user(string $email, ?string $ssoId = null): int
    {
        return DB::table('users')->insertGetId(['email' => $email, 'sso_id' => $ssoId]);
    }

    public function test_it_purges_a_converted_tenants_sample_accounts(): void
    {
        $this->sso($this->classification());
        $fake = $this->user('111@fakemail.com');
        $real = $this->user('medic@agency.org');
        DB::table('company_user')->insert(['company_id' => 1, 'user_id' => $fake]);

        $this->artisan('sso:purge-fake-users')->assertSuccessful();

        $this->assertDatabaseMissing('users', ['id' => $fake]);
        $this->assertDatabaseHas('users', ['id' => $real]);
        $this->assertDatabaseCount('company_user', 0);
    }

    public function test_it_never_touches_an_account_protected_by_a_live_trial(): void
    {
        $this->sso($this->classification(protected: ['222@fakemail.com']));
        $onTrial = $this->user('222@fakemail.com');
        $converted = $this->user('333@fakemail.com');

        $this->artisan('sso:purge-fake-users')->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $onTrial]);
        $this->assertDatabaseMissing('users', ['id' => $converted]);
    }

    /**
     * The whole point of the unlink policy: a purged account must not take
     * ledger rows with it, or stock levels silently change.
     */
    public function test_it_unlinks_records_rather_than_deleting_them(): void
    {
        $this->sso($this->classification());
        $fake = $this->user('444@fakemail.com');
        DB::table('inventory_txns')->insert([
            ['user_id' => $fake, 'quantity' => 5],
            ['user_id' => $fake, 'quantity' => 7],
        ]);

        $this->artisan('sso:purge-fake-users')->assertSuccessful();

        $this->assertDatabaseCount('inventory_txns', 2);
        $this->assertSame(12, (int) DB::table('inventory_txns')->sum('quantity'));
        $this->assertSame(2, DB::table('inventory_txns')->whereNull('user_id')->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $this->sso($this->classification());
        $fake = $this->user('555@fakemail.com');
        DB::table('company_user')->insert(['company_id' => 1, 'user_id' => $fake]);

        $this->artisan('sso:purge-fake-users', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('users', ['id' => $fake]);
        $this->assertDatabaseCount('company_user', 1);
    }

    public function test_it_fails_closed_when_sso_is_unreachable(): void
    {
        $this->sso(['error' => 'boom'], 500);
        $fake = $this->user('666@fakemail.com');

        $this->artisan('sso:purge-fake-users')->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $fake]);
    }

    /**
     * An allow-list that arrives empty because the contract changed would purge
     * every live trial, so a missing key must abort rather than default.
     */
    public function test_it_fails_closed_on_a_malformed_contract(): void
    {
        $this->sso(['email_domain' => '@fakemail.com']);
        $fake = $this->user('777@fakemail.com');

        $this->artisan('sso:purge-fake-users')->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $fake]);
    }

    public function test_it_aborts_on_a_not_null_reference_it_has_no_policy_for(): void
    {
        Schema::create('audit_entries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
        });

        $this->sso($this->classification());
        $fake = $this->user('888@fakemail.com');
        DB::table('audit_entries')->insert(['user_id' => $fake]);

        $this->artisan('sso:purge-fake-users')->assertFailed();

        $this->assertDatabaseHas('users', ['id' => $fake]);
        $this->assertDatabaseCount('audit_entries', 1);
    }

    public function test_it_reassigns_a_company_owned_by_a_sample_account(): void
    {
        $fake = $this->user('999@fakemail.com');
        $realOwner = $this->user('owner@agency.org', ssoId: '111');
        DB::table('companies')->insert([
            'id' => 5, 'name' => 'Converted Co', 'sso_company_id' => '29', 'owner_id' => $fake,
        ]);

        $this->sso($this->classification(owners: ['29' => 111]));

        $this->artisan('sso:purge-fake-users')->assertSuccessful();

        $this->assertSame($realOwner, DB::table('companies')->where('id', 5)->value('owner_id'));
    }

    /**
     * A demo tenant SSO has since deleted offers no owner to restore; null is
     * the only honest answer and must not block the purge.
     */
    public function test_it_nulls_an_owner_sso_can_no_longer_resolve(): void
    {
        $fake = $this->user('1010@fakemail.com');
        DB::table('companies')->insert([
            'id' => 6, 'name' => 'Dead Demo', 'sso_company_id' => '26', 'owner_id' => $fake,
        ]);

        $this->sso($this->classification(owners: []));

        $this->artisan('sso:purge-fake-users')->assertSuccessful();

        $this->assertNull(DB::table('companies')->where('id', 6)->value('owner_id'));
        $this->assertDatabaseMissing('users', ['id' => $fake]);
    }

    public function test_it_is_idempotent(): void
    {
        $this->sso($this->classification());
        $this->user('1111@fakemail.com');

        $this->artisan('sso:purge-fake-users')->assertSuccessful();
        $this->artisan('sso:purge-fake-users')->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
    }
}
