<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform admin freezing a customer's account by hand, or lifting a
 * freeze, with the reason: the overdue-payment freeze's manual half.
 *
 * kind is freeze or release, and the latest row decides. A freeze holds
 * until it is released; a release holds the account in good standing until
 * its date (released_until), whatever the policy's stages say, after which
 * the policy decides again. Who did it, and why, is the record; the
 * console's audit log has the request.
 *
 * An append-only log (class d): a row is never changed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('kind', 10);
            $table->string('reason', 500);
            $table->date('released_until')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['org_id', 'created_at']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_holds');
    }
};
