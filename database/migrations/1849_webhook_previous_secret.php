<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The secret a webhook's rotation replaced, and until when deliveries are
 * signed with it as well as the new one (Webhook::rotateSecret), so a
 * receiver still holding it keeps verifying while it deploys the new one.
 * Encrypted, as the secret is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->text('previous_secret')->nullable()->after('secret');
            $table->timestamp('previous_secret_expires_at')->nullable()->after('previous_secret');
        });
    }

    public function down(): void
    {
        Schema::table('webhooks', function (Blueprint $table) {
            $table->dropColumn(['previous_secret', 'previous_secret_expires_at']);
        });
    }
};
