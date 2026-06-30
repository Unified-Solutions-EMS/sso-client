<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local mirror of each user's device-lock bypass per company, synced from the
 * SSO /api/user payload. `app_slugs` holds ['*'] for a company-wide bypass or
 * the specific app slugs the user is excused from. The device-lock middleware
 * reads this to let bypassed users in without an authorized device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_device_bypasses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('company_id');
            $table->json('app_slugs');
            $table->timestamps();

            $table->unique(['user_id', 'company_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_device_bypasses');
    }
};
