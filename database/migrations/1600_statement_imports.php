<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A bank statement or a card settlement report brought into Admin ›
 * Finance › Reconciliation: the platform's own money, so it belongs to no
 * customer.
 *
 *   kind                 csv, camt053, mt940 or settlement.
 *   source               the Finance › Settings bank account it is the
 *                        statement of (its key), or the card gateway.
 *   statement_reference  the statement's own id (CAMT.053 Stmt/Id, MT940
 *                        :20: and :28C:, a settlement's id): one statement
 *                        of a source is brought in once.
 *   file_hash            SHA-256 of the file: the same file is refused.
 *
 * The file itself is on the private imports disk while it may be needed
 * and removed after the keeping period (finance:prune-statements);
 * file_path is null from then on and this row keeps what came of it.
 *
 * A financial record (class c): never deleted; updated only when its file
 * is pruned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('kind', 16);
            $table->string('source', 40);
            $table->string('profile', 40)->nullable();
            $table->string('account_identifier', 64)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('statement_reference', 120)->nullable();
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->decimal('opening_balance', 15, 2)->nullable();
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->char('file_hash', 64)->unique();
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 255);
            $table->unsignedInteger('file_size')->default(0);
            $table->timestamp('file_removed_at')->nullable();
            $table->unsignedInteger('lines_total')->default(0);
            $table->unsignedInteger('lines_new')->default(0);
            $table->unsignedInteger('lines_duplicate')->default(0);
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->unique(['source', 'statement_reference']);
            $table->index('created_at');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_imports');
    }
};
