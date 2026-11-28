<?php

declare(strict_types=1);

namespace Unified\SsoClient\Tests\Feature\Release;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Unified\SsoClient\SsoUserSynchronizer;

/**
 * SSO says which release each agency runs in this app: `/api/user`
 * `companies[].releaseChannel` at sign-in, and `release_channel` on
 * `company.updated`. Only stable and beta are accepted; anything else, or
 * nothing, leaves the local value alone.
 */
class ReleaseChannelSyncTest extends ReleaseChannelTestCase
{
    /**
     * @param  array<int, array<string, mixed>>  $companies
     */
    private function signIn(array $companies): void
    {
        app(SsoUserSynchronizer::class)->synchronize([
            'user' => ['id' => '900', 'email' => 'pat900@example.test', 'displayName' => 'Pat Medic'],
            'companies' => $companies,
            'staffRoles' => [],
        ]);
    }

    private function channelOf(int $ssoCompanyId): ?string
    {
        return DB::table('companies')->where('sso_company_id', (string) $ssoCompanyId)->value('release_channel');
    }

    public function test_the_migration_adds_the_column_with_stable_as_default(): void
    {
        $this->company(70);

        $this->assertSame('stable', $this->channelOf(70));
    }

    public function test_sign_in_writes_the_channel_sso_sends_for_this_app(): void
    {
        $this->agency(70);

        $this->signIn([['id' => 70, 'name' => 'Agency 70', 'roles' => ['User'], 'releaseChannel' => 'beta']]);
        $this->assertSame('beta', $this->channelOf(70));

        $this->signIn([['id' => 70, 'name' => 'Agency 70', 'roles' => ['User'], 'releaseChannel' => 'stable']]);
        $this->assertSame('stable', $this->channelOf(70));
    }

    public function test_a_new_company_created_at_sign_in_gets_its_channel(): void
    {
        $this->signIn([['id' => 71, 'name' => 'Agency 71', 'roles' => ['User'], 'releaseChannel' => 'beta']]);

        $this->assertSame('beta', $this->channelOf(71));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function valuesLeftAlone(): array
    {
        return [
            'absent' => [[]],
            'null' => [['releaseChannel' => null]],
            'alpha is not an agency channel' => [['releaseChannel' => 'alpha']],
            'unknown' => [['releaseChannel' => 'gamma']],
            'not a string' => [['releaseChannel' => 1]],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    #[DataProvider('valuesLeftAlone')]
    public function test_sign_in_leaves_the_channel_alone_without_a_known_value(array $extra): void
    {
        $this->agency(72, 'beta');

        $this->signIn([['id' => 72, 'name' => 'Agency 72', 'roles' => ['User']] + $extra]);

        $this->assertSame('beta', $this->channelOf(72));
    }

    public function test_company_updated_writes_the_channel_from_the_company_block(): void
    {
        $this->agency(80);

        $this->postWebhook('company.updated', ['company' => ['id' => 80, 'name' => 'Agency 80', 'release_channel' => 'beta']])
            ->assertOk()
            ->assertJson(['action' => 'company.updated']);

        $this->assertSame('beta', $this->channelOf(80));
    }

    public function test_company_updated_accepts_the_channel_at_the_top_level_too(): void
    {
        $this->agency(81, 'beta');

        $this->postWebhook('company.updated', ['company' => ['id' => 81], 'release_channel' => 'stable'])->assertOk();

        $this->assertSame('stable', $this->channelOf(81));
    }

    public function test_company_updated_without_a_known_value_leaves_the_channel_alone(): void
    {
        $this->agency(82, 'beta');

        $this->postWebhook('company.updated', ['company' => ['id' => 82, 'name' => 'Renamed']])->assertOk();
        $this->postWebhook('company.updated', ['company' => ['id' => 82, 'release_channel' => 'alpha']])->assertOk();

        $this->assertSame('beta', $this->channelOf(82));
        $this->assertSame('Renamed', DB::table('companies')->where('sso_company_id', '82')->value('name'));
    }
}
