<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Unified\SsoClient\Tests\Feature\MasterData\MasterDataTestCase;

/**
 * Events the package does not handle must be acknowledged with a 200 so SSO
 * records them delivered instead of retrying them forever. SSO renamed
 * personnel.* to person.*: the old names and person.created stay unknown,
 * while person.updated / person.deactivated now go to the personnel mirror,
 * which acks them as ignored while the app has not turned it on.
 */
class SsoWebhookUnknownEventTest extends MasterDataTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function unhandledEvents(): array
    {
        return [
            'person.created' => ['person.created'],
            'legacy personnel.updated' => ['personnel.updated'],
            'made-up event' => ['something.nobody.sends'],
        ];
    }

    #[DataProvider('unhandledEvents')]
    public function test_an_unhandled_event_is_acknowledged_as_ignored(string $event): void
    {
        $this->company(70);

        $this->postWebhook($event, [
            'company' => ['id' => 70],
            'person' => ['id' => 9001, 'first_name' => 'Pat', 'last_name' => 'Medic'],
        ])->assertOk()->assertExactJson(['status' => 'ok', 'action' => 'ignored']);

        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('qualifications')->count());
    }

    /**
     * @return array<string, array{string}>
     */
    public static function personnelEvents(): array
    {
        return [
            'person.updated' => ['person.updated'],
            'person.deactivated' => ['person.deactivated'],
        ];
    }

    #[DataProvider('personnelEvents')]
    public function test_a_personnel_event_is_acknowledged_while_the_mirror_is_off(string $event): void
    {
        $this->company(70);

        $this->postWebhook($event, [
            'company' => ['id' => 70],
            'user' => ['id' => 9001],
            'personnel' => ['user' => ['id' => 9001, 'first_name' => 'Pat', 'last_name' => 'Medic']],
        ])->assertOk()->assertJson(['status' => 'ignored', 'action' => $event, 'reason' => 'master_data.personnel disabled']);

        $this->assertSame(0, DB::table('users')->count());
    }

    public function test_an_unhandled_event_with_a_bad_signature_is_still_rejected(): void
    {
        $this->call('POST', '/api/sso/provision', [], [], [], [
            'HTTP_X-SSO-Signature' => 'bogus',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['event' => 'person.updated']))->assertForbidden();
    }
}
