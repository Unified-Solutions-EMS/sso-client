<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Unified\SsoClient\MasterData\MasterDataRegistry;
use Unified\SsoClient\MasterData\Rotations\RotationChildTable;
use Unified\SsoClient\MasterData\Rotations\RotationMirror;
use Unified\SsoClient\MasterData\Rotations\StandardRotationProjection;
use Unified\SsoClient\MasterData\Support\MirrorLinkColumns;

/**
 * Creates the rotations read model: `sso_rotations` (or the link columns
 * on the table the app's bound RotationProjection names), and the package's
 * `sso_rotation_blocks` + `sso_rotation_assignments` under it. Published by
 * `vendor:publish --tag=sso-master-data-rotations`.
 *
 * Blocks keep SSO's wall-clock start (HH:MM text, never a time zone) and
 * length in minutes; assignments keep SSO's unit or person id, the cycle
 * day the assignee is on at day 1 and the date range. Nothing here
 * deletes or rewrites a row the app already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mirror = $this->mirror();
        $table = $mirror->table();

        if (! Schema::hasTable($table) && $table === StandardRotationProjection::TABLE) {
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->id();
                $blueprint->foreignId('company_id')->constrained()->cascadeOnDelete();
                $blueprint->string('name', 100);
                $blueprint->text('description')->nullable();
                $blueprint->unsignedSmallInteger('cycle_days')->default(1);
                $blueprint->date('anchor_date')->nullable();
                $blueprint->string('pattern')->nullable();
                $blueprint->string('source_app', 50)->nullable();
                $blueprint->timestamps();
            });
        }

        MirrorLinkColumns::add($table, RotationMirror::SSO_ID_COLUMN, $mirror->activeColumn());

        if (! Schema::hasTable(RotationChildTable::BLOCKS)) {
            Schema::create(RotationChildTable::BLOCKS, function (Blueprint $blueprint) use ($table): void {
                $blueprint->id();
                $blueprint->foreignId(RotationChildTable::PARENT_COLUMN)->constrained($table)->cascadeOnDelete();
                $blueprint->unsignedBigInteger('sso_rotation_block_id')->nullable();
                $blueprint->string('uuid', 36)->nullable();
                $blueprint->unsignedSmallInteger('day_index');
                $blueprint->string('start_time', 5);
                $blueprint->unsignedSmallInteger('duration_minutes');
                $blueprint->string('label')->nullable();
                $blueprint->unsignedInteger('sort_order')->default(0);
                $blueprint->timestamp('sso_removed_at')->nullable();
                $blueprint->timestamps();
                $blueprint->unique([RotationChildTable::PARENT_COLUMN, 'sso_rotation_block_id'], 'sso_rotation_blocks_parent_sso_unique');
            });
        }

        if (! Schema::hasTable(RotationChildTable::ASSIGNMENTS)) {
            Schema::create(RotationChildTable::ASSIGNMENTS, function (Blueprint $blueprint) use ($table): void {
                $blueprint->id();
                $blueprint->foreignId(RotationChildTable::PARENT_COLUMN)->constrained($table)->cascadeOnDelete();
                $blueprint->unsignedBigInteger('sso_rotation_assignment_id')->nullable();
                $blueprint->string('uuid', 36)->nullable();
                $blueprint->string('assignable_type', 10);
                $blueprint->unsignedBigInteger('sso_unit_id')->nullable()->index();
                $blueprint->unsignedBigInteger('sso_user_id')->nullable()->index();
                $blueprint->string('name')->nullable();
                $blueprint->unsignedSmallInteger('start_day_index')->default(0);
                $blueprint->date('starts_on')->nullable();
                $blueprint->date('ends_on')->nullable();
                $blueprint->boolean('is_active')->default(true);
                $blueprint->timestamp('sso_removed_at')->nullable();
                $blueprint->timestamps();
                $blueprint->unique([RotationChildTable::PARENT_COLUMN, 'sso_rotation_assignment_id'], 'sso_rotation_assignments_parent_sso_unique');
            });
        }
    }

    /**
     * Drops the two child tables (pure mirrors, refilled by a resync) and
     * the link columns; a rotation table the app owns keeps its rows.
     */
    public function down(): void
    {
        Schema::dropIfExists(RotationChildTable::ASSIGNMENTS);
        Schema::dropIfExists(RotationChildTable::BLOCKS);

        MirrorLinkColumns::drop($this->mirror()->table(), RotationMirror::SSO_ID_COLUMN);
    }

    private function mirror(): RotationMirror
    {
        $mirror = app(MasterDataRegistry::class)->mirror('rotations');

        assert($mirror instanceof RotationMirror);

        return $mirror;
    }
};
