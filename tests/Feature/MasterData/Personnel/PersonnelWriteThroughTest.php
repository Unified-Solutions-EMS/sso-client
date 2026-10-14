<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Personnel;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Personnel\PersonnelMirror;

/**
 * The app's editor writes through to SSO and applies SSO's answer at once,
 * so the person sees the saved state without waiting for the webhook.
 */
class PersonnelWriteThroughTest extends PersonnelTestCase
{
    public function test_the_patch_goes_to_sso_and_its_answer_lands_locally(): void
    {
        $companyId = $this->company(70);
        $this->user(9001);
        $this->user(9100);
        $this->deliver($this->person(70, 9001))->assertOk();

        Http::fake(['*/companies/70/personnel/9001' => Http::response([
            'results' => ['hire_date' => ['ok' => true], 'date_of_birth' => ['ok' => false, 'message' => "The date of birth can't be in the future."]],
            'data' => $this->person(70, 9001, ['membership' => ['hire_date' => '2021-03-01'], 'updated_at' => '2026-10-13T12:20:00+00:00']),
        ])]);

        $mirror = app(MasterDataRegistry::class)->mirror('personnel');
        $this->assertInstanceOf(PersonnelMirror::class, $mirror);

        $answer = $mirror->writeThrough($companyId, 70, 9001, ['hire_date' => '2021-03-01', 'date_of_birth' => '2099-01-01'], 9100);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
            && $request['source'] === 'cloudpcr'
            && $request['actor_sso_user_id'] === 9100
            && $request['patch'] === ['hire_date' => '2021-03-01', 'date_of_birth' => '2099-01-01']);
        $this->assertSame('updated', $answer['result']);
        $this->assertFalse($answer['results']['date_of_birth']['ok']);
        $this->assertSame('2021-03-01', $this->demRow($companyId, 9001)->dpersonnel_33);
        $this->assertSame('1988-04-12', $this->demRow($companyId, 9001)->dpersonnel_11);
    }
}
