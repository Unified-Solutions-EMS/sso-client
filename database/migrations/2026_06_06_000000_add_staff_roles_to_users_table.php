<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'staff_roles')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->json('staff_roles')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'staff_roles')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('staff_roles');
        });
    }
};
