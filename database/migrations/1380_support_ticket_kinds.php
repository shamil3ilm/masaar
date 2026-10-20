<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The portal's Requests: a ticket is a request for help, for paid work for
 * one customer (a customization) or for something new for everyone (a
 * feature request). All three keep the one conversation a ticket has.
 *
 * A customization or a feature request also moves through stages -
 * submitted, under review, planned, in progress, released or declined -
 * beside whose move it is. A message that moved it says to which stage, so
 * the conversation reads as the request's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            // support, customization or feature.
            $table->string('kind', 20)->default('support')->after('org_id');
            // Null for a support ticket.
            $table->string('stage', 20)->nullable()->after('status');

            $table->index(['kind', 'status', 'last_activity_at'], 'support_tickets_kind_index');
        });

        Schema::table('support_messages', function (Blueprint $table) {
            // The stage this message moved the request to, when it moved it.
            $table->string('stage', 20)->nullable()->after('internal');
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table) {
            $table->dropColumn('stage');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropIndex('support_tickets_kind_index');
            $table->dropColumn(['kind', 'stage']);
        });
    }
};
