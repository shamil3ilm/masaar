<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Token blacklist for invalidated JWTs
        Schema::create('token_blacklist', function (Blueprint $table) {
            $table->id();
            $table->string('jti', 64)->unique(); // JWT ID
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('reason', 50); // logout, password_change, revoked, user_deleted
            $table->timestamp('expires_at'); // When token would naturally expire (for cleanup)
            $table->timestamp('created_at')->useCurrent();

            $table->index('expires_at');
        });

        // Login attempts for brute force protection
        Schema::create('login_attempts', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->string('ip_address', 45);
            $table->boolean('successful')->default(false);
            $table->timestamp('attempted_at')->useCurrent();

            $table->index(['email', 'attempted_at']);
            $table->index(['ip_address', 'attempted_at']);
        });

        // Password change history (for token invalidation)
        Schema::create('password_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('changed_at')->useCurrent();
            $table->string('ip_address', 45)->nullable();

            $table->index(['user_id', 'changed_at']);
        });

        // Idempotency keys for double-submit prevention
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('endpoint');
            $table->text('response')->nullable();
            $table->smallInteger('status_code');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('expires_at');

            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('password_changes');
        Schema::dropIfExists('login_attempts');
        Schema::dropIfExists('token_blacklist');
    }
};
