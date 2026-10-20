<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A CSV file of invoices, customers or products brought in through the
 * portal: checked row by row, shown with each row's problems, and only then
 * saved - invoices as drafts, never issued.
 *
 * The file itself is on the private imports disk while it may be needed and
 * removed after the keeping period (imports:prune); file_path is null from
 * then on and this row keeps what came of it.
 *
 * An entity whose status moves (class a): checking, checked, importing,
 * imported or failed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('csv_imports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            // The branch its invoices are drafted at: the importer's, as the
            // invoice form would choose it. Null for head office.
            $table->uuid('branch_id')->nullable();
            // 'invoices', 'customers' or 'products'.
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->string('file_path', 255)->nullable();
            $table->string('file_name', 255);
            $table->unsignedInteger('file_size')->default(0);
            $table->unsignedInteger('rows_total')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->unsignedInteger('rows_imported')->default(0);
            // Each problem by row and column, capped (CsvImports::PROBLEMS).
            $table->json('problems')->nullable();
            // The first records that would be saved, as the page shows them.
            $table->json('preview')->nullable();
            // Why the file could not be read at all.
            $table->string('failure', 500)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('file_removed_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->index(['org_id', 'created_at']);
            $table->index(['file_path', 'created_at']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();

            foreach (['created_by', 'updated_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('csv_imports');
    }
};
