<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('organization_id');
            $table->string('url', 500);
            $table->string('secret', 128);
            $table->json('events');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();

            $table->foreign('organization_id')
                ->references('id')
                ->on('organizations')
                ->onDelete('cascade');

            $table->index(['organization_id', 'is_active']);
        });

        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('webhook_id');
            $table->string('event', 100);
            $table->json('payload');
            $table->unsignedSmallInteger('response_status')->default(0);
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('success')->default(false);
            $table->timestamps();

            $table->foreign('webhook_id')
                ->references('id')
                ->on('webhooks')
                ->onDelete('cascade');

            $table->index(['webhook_id', 'created_at']);
            $table->index(['webhook_id', 'success']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
        Schema::dropIfExists('webhooks');
    }
};
