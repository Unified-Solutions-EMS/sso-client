<?php

namespace Unified\SsoClient\Tests\Feature;

use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Unified\SsoClient\Tests\Feature\MasterData\MasterDataTestCase;

/**
 * SSO is renaming personnel.* to person.* and the package has no personnel
 * mirror yet. Events it does not handle must be acknowledged with a 200 so
 * SSO records them delivered instead of retrying them forever.
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
            'person.updated' => ['person.updated'],
            'person.deactivated' => ['person.deactivated'],
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

    public function test_an_unhandled_event_with_a_bad_signature_is_still_rejected(): void
    {
        $this->call('POST', '/api/sso/provision', [], [], [], [
            'HTTP_X-SSO-Signature' => 'bogus',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ], json_encode(['event' => 'person.updated']))->assertForbidden();
    }
}
