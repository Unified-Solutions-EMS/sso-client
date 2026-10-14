<?php

namespace Unified\SsoClient\Tests\Feature\MasterData\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Unified\SsoClient\MasterData\Support\ChildRowPolicy;
use Unified\SsoClient\MasterData\Support\ChildRows;
use Unified\SsoClient\Tests\TestCase;

/**
 * Positional child rows (a facility's phones). A pure copy of SSO's list
 * deletes the extras; a table carrying the app's own data deactivates them
 * and never reuses an inactive row for another child.
 */
class ChildRowsTest extends TestCase
{
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::create('facility_phones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('facility_id');
            $table->string('number');
            $table->boolean('is_active')->default(true);
            $table->string('verified_by')->nullable();
            $table->timestamp('sso_removed_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * @return list<array{string, bool}>
     */
    private function phones(): array
    {
        return DB::table('facility_phones')->where('facility_id', 1)->orderBy('id')->get()
            ->map(fn (object $row): array => [$row->number, (bool) $row->is_active])->all();
    }

    public function test_extras_are_deleted_by_default(): void
    {
        ChildRows::sync('facility_phones', 'facility_id', 1, [['number' => '111'], ['number' => '222']]);
        $this->assertTrue(ChildRows::sync('facility_phones', 'facility_id', 1, [['number' => '111']]));

        $this->assertSame([['111', true]], $this->phones());
    }

    public function test_deactivate_keeps_the_extra_row_and_never_reuses_it(): void
    {
        $policy = ChildRowPolicy::deactivate('is_active');
        ChildRows::sync('facility_phones', 'facility_id', 1, [['number' => '111'], ['number' => '222']], onRemoved: $policy);
        DB::table('facility_phones')->where('number', '222')->update(['verified_by' => 'dispatch']);

        $this->assertTrue(ChildRows::sync('facility_phones', 'facility_id', 1, [['number' => '111']], onRemoved: $policy));
        $removed = DB::table('facility_phones')->where('number', '222')->first();
        $this->assertSame(['dispatch', false], [$removed->verified_by, (bool) $removed->is_active]);
        $this->assertNotNull($removed->sso_removed_at);

        $this->assertFalse(ChildRows::sync('facility_phones', 'facility_id', 1, [['number' => '111']], onRemoved: $policy), 'a repeat writes nothing');

        ChildRows::sync('facility_phones', 'facility_id', 1, [['number' => '111'], ['number' => '333']], onRemoved: $policy);
        $this->assertSame([['111', true], ['222', false], ['333', true]], $this->phones());
    }

    public function test_unlink_is_refused_for_positional_rows(): void
    {
        $this->expectException(LogicException::class);

        ChildRows::sync('facility_phones', 'facility_id', 1, [], onRemoved: ChildRowPolicy::unlink());
    }
}
