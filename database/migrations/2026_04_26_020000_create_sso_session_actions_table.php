<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pending session actions for cross-app SSO state propagation.
 *
 * When SSO fires a webhook that affects a user's session state in this
 * app (logout, impersonation start/end), the webhook handler writes a
 * row here. The next request that user makes triggers the
 * EnforceSsoSessionActions middleware which drains the rows and applies
 * the side effect (logout or rebuild session with the right company).
 *
 * Sessions are per-browser, so we can't update them directly from a
 * webhook — but we can mark them dirty for the next request to fix up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_session_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('action', 32);
            $table->json('payload')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'action']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_session_actions');
    }
};
