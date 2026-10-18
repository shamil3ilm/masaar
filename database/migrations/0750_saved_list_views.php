<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A set of filters someone named on a list, to come back to.
 *
 * Per person, per place they work and per list. `scope` is the
 * organization's id in the portal and 'admin' in the console: a nullable
 * org_id cannot carry the unique index, because two NULLs are not equal to
 * one. org_id is kept beside it so an organization's views go with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_list_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('org_id')->nullable();
            $table->string('scope', 36);
            // The list's route name.
            $table->string('list', 100);
            $table->string('name', 60);
            // The query string that asks for it, as the list understood it.
            $table->text('query');
            $table->timestamps();

            $table->unique(['user_id', 'scope', 'list', 'name'], 'saved_list_views_name_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_list_views');
    }
};
