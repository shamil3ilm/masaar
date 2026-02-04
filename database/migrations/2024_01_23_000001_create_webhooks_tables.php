<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Webhook endpoints configuration
        Schema::create('webhooks', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->string('secret', 64)->nullable(); // For HMAC signature
            $table->json('events'); // Array of event types to subscribe to
            $table->json('headers')->nullable(); // Custom headers to include
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('retry_count')->default(3);
            $table->unsignedInteger('timeout_seconds')->default(30);
            $table->string('content_type', 50)->default('application/json');
            $table->timestamp('last_triggered_at')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamp('last_failure_at')->nullable();
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        // Webhook delivery logs
        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('webhook_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 100);
            $table->json('payload');
            $table->string('status', 20)->default('pending'); // pending, success, failed
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->text('response_body')->nullable();
            $table->json('response_headers')->nullable();
            $table->unsignedInteger('duration_ms')->nullable(); // Response time
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->timestamp('next_retry_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['webhook_id', 'status']);
            $table->index(['webhook_id', 'event_type']);
            $table->index('created_at');
            $table->index('next_retry_at');
        });

        // Event log for all webhook-triggering events
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('event_type', 100);
            $table->string('resource_type', 100); // invoice, payment, customer, etc.
            $table->string('resource_id', 100)->nullable();
            $table->json('data'); // Event payload
            $table->unsignedInteger('webhooks_triggered')->default(0);
            $table->timestamp('created_at');

            $table->index(['organization_id', 'event_type']);
            $table->index(['organization_id', 'resource_type', 'resource_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('webhooks');
    }
};
