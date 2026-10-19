<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The partner API's recent requests, per organization, for its
 * administrators to read in Portal › Integrations: method, path, answer,
 * which key, when, the request id and the error code.
 *
 * No bodies, no headers, no query strings, no secrets and no addresses -
 * only the audit and evidence logs keep an address. usage_events stays the
 * billing record and is never pruned; this is kept for as many days as the
 * customization integrations.request_log_days says
 * (integrations:prune-request-log).
 *
 * An append-only log (class d), timed by occurred_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->uuid('license_id');
            // The organization's own key, when it was one; null for the licence's key.
            $table->uuid('api_key_id')->nullable();
            $table->string('method', 10);
            $table->string('path', 500);
            $table->unsignedSmallInteger('status');
            $table->string('error_code', 64)->nullable();
            $table->string('request_id', 100)->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->timestamp('occurred_at');

            $table->index(['org_id', 'occurred_at']);
            $table->index('request_id');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('license_id')->references('id')->on('licenses')->cascadeOnDelete();
            $table->foreign('api_key_id')->references('id')->on('api_keys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_request_logs');
    }
};
