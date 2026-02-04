<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Polymorphic relationship
            $table->string('auditable_type');
            $table->unsignedBigInteger('auditable_id');

            // Event type
            $table->string('event', 20); // created, updated, deleted, restored

            // Changes
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            // Request context
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->text('url')->nullable();

            // Timestamp (no updated_at needed for audit logs)
            $table->timestamp('created_at')->useCurrent();

            // Indexes for querying
            $table->index(['auditable_type', 'auditable_id']);
            $table->index('organization_id');
            $table->index('user_id');
            $table->index('event');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
