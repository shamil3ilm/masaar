<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a customer said about the product, for the home page's testimonials.
 *
 * A removable entity (class b), the platform's own. Written by an admin in
 * both languages; shown only while published, and the section itself only
 * when Admin › Website turns it on (off by default). The logo is a file on
 * the website disk, served by the website's own route.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('website_testimonials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->text('quote_en');
            $table->text('quote_ar');
            $table->string('name_en', 120);
            $table->string('name_ar', 120);
            $table->string('role_en', 120)->nullable();
            $table->string('role_ar', 120)->nullable();
            $table->string('company_en', 160)->nullable();
            $table->string('company_ar', 160)->nullable();
            // On the website disk (WebsiteImages); null for none.
            $table->string('logo_path')->nullable();
            $table->string('logo_type', 32)->nullable();
            $table->boolean('published')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['published', 'position']);

            foreach (['created_by', 'updated_by', 'deleted_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('website_testimonials');
    }
};
