<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // User notifications
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type', 100); // invoice.created, payment.received, stock.low, etc.
            $table->string('title');
            $table->text('message');
            $table->string('icon', 50)->nullable();
            $table->string('color', 20)->nullable();
            $table->string('action_url')->nullable(); // URL to navigate to
            $table->string('action_text', 50)->nullable(); // Button text
            $table->string('notifiable_type')->nullable(); // Related model class
            $table->unsignedBigInteger('notifiable_id')->nullable(); // Related model ID
            $table->json('data')->nullable(); // Additional data
            $table->string('channel', 20)->default('database'); // database, email, sms, push
            $table->timestamp('read_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at']);
            $table->index(['organization_id', 'type']);
            $table->index(['notifiable_type', 'notifiable_id']);
        });

        // Notification preferences per user
        Schema::create('notification_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('notification_type', 100);
            $table->boolean('email_enabled')->default(true);
            $table->boolean('database_enabled')->default(true);
            $table->boolean('push_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'notification_type']);
        });

        // Organization notification settings
        Schema::create('organization_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('notification_type', 100);
            $table->boolean('is_enabled')->default(true);
            $table->json('default_channels')->nullable(); // ['database', 'email']
            $table->json('settings')->nullable(); // Type-specific settings
            $table->timestamps();

            $table->unique(['organization_id', 'notification_type'], 'org_notif_type_unique');
        });

        // Notification templates for customizable messages
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('notification_type', 100);
            $table->string('channel', 20); // email, sms, database
            $table->string('language', 5)->default('en');
            $table->string('subject')->nullable(); // For email
            $table->text('body');
            $table->json('variables')->nullable(); // Available template variables
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'notification_type', 'channel', 'language'], 'notif_tpl_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('organization_notification_settings');
        Schema::dropIfExists('notification_preferences');
        Schema::dropIfExists('notifications');
    }
};
