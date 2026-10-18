<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each warning a customer was sent about an allowance: which licence, which
 * allowance, the period it counts in ('2026-10' for a month, '2026-10-18'
 * for a day) and the threshold reached. The unique key is what makes each
 * one go out once a period, however many requests cross it together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('allowance_warnings', function (Blueprint $table) {
            $table->id();
            $table->uuid('license_id');
            $table->string('allowance', 32);
            $table->string('period', 10);
            $table->unsignedTinyInteger('percent');
            $table->unsignedBigInteger('used');
            $table->unsignedBigInteger('allowed');
            $table->timestamp('warned_at');

            $table->unique(['license_id', 'allowance', 'period', 'percent'], 'allowance_warnings_once');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('allowance_warnings');
    }
};
