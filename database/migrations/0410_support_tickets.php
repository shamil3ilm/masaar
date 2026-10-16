<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Support tickets: a customer asking for help, and the conversation after.
 *
 * Help used to mean an email to whoever the customer knew, with nothing
 * kept beside the company it was about. The number is what a customer quotes
 * on the phone; the id is what links use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->unsignedBigInteger('number')->unique();
            $table->uuid('org_id');
            $table->uuid('opened_by')->nullable();
            $table->string('subject', 160);
            $table->string('category', 20);
            $table->string('priority', 10);
            $table->string('status', 20);
            $table->uuid('assigned_to')->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_activity_at']);
            $table->index(['org_id', 'last_activity_at']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('support_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('ticket_id');
            $table->uuid('user_id')->nullable();
            $table->boolean('from_staff')->default(false);
            // A note between admins, never shown to the customer.
            $table->boolean('internal')->default(false);
            $table->text('body');
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
            $table->foreign('ticket_id')->references('id')->on('support_tickets')->cascadeOnDelete();
        });

        Schema::create('support_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('message_id');
            $table->string('path');
            $table->string('name');
            $table->string('mime', 100);
            $table->unsignedInteger('size');
            $table->timestamps();

            $table->foreign('message_id')->references('id')->on('support_messages')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
    }
};
