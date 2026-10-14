<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\Models\SsoSessionAction;
use Unified\SsoClient\Tests\Feature\MasterData\MasterDataTestCase;
use Unified\SsoClient\Tests\Stubs\Models\Company;
use Unified\SsoClient\Tests\Stubs\Models\Role;
use Unified\SsoClient\Tests\Stubs\Models\User;

class DeviceRevokedWebhookTest extends MasterDataTestCase
{
    protected bool $runMirrorMigration = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (! class_exists('App\\Models\\User')) {
            class_alias(User::class, 'App\\Models\\User');
            class_alias(Company::class, 'App\\Models\\Company');
            class_alias(Role::class, 'App\\Models\\Role');
        }
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('session.driver', 'database');
        $app['config']->set('session.table', 'sessions');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        $this->loadMigrationsFrom(dirname(__DIR__, 2).'/database/migrations');
    }

    private function makeUser(string $ssoId): int
    {
        return (int) DB::table('users')->insertGetId(['sso_id' => $ssoId, 'email' => "u{$ssoId}@example.test"]);
    }

    private function seedSession(string $id, ?int $userId): void
    {
        DB::table('sessions')->insert([
            'id' => $id, 'user_id' => $userId, 'payload' => 'x', 'last_activity' => time(),
        ]);
    }

    public function test_it_deletes_only_the_revoked_users_sessions(): void
    {
        $target = $this->makeUser('501');
        $other = $this->makeUser('502');
        $this->seedSession('a', $target);
        $this->seedSession('b', $target);
        $this->seedSession('c', $other);
        $this->seedSession('guest', null);

        $this->postWebhook('device.revoked', [
            'company' => ['id' => 70],
            'user' => ['id' => 501],
            'device' => ['id' => 42],
        ])->assertOk()->assertJsonPath('action', 'device.revoked');

        $this->assertSame(['c', 'guest'], DB::table('sessions')->orderBy('id')->pluck('id')->all());
        $this->assertSame(
            [SsoSessionAction::ACTION_FORCE_LOGOUT],
            SsoSessionAction::query()->where('user_id', $target)->pluck('action')->all(),
        );
        $this->assertSame(0, SsoSessionAction::query()->where('user_id', $other)->count());
    }

    public function test_an_unknown_device_id_is_still_acknowledged(): void
    {
        $user = $this->makeUser('501');
        $this->seedSession('a', $user);

        $this->postWebhook('device.revoked', [
            'company' => ['id' => 70],
            'user' => ['id' => 501],
            'device' => ['id' => 999999],
        ])->assertOk();

        $this->assertSame(0, DB::table('sessions')->count());
    }

    public function test_an_unknown_user_is_acknowledged_and_touches_nothing(): void
    {
        $user = $this->makeUser('501');
        $this->seedSession('a', $user);

        $this->postWebhook('device.revoked', [
            'company' => ['id' => 70],
            'user' => ['id' => 777],
            'device' => ['id' => 42],
        ])->assertOk()->assertJsonPath('skipped', true);

        $this->assertSame(1, DB::table('sessions')->count());
    }

    public function test_a_non_database_session_driver_only_queues_the_force_logout(): void
    {
        config(['session.driver' => 'file']);
        $user = $this->makeUser('501');
        $this->seedSession('a', $user);

        $this->postWebhook('device.revoked', ['user' => ['id' => 501], 'device' => ['id' => 42]])->assertOk();

        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertSame(1, SsoSessionAction::query()->where('user_id', $user)->count());
    }

    public function test_an_unsigned_request_is_rejected_and_sessions_survive(): void
    {
        $user = $this->makeUser('501');
        $this->seedSession('a', $user);

        $this->postJson('/api/sso/provision', ['event' => 'device.revoked', 'user' => ['id' => 501]])
            ->assertStatus(403);

        $this->assertSame(1, DB::table('sessions')->count());
        $this->assertSame(0, SsoSessionAction::query()->count());
    }
}
