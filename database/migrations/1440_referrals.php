<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The referral programme.
 *
 * referral_codes    one per organization, made the first time it is asked
 *                   for: its link and the code entered at sign-up. Only the
 *                   platform writes it.
 * referrals         a company that signed up with another's code - one
 *                   referrer per company (the unique org_id) - and the
 *                   programme's terms as they stood then, which later
 *                   setting changes never touch. Its status moves: signed
 *                   up, approved, qualified, rewarded; refused when the
 *                   abuse checks find it is the referrer itself; reversed
 *                   by an admin.
 * referral_rewards  what each side was given (class c: never deleted): a
 *                   credit (account_credits), months on the plan, or
 *                   withheld by a cap until an admin grants it. Adjusted or
 *                   reversed with who and why; every step is audited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id')->unique();
            $table->string('code', 16)->unique();
            $table->timestamps();

            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('referrer_org_id');
            // The company referred: one referrer each.
            $table->uuid('org_id')->unique();
            $table->string('code', 16);
            // signed_up, approved, qualified, rewarded, refused or reversed.
            $table->string('status', 20);
            // ReferralTerms as they stood when it signed up.
            $table->json('terms');
            $table->string('refusal_reason', 255)->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('referrer_org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();

            foreach (['reversed_by', 'created_by', 'updated_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }

            $table->index(['referrer_org_id', 'status'], 'referrals_referrer_index');
            $table->index(['status', 'created_at'], 'referrals_status_index');
        });

        Schema::create('referral_rewards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('referral_id');
            // Who receives it: the referrer or the company referred.
            $table->uuid('org_id');
            // referrer or referred.
            $table->string('side', 10);
            // percent_off, credit or free_months.
            $table->string('kind', 20);
            $table->decimal('percent', 5, 2)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedSmallInteger('months')->nullable();
            // granted, withheld or reversed.
            $table->string('status', 20);
            $table->string('withheld_reason', 255)->nullable();
            $table->uuid('credit_id')->nullable();
            $table->uuid('licence_id')->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->uuid('reversed_by')->nullable();
            $table->string('reversal_reason', 500)->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('referral_id')->references('id')->on('referrals')->cascadeOnDelete();
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('credit_id')->references('id')->on('account_credits')->nullOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reversed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['org_id', 'side', 'granted_at'], 'referral_rewards_cap_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('referral_rewards');
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_codes');
    }
};
