<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\Tests\Stubs\StubSynchronizer;

/**
 * `/api/user` `companies[].personnel_updated_at`: when SSO's copy is newer
 * than the mirror's (or the mirror has none), the login pulls that one
 * person after it commits. A current copy pulls nothing, and SSO failing
 * never fails the login.
 */
class PersonnelLoginPullTest extends PersonnelTestCase
{
    public function test_a_newer_hint_pulls_the_person_after_login(): void
    {
        $companyId = $this->company(70);
        $this->fakePerson($this->person(70, 9001, ['user' => ['last_name' => 'Reyes-Newer'], 'updated_at' => '2026-10-13T15:00:00+00:00']));

        $this->login('2026-10-13T15:00:00+00:00');

        $row = $this->demRow($companyId, 9001);
        $this->assertNotNull($row);
        $this->assertSame('Reyes-Newer', $row->dpersonnel_01);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://sso.test/api/internal/companies/70/personnel/9001');
    }

    public function test_a_current_copy_pulls_nothing(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        Http::fake();
        $this->deliver($this->person(70, 9001, ['updated_at' => '2026-10-13T15:00:00+00:00']))->assertOk();

        $this->login('2026-10-13T15:00:00+00:00');
        $this->login(null);

        Http::assertNothingSent();
        $this->assertSame('Reyes', $this->demRow($companyId, 9001)->dpersonnel_01);
    }

    public function test_a_disabled_entity_pulls_nothing(): void
    {
        config(['sso.master_data.personnel' => false]);
        $this->company(70);
        Http::fake();

        $this->login('2026-10-13T15:00:00+00:00');

        Http::assertNothingSent();
    }

    /**
     * The same hint is asked about at most once per five minutes, so an SSO
     * that keeps erroring is not called on every request.
     */
    public function test_sso_failing_never_fails_the_login(): void
    {
        $this->company(70);
        Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

        [$user] = $this->login('2026-10-13T15:00:00+00:00');
        $this->login('2026-10-13T15:00:00+00:00');

        $this->assertNotNull($user);
        $this->assertSame(0, DB::table('dem_personnel')->count());
        Http::assertSentCount(1);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function fakePerson(array $snapshot): void
    {
        Http::fake(['*/companies/70/personnel/9001' => Http::response(['data' => $snapshot])]);
    }

    /**
     * @return array{0: mixed, 1: mixed}
     */
    private function login(?string $hint): array
    {
        return (new StubSynchronizer)->synchronize([
            'user' => ['id' => 9001, 'email' => 'user9001@example.com', 'displayName' => 'Jordan Reyes', 'firstName' => 'Jordan', 'lastName' => 'Reyes'],
            'companies' => [array_filter(['id' => 70, 'name' => 'Agency 70', 'roles' => [], 'personnel_updated_at' => $hint], fn ($value): bool => $value !== null)],
            'selectedCompany' => ['id' => 70],
            'staffRoles' => [],
        ]);
    }
}
