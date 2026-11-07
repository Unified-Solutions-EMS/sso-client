<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\CodeLists;

use Illuminate\Support\Facades\DB;
use Unified\SsoClient\MasterData\MasterDataRegistry;

/**
 * A resync or a batch reads the company's mirror rows once, so the lookups
 * do not grow with the number of rows (Sentry CLOUDPCR-7P / -7Z), and
 * re-running the same records changes nothing.
 */
class CodeListBulkQueryCountTest extends CodeListsTestCase
{
    protected string $shape = 'plain';

    private int $companyId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = $this->company(70);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function procedures(int $count): array
    {
        return array_map(
            fn (int $i): array => $this->procedureRecord(1000 + $i, (string) (268400000 + $i), "Procedure {$i}", [self::EMT]),
            range(1, $count),
        );
    }

    private function mirrorSelects(callable $run): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $run();
        $selects = collect(DB::getQueryLog())
            ->filter(fn (array $query): bool => str_starts_with(strtolower($query['query']), 'select') && str_contains($query['query'], 'sso_procedures'))
            ->count();
        DB::disableQueryLog();

        return $selects;
    }

    private function resync(array $records): array
    {
        return app(MasterDataRegistry::class)->mirror('procedures')->resync($this->companyId, ['procedures' => $records]);
    }

    public function test_a_resync_reads_the_mirror_once_whatever_its_size(): void
    {
        $small = $this->mirrorSelects(fn () => $this->resync($this->procedures(3)));
        DB::table('sso_procedures')->delete();
        $large = $this->mirrorSelects(fn () => $this->resync($this->procedures(30)));

        $this->assertSame($small, $large);
        $this->assertSame(30, DB::table('sso_procedures')->count());
    }

    public function test_a_batch_reads_the_mirror_once_whatever_its_size(): void
    {
        $deliver = fn (array $records) => $this->postWebhook('procedure.batch_updated', [
            'company' => ['id' => 70],
            'procedures' => $records,
            'updated_at' => '2026-10-11T12:00:00+00:00',
        ])->assertOk();

        $small = $this->mirrorSelects(fn () => $deliver($this->procedures(3)));
        DB::table('sso_procedures')->delete();
        $large = $this->mirrorSelects(fn () => $deliver($this->procedures(30)));

        $this->assertSame($small, $large);
        $this->assertSame(30, DB::table('sso_procedures')->count());
    }

    public function test_rerunning_the_same_resync_changes_nothing(): void
    {
        $records = $this->procedures(5);

        $this->assertSame(5, $this->resync($records)['created']);

        $again = $this->resync($records);

        $this->assertSame(0, $again['created']);
        $this->assertSame(5, $again['unchanged']);
        $this->assertSame(5, DB::table('sso_procedures')->where('company_id', $this->companyId)->count());
    }

    public function test_an_unlinked_row_is_adopted_by_code_and_an_existing_link_is_updated(): void
    {
        $this->resync([$this->procedureRecord(1001, '268400001', 'Old name', [self::EMT])]);
        DB::table('sso_procedures')->insert([
            'company_id' => $this->companyId,
            'name' => 'Local copy',
            'code' => '268400002',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $counts = $this->resync([
            $this->procedureRecord(1001, '268400001', 'New name', [self::EMT], ['updated_at' => '2026-10-12T12:00:00+00:00']),
            $this->procedureRecord(1002, '268400002', 'From SSO', [self::EMT]),
        ]);

        $this->assertSame(1, $counts['updated']);
        $this->assertSame(1, $counts['linked']);
        $this->assertSame(0, $counts['created']);
        $this->assertSame('New name', DB::table('sso_procedures')->where('sso_procedure_id', 1001)->value('name'));
        $this->assertSame(2, DB::table('sso_procedures')->count());
    }

    public function test_a_record_repeated_in_one_batch_is_stored_once(): void
    {
        $record = $this->procedureRecord(1001, '268400001', 'ECG', [self::EMT]);

        $this->postWebhook('procedure.batch_updated', [
            'company' => ['id' => 70],
            'procedures' => [$record, $record],
            'updated_at' => '2026-10-11T12:00:00+00:00',
        ])->assertOk()->assertJson(['counts' => ['created' => 1, 'unchanged' => 1]]);

        $this->assertSame(1, DB::table('sso_procedures')->count());
    }

    public function test_another_companys_rows_are_never_matched(): void
    {
        $other = $this->company(71, 'Other');
        DB::table('sso_procedures')->insert([
            'company_id' => $other,
            'sso_procedure_id' => 1001,
            'name' => 'Theirs',
            'code' => '268400001',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(1, $this->resync($this->procedures(1))['created']);
        $this->assertSame('Theirs', DB::table('sso_procedures')->where('company_id', $other)->value('name'));
        $this->assertSame(1, DB::table('sso_procedures')->where('company_id', $this->companyId)->count());
    }
}
