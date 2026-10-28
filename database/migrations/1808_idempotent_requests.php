<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An API request made once under an Idempotency-Key, and its answer, for
 * the routes whose records keep no key of their own (the idempotent
 * middleware). One key per organization: the unique index is what lets
 * one of two simultaneous requests through.
 *
 * An entity the platform writes (class a): the answer is filled in when the
 * request finishes. Pruned after IdempotentRequest::RETENTION_DAYS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotent_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('idempotency_key', 255);
            $table->char('request_hash', 64);
            $table->unsignedSmallInteger('status')->nullable();
            // Encrypted: a webhook's secret can be in it.
            $table->longText('response')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'idempotency_key']);
            $table->index('created_at');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotent_requests');
    }
};
