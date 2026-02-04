<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Platform admins (super admins that manage all organizations)
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('password');
            $table->string('role', 30)->default('admin'); // super_admin, admin, support, finance, viewer
            $table->string('avatar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_2fa_enabled')->default(false);
            $table->string('two_factor_secret')->nullable();
            $table->json('two_factor_recovery_codes')->nullable();
            $table->json('permissions')->nullable(); // Override role-based permissions
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['role', 'is_active']);
        });

        // Platform admin roles
        Schema::create('platform_admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 50)->unique();
            $table->text('description')->nullable();
            $table->json('permissions'); // List of permission slugs
            $table->boolean('is_system')->default(false); // Cannot be deleted
            $table->timestamps();
        });

        // Platform permissions (available to platform admins)
        Schema::create('platform_permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->string('module', 50); // organizations, users, billing, support, system
            $table->text('description')->nullable();
            $table->timestamps();
        });

        // Platform admin activity log
        Schema::create('platform_admin_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->string('action', 100);
            $table->string('entity_type', 100)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'created_at']);
            $table->index(['entity_type', 'entity_id']);
            $table->index(['organization_id']);
        });

        // Platform admin sessions
        Schema::create('platform_admin_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->string('session_token', 64)->unique();
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->string('device_type', 30)->nullable();
            $table->string('browser', 50)->nullable();
            $table->string('os', 50)->nullable();
            $table->timestamp('last_activity_at');
            $table->timestamp('expires_at');
            $table->boolean('is_revoked')->default(false);
            $table->timestamps();

            $table->index(['admin_id', 'is_revoked']);
            $table->index(['session_token']);
        });

        // Admin notifications
        Schema::create('admin_notifications', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('admin_id')->nullable()->constrained('platform_admins')->cascadeOnDelete();
            $table->string('type', 100); // new_organization, subscription_expiring, system_alert, support_ticket
            $table->string('title');
            $table->text('message');
            $table->string('severity', 20)->default('info'); // info, warning, error, critical
            $table->json('data')->nullable();
            $table->string('action_url')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->boolean('is_dismissed')->default(false);
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->index(['admin_id', 'is_read']);
            $table->index(['type', 'created_at']);
        });

        // Organization management (admin view of all organizations)
        Schema::create('organization_admin_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->text('note');
            $table->string('note_type', 30)->default('general'); // general, warning, support, billing
            $table->boolean('is_internal')->default(true); // Visible only to admins
            $table->boolean('is_pinned')->default(false);
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        // Organization status history (admin tracking)
        Schema::create('organization_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('status_from', 30)->nullable();
            $table->string('status_to', 30);
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'created_at']);
        });

        // Support tickets (admin can manage)
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('ticket_number', 30)->unique();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->string('subject');
            $table->text('description');
            $table->string('category', 50); // technical, billing, feature_request, bug_report, general
            $table->string('priority', 20)->default('medium'); // low, medium, high, urgent
            $table->string('status', 30)->default('open'); // open, in_progress, waiting_response, resolved, closed
            $table->json('tags')->nullable();
            $table->string('source', 30)->default('web'); // web, email, api, phone
            $table->timestamp('first_response_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('satisfaction_rating', 2, 1)->nullable(); // 1-5
            $table->text('satisfaction_feedback')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'status']);
            $table->index(['assigned_admin_id', 'status']);
            $table->index(['status', 'priority']);
        });

        // Support ticket messages
        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->text('message');
            $table->boolean('is_internal_note')->default(false); // Only visible to admins
            $table->json('attachments')->nullable();
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });

        // System announcements (from admin to all users)
        Schema::create('system_announcements', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('admin_id')->constrained('platform_admins')->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->string('type', 30)->default('info'); // info, warning, maintenance, feature, critical
            $table->string('target_audience', 30)->default('all'); // all, organizations, admins, specific
            $table->json('target_organization_ids')->nullable(); // For specific targeting
            $table->json('target_subscription_plans')->nullable();
            $table->boolean('is_dismissible')->default(true);
            $table->boolean('show_banner')->default(false);
            $table->string('banner_color', 7)->nullable();
            $table->string('action_url')->nullable();
            $table->string('action_text')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        // User announcement reads (track who dismissed)
        Schema::create('announcement_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('system_announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_dismissed')->default(false);
            $table->timestamps();

            $table->unique(['announcement_id', 'user_id']);
        });

        // Platform settings (global configuration)
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->text('value')->nullable();
            $table->string('type', 30)->default('string'); // string, integer, boolean, json, encrypted
            $table->string('group', 50)->default('general'); // general, email, security, billing, integrations
            $table->text('description')->nullable();
            $table->boolean('is_public')->default(false); // Can be fetched by frontend
            $table->foreignId('updated_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamps();

            $table->index(['group']);
        });

        // Feature flags (admin controlled)
        Schema::create('feature_flags', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug', 100)->unique();
            $table->text('description')->nullable();
            $table->boolean('is_enabled')->default(false);
            $table->string('rollout_type', 30)->default('all'); // all, percentage, specific, subscription_plan
            $table->unsignedTinyInteger('rollout_percentage')->nullable();
            $table->json('specific_organization_ids')->nullable();
            $table->json('specific_subscription_plans')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by')->constrained('platform_admins')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['is_enabled', 'rollout_type']);
        });

        // Admin IP whitelist
        Schema::create('admin_ip_whitelist', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('platform_admins')->cascadeOnDelete();
            $table->string('ip_address', 45);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('platform_admins')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['admin_id', 'ip_address']);
            $table->index(['ip_address', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_ip_whitelist');
        Schema::dropIfExists('feature_flags');
        Schema::dropIfExists('platform_settings');
        Schema::dropIfExists('announcement_reads');
        Schema::dropIfExists('system_announcements');
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('organization_status_history');
        Schema::dropIfExists('organization_admin_notes');
        Schema::dropIfExists('admin_notifications');
        Schema::dropIfExists('platform_admin_sessions');
        Schema::dropIfExists('platform_admin_activities');
        Schema::dropIfExists('platform_permissions');
        Schema::dropIfExists('platform_admin_roles');
        Schema::dropIfExists('platform_admins');
    }
};
