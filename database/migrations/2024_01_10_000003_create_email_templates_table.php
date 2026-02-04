<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Email templates
        Schema::create('email_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 50)->unique(); // invoice_created, payment_reminder, etc.
            $table->string('name', 100);
            $table->string('subject', 255);
            $table->text('body_html');
            $table->text('body_text')->nullable();
            $table->string('from_name', 100)->nullable();
            $table->string('reply_to', 100)->nullable();
            $table->string('cc', 255)->nullable();
            $table->string('bcc', 255)->nullable();
            $table->json('variables')->nullable(); // Available placeholder variables
            $table->string('language', 5)->default('en');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_system')->default(false); // System templates cannot be deleted
            $table->timestamps();

            $table->unique(['organization_id', 'code', 'language']);
        });

        // Email logs
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_code', 50)->nullable();
            $table->string('emailable_type', 100)->nullable(); // Invoice, Quotation, etc.
            $table->unsignedBigInteger('emailable_id')->nullable();
            $table->string('to_email', 255);
            $table->string('to_name', 255)->nullable();
            $table->string('subject', 255);
            $table->text('body_preview')->nullable(); // First 500 chars
            $table->json('attachments')->nullable();
            $table->string('status', 20)->default('pending'); // pending, sent, failed, bounced
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('clicked_at')->nullable();
            $table->timestamp('bounced_at')->nullable();
            $table->string('message_id', 255)->nullable(); // External email provider message ID
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['emailable_type', 'emailable_id']);
        });

        // Scheduled emails (for payment reminders, etc.)
        Schema::create('scheduled_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('template_code', 50);
            $table->string('emailable_type', 100);
            $table->unsignedBigInteger('emailable_id');
            $table->string('to_email', 255);
            $table->string('to_name', 255)->nullable();
            $table->datetime('scheduled_at');
            $table->string('status', 20)->default('pending'); // pending, sent, cancelled
            $table->unsignedBigInteger('email_log_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_emails');
        Schema::dropIfExists('email_logs');
        Schema::dropIfExists('email_templates');
    }
};
