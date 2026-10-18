<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every customization's value at every level that sets one (Customizations):
 * a row per key per holder - the platform, an organization, a branch, a
 * person in an organization or a person anywhere. No row is "as the level
 * above", so resetting a value deletes its row.
 *
 * The value is text holding JSON rather than a JSON column, because MySQL
 * reorders the keys of a JSON object and a list of entries keeps its order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customizations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 100);
            $table->string('level', 20);
            $table->string('holder', 100);
            $table->uuid('org_id')->nullable();
            $table->uuid('branch_id')->nullable();
            $table->uuid('user_id')->nullable();
            $table->longText('value');
            $table->boolean('locked')->default(false);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['key', 'holder']);
            $table->index('holder');
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customizations');
    }
};
