<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A message someone sent from the home page's contact form, and where the
 * console's Website inbox has got with it.
 *
 * A removable entity (class b): the platform's own, so no organization owns
 * it. The sender is not a user - most have no account - so who wrote it is
 * the name and email they gave, not an actor column; the actors are the
 * admins who read, answered, archived or removed it.
 *
 * The network address and browser are kept for spam review only and cleared
 * after config('website.contact.keep_details_days') (PruneContactDetails).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('email');
            $table->string('company')->nullable();
            $table->string('country', 100)->nullable();
            // A ContactTopic value.
            $table->string('topic', 32);
            $table->text('message');
            // The language the page was in, so a reply can be in it too.
            $table->string('locale', 8);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            // new, replied or archived (ContactStatus).
            $table->string('status', 16)->default('new');
            $table->timestamp('read_at')->nullable();
            $table->uuid('read_by')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->uuid('replied_by')->nullable();
            // Who last changed and removed it (RecordsActors). created_by is
            // kept for the class; a public sender leaves it empty.
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'created_at']);
            $table->index('topic');
            $table->index('email');

            foreach (['read_by', 'replied_by', 'created_by', 'updated_by', 'deleted_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
    }
};
