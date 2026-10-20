<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which board item a feature request was triaged into. Several requests -
 * duplicates from different companies - share one item, and each company
 * keeps its own request and conversation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->uuid('board_item_id')->nullable()->after('importance');

            $table->foreign('board_item_id')->references('id')->on('feature_board_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('feature_requests', function (Blueprint $table) {
            $table->dropForeign(['board_item_id']);
            $table->dropColumn('board_item_id');
        });
    }
};
