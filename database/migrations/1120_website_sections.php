<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The home page's sections as Admin › Website has set them: whether each is
 * shown, where it sits, and its text in English and Arabic.
 *
 * An entity (class a), the platform's own. A section with no row, or with
 * no content in its row, shows its shipped default (SectionCatalogue), so
 * the page is the same out of the box and "Reset to default" empties
 * content rather than removing anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // A SectionCatalogue key: hero, countries, faq ...
            $table->string('key', 32)->unique();
            $table->boolean('enabled')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            // Every text field in both languages, or null for the default.
            $table->json('content')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            foreach (['created_by', 'updated_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_sections');
    }
};
