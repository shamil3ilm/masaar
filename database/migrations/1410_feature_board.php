<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The feature board: what customers have asked for, as an admin publishes
 * it - reworded, in both languages, with no company's name or details - for
 * every customer to read, back ("we need this too") and follow.
 *
 * feature_board_items  one idea, however many companies asked for it. An
 *                      entity (class a), the platform's own: an admin words
 *                      it, publishes it, moves its stage and links the
 *                      release that delivered it. A duplicate merged into
 *                      another names it, and is no longer shown.
 * feature_board_backers   a company that needs it too: one row each,
 *                         withdrawn and given again (class b).
 * feature_board_followers a person told when it moves on (class b).
 * feature_board_updates   each move and public note, as published (class d).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_board_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // What a customer quotes: F-0042.
            $table->unsignedBigInteger('number')->unique();
            $table->string('title', 160);
            $table->string('title_ar', 160)->nullable();
            $table->text('summary');
            $table->text('summary_ar')->nullable();
            $table->string('category', 40);
            $table->string('stage', 20);
            // Null while it is a draft only admins see.
            $table->timestamp('published_at')->nullable();
            // The release that delivered it: its name, and where it is described.
            $table->string('released_in', 80)->nullable();
            $table->string('release_url', 255)->nullable();
            $table->uuid('merged_into_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['published_at', 'stage'], 'feature_board_items_published_index');

            foreach (['created_by', 'updated_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });

        Schema::table('feature_board_items', function (Blueprint $table) {
            $table->foreign('merged_into_id')->references('id')->on('feature_board_items')->nullOnDelete();
        });

        foreach (['feature_board_backers' => 'org', 'feature_board_followers' => 'user'] as $name => $who) {
            Schema::create($name, function (Blueprint $table) use ($name, $who) {
                $table->uuid('id')->primary();
                $table->uuid('board_item_id');
                $table->uuid('org_id');
                if ($who === 'user') {
                    $table->uuid('user_id');
                }
                $table->uuid('created_by')->nullable();
                $table->uuid('updated_by')->nullable();
                $table->uuid('deleted_by')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->foreign('board_item_id')->references('id')->on('feature_board_items')->cascadeOnDelete();
                $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();

                if ($who === 'user') {
                    $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
                }

                foreach (['created_by', 'updated_by', 'deleted_by'] as $actor) {
                    $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
                }

                // Backing or following again brings the removed row back.
                $table->unique(['board_item_id', $who === 'user' ? 'user_id' : 'org_id'], $name.'_unique');
            });
        }

        Schema::create('feature_board_updates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('board_item_id');
            // The stage it moved to, when it moved.
            $table->string('stage', 20)->nullable();
            $table->text('note')->nullable();
            $table->text('note_ar')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['board_item_id', 'created_at']);
            $table->foreign('board_item_id')->references('id')->on('feature_board_items')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_board_updates');
        Schema::dropIfExists('feature_board_followers');
        Schema::dropIfExists('feature_board_backers');
        Schema::dropIfExists('feature_board_items');
    }
};
