<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lead sources (website, referral, etc.)
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        // Pipeline stages
        Schema::create('pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('code', 20)->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('probability')->default(0); // 0-100%
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('color', 7)->nullable();
            $table->boolean('is_won')->default(false);
            $table->boolean('is_lost')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
        });

        // Leads
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Lead info
            $table->string('lead_number', 50)->nullable();
            $table->string('title', 200)->nullable();
            $table->enum('lead_type', ['individual', 'company'])->default('company');

            // Company info
            $table->string('company_name', 200)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('website', 200)->nullable();
            $table->unsignedInteger('employee_count')->nullable();
            $table->decimal('annual_revenue', 15, 2)->nullable();

            // Primary contact
            $table->string('contact_name', 200);
            $table->string('contact_title', 100)->nullable();
            $table->string('email', 200)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('mobile', 30)->nullable();

            // Address
            $table->string('address_line_1', 200)->nullable();
            $table->string('address_line_2', 200)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country_code', 2)->nullable();

            // Source & Assignment
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source_details', 200)->nullable(); // Campaign name, referrer, etc.
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Status
            $table->enum('status', [
                'new',
                'contacted',
                'qualified',
                'unqualified',
                'converted',
                'lost',
            ])->default('new');
            $table->string('lost_reason', 500)->nullable();

            // Scoring
            $table->unsignedSmallInteger('lead_score')->default(0); // 0-100
            $table->enum('rating', ['hot', 'warm', 'cold'])->default('cold');

            // Potential
            $table->decimal('estimated_value', 15, 4)->nullable();
            $table->string('currency_code', 3)->default('SAR');

            // Converted to
            $table->foreignId('converted_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignId('converted_opportunity_id')->nullable();
            $table->datetime('converted_at')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->text('description')->nullable();
            $table->text('notes')->nullable();
            $table->json('tags')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'lead_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'assigned_to']);
            $table->index(['organization_id', 'lead_source_id']);
        });

        // Opportunities
        Schema::create('opportunities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Opportunity info
            $table->string('opportunity_number', 50)->nullable();
            $table->string('name', 200);
            $table->text('description')->nullable();

            // Related to
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_name', 200)->nullable(); // Company name

            // Pipeline
            $table->foreignId('pipeline_stage_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('probability')->default(0); // 0-100

            // Value
            $table->decimal('amount', 15, 4)->nullable();
            $table->string('currency_code', 3)->default('SAR');
            $table->decimal('expected_revenue', 15, 4)->nullable(); // amount * probability

            // Dates
            $table->date('expected_close_date')->nullable();
            $table->date('actual_close_date')->nullable();

            // Status
            $table->enum('status', [
                'open',
                'won',
                'lost',
                'suspended',
            ])->default('open');
            $table->string('lost_reason', 500)->nullable();
            $table->string('won_reason', 500)->nullable();

            // Assignment
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_source_id')->nullable()->constrained()->nullOnDelete();

            // Converted to
            $table->foreignId('quotation_id')->nullable();
            $table->foreignId('sales_order_id')->nullable();

            $table->text('notes')->nullable();
            $table->json('tags')->nullable();
            $table->json('competitors')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'opportunity_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'pipeline_stage_id']);
            $table->index(['organization_id', 'assigned_to']);
            $table->index(['organization_id', 'expected_close_date']);
        });

        // Activities (calls, emails, meetings, tasks)
        Schema::create('crm_activities', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Activity type
            $table->enum('activity_type', [
                'call',
                'email',
                'meeting',
                'task',
                'note',
                'follow_up',
            ]);

            // Subject and description
            $table->string('subject', 200);
            $table->text('description')->nullable();

            // Related to (polymorphic)
            $table->string('related_type', 50)->nullable(); // lead, opportunity, contact
            $table->unsignedBigInteger('related_id')->nullable();

            // Timing
            $table->datetime('start_datetime')->nullable();
            $table->datetime('end_datetime')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->boolean('is_all_day')->default(false);

            // Status
            $table->enum('status', [
                'planned',
                'in_progress',
                'completed',
                'cancelled',
            ])->default('planned');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium');
            $table->datetime('completed_at')->nullable();

            // For calls
            $table->enum('call_direction', ['inbound', 'outbound'])->nullable();
            $table->enum('call_result', ['connected', 'no_answer', 'busy', 'voicemail', 'wrong_number'])->nullable();

            // For meetings
            $table->string('location', 200)->nullable();
            $table->string('meeting_link', 500)->nullable();

            // Assignment
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->json('attendees')->nullable(); // Array of user IDs or contact IDs

            // Reminder
            $table->datetime('reminder_datetime')->nullable();
            $table->boolean('reminder_sent')->default(false);

            $table->text('outcome')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'activity_type']);
            $table->index(['organization_id', 'status']);
            $table->index(['related_type', 'related_id']);
            $table->index(['organization_id', 'assigned_to', 'status']);
            $table->index(['organization_id', 'start_datetime']);
        });

        // Add foreign key for converted_opportunity_id
        Schema::table('leads', function (Blueprint $table) {
            $table->foreign('converted_opportunity_id')->references('id')->on('opportunities')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropForeign(['converted_opportunity_id']);
        });

        Schema::dropIfExists('crm_activities');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('leads');
        Schema::dropIfExists('pipeline_stages');
        Schema::dropIfExists('lead_sources');
    }
};
