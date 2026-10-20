<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each reminder sent to an organization whose e-invoicing start date is
 * near with no service provider appointed: which start date it was about
 * and how many days before it (90, 30 or 7). The unique key is what makes
 * each go out once per date, however often the reminder runs; a new start
 * date is reminded afresh. An append-only log (class d): reminded_at, no
 * updated_at, never deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('einvoicing_start_reminders', function (Blueprint $table) {
            $table->id();
            $table->uuid('org_id');
            $table->date('starts_on');
            $table->unsignedSmallInteger('days_before');
            $table->timestamp('reminded_at');

            $table->unique(['org_id', 'starts_on', 'days_before'], 'einvoicing_start_reminders_once');
            $table->foreign('org_id')->references('id')->on('organizations')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('einvoicing_start_reminders');
    }
};
