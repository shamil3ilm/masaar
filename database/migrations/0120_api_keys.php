<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id');
            $table->uuid('org_id');
            $table->string('name', 255);
            $table->string('key_prefix', 20);
            $table->string('key_hash', 64);
            $table->json('scopes');
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['expires_at'], 'api_keys_expires_idx');
            $table->index(['key_hash'], 'api_keys_key_hash_index');
            $table->unique(['key_hash'], 'api_keys_key_hash_unique');
            $table->unique(['key_prefix'], 'api_keys_key_prefix_unique');
            $table->index(['org_id', 'is_active'], 'api_keys_organization_id_is_active_index');
            $table->foreign('org_id', 'api_keys_organization_id_foreign')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
