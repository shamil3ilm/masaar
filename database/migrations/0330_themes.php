<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the application looks: colours, name, logo and favicon.
 *
 * One row with no organization is the platform's own; a row for an
 * organization overrides it for that organization's portal and documents.
 * The definition is the theme JSON as edited, validated before it is stored,
 * and version moves on every save so a cached stylesheet is replaced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('themes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->nullable()->unique();
            $table->json('definition');
            $table->string('logo_path')->nullable();
            $table->string('logo_type', 50)->nullable();
            $table->string('favicon_path')->nullable();
            $table->string('favicon_type', 50)->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('themes');
    }
};
