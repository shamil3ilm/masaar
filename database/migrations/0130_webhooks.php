<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id');
            $table->string('url', 500);
            $table->string('secret', 128);
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['org_id', 'is_active'], 'webhooks_organization_id_is_active_index');
            $table->foreign('org_id', 'webhooks_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('webhook_id');
            $table->string('event', 100);
            $table->json('payload');
            $table->unsignedSmallInteger('response_status')->default(0);
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('success')->default(false);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['webhook_id', 'created_at'], 'webhook_logs_webhook_id_created_at_index');
            $table->index(['webhook_id', 'success'], 'webhook_logs_webhook_id_success_index');
            $table->foreign('webhook_id', 'webhook_logs_webhook_id_foreign')->references('id')->on('webhooks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('webhooks');
    }
};
