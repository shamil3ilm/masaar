<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zatca_rule_codes', function (Blueprint $table) {
            $table->uuid('id');
            $table->string('code', 64);
            $table->timestamp('first_seen_at');
            $table->boolean('needs_review')->default(true);
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->string('category', 255)->nullable();
            $table->string('severity', 255)->nullable();
            $table->text('description')->nullable();
            $table->string('first_env', 255)->nullable();
            $table->uuid('first_org_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->unique(['code'], 'zatca_rule_codes_code_unique');
            $table->index(['first_org_id'], 'zatca_rule_codes_first_org_id_foreign');
            $table->index(['needs_review', 'first_seen_at'], 'zatca_rule_codes_needs_review_first_seen_at_index');
            $table->foreign('first_org_id', 'zatca_rule_codes_first_org_id_foreign')->references('id')->on('organizations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zatca_rule_codes');
    }
};
