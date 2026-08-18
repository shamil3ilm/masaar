<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->string('action', 255);
            $table->string('entity_type', 255);
            $table->uuid('entity_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['action'], 'audit_logs_action_index');
            $table->index(['entity_type', 'entity_id'], 'audit_logs_entity_type_entity_id_index');
            $table->index(['org_id', 'created_at'], 'audit_logs_organization_id_created_at_index');
            $table->index(['user_id'], 'audit_logs_user_id_foreign');
            $table->foreign('org_id', 'audit_logs_organization_id_foreign')->references('id')->on('organizations')->nullOnDelete();
            $table->foreign('user_id', 'audit_logs_user_id_foreign')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
