<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Handing the platform's ownership to another admin (PlatformOwnership).
 *
 * The platform has exactly one owner. The owner offers ownership to one
 * admin, naming the role they step down to; it moves only when that admin
 * accepts. Evidence of who held the platform when, so never deleted: who
 * offered it, who accepted, who cancelled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('from_user_id');
            $table->uuid('to_user_id');
            $table->string('demote_to', 20);
            $table->string('status', 20)->default('pending');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('accepted_by')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->foreign('from_user_id')->references('id')->on('users');
            $table->foreign('to_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_transfers');
    }
};
