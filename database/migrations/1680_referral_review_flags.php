<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A referral an admin must look at: it qualified on the company's first
 * paid invoice, that payment was reversed, and its rewards could not be
 * taken back by themselves (a reward's credit was already used). Why, and
 * since when; cleared when the referral is reversed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->string('review_reason', 500)->nullable();
            $table->timestamp('review_flagged_at')->nullable();

            $table->index('review_flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('referrals', function (Blueprint $table) {
            $table->dropIndex(['review_flagged_at']);
            $table->dropColumn(['review_reason', 'review_flagged_at']);
        });
    }
};
