<?php

namespace Unified\SsoClient\Tests\Unit;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Unified\SsoClient\MasterData\ImportBatcher;
use Unified\SsoClient\MasterData\MasterDataClient;
use Unified\SsoClient\Tests\TestCase;

class ImportBatcherTest extends TestCase
{
    public function test_counts_sum_lists_concatenate_and_mappings_merge(): void
    {
        config(['sso.base_url' => 'https://sso.test', 'app.core_api_key' => 'key']);
        Http::fake(fn (Request $request) => Http::response([
            'created' => count($request['vehicles']),
            'invalid' => [['local_id' => $request['vehicles'][0]['local_id']]],
            'review_id' => 7,
            'mapping' => collect($request['vehicles'])->mapWithKeys(fn (array $v): array => [$v['local_id'] => ['sso_id' => $v['local_id'] + 1000]])->all(),
        ]));

        $rows = array_map(fn (int $i): array => ['local_id' => $i, 'name' => "V{$i}"], range(1, 25));
        $response = (new ImportBatcher(app(MasterDataClient::class)))->import('vehicles', 5, ['app_slug' => 'x', 'vehicles' => $rows], 10);

        Http::assertSentCount(3);
        $this->assertSame(25, $response['created']);
        $this->assertCount(3, $response['invalid']);
        $this->assertSame(7, $response['review_id']);
        $this->assertCount(25, $response['mapping']);
        $this->assertSame(1025, $response['mapping'][25]['sso_id']);
    }

    public function test_assignments_follow_their_rows_into_the_right_request(): void
    {
        config(['sso.base_url' => 'https://sso.test', 'app.core_api_key' => 'key']);
        Http::fake(Http::response(['mapping' => []]));

        $rows = array_map(fn (int $i): array => ['local_id' => $i, 'name' => "Q{$i}"], range(1, 4));
        $assignments = [['user_sso_id' => '9', 'local_qualification_ids' => [1, 2, 3]]];
        (new ImportBatcher(app(MasterDataClient::class)))->import('qualifications', 5, ['qualifications' => $rows, 'assignments' => $assignments], 2);

        $seen = [];
        Http::assertSent(function (Request $request) use (&$seen): bool {
            $seen[] = array_column($request['assignments'], 'local_qualification_ids');

            return true;
        });
        $this->assertSame([[[1, 2]], [[3]]], $seen);
    }

    public function test_under_the_cap_is_one_untouched_request(): void
    {
        config(['sso.base_url' => 'https://sso.test', 'app.core_api_key' => 'key']);
        Http::fake(Http::response(['mapping' => []]));

        (new ImportBatcher(app(MasterDataClient::class)))->import('vehicles', 5, ['vehicles' => [['local_id' => 1]]], 1000);

        Http::assertSentCount(1);
    }
}
