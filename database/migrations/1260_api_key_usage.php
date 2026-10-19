<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much each API key is used: requests and errors per key per day, for
 * the portal's Integrations page.
 *
 * Counters the platform keeps (class a, written by the system), as
 * license_usage keeps them per licence. The licence's own counters still
 * meter the organization's allowance; these only say which key made the
 * calls.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_usage', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('api_key_id');
            $table->date('usage_date');
            $table->unsignedInteger('requests')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->timestamps();

            $table->unique(['api_key_id', 'usage_date']);
            $table->foreign('api_key_id')->references('id')->on('api_keys')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_usage');
    }
};
