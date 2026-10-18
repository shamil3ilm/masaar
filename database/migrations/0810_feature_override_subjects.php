<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A platform admin's feature switch for one role or one person in an
 * organization, beside the switch for the organization itself
 * (FeatureOverrides, docs/architecture/ACCESS.md).
 *
 * subject is '' for the organization, 'role:{role}' for everyone in it with
 * that role and 'user:{id}' for one person, so the unique key is still the
 * switch. The new key is added before the old one goes: it starts with
 * org_id, which MySQL needs an index on for the foreign key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organization_feature_overrides', function (Blueprint $table) {
            $table->string('subject', 60)->default('');
        });

        Schema::table('organization_feature_overrides', function (Blueprint $table) {
            $table->unique(['org_id', 'feature', 'subject'], 'feature_overrides_subject_unique');
            $table->dropUnique(['org_id', 'feature']);
        });
    }

    public function down(): void
    {
        DB::table('organization_feature_overrides')->where('subject', '!=', '')->delete();

        Schema::table('organization_feature_overrides', function (Blueprint $table) {
            $table->unique(['org_id', 'feature']);
            $table->dropUnique('feature_overrides_subject_unique');
        });

        Schema::table('organization_feature_overrides', function (Blueprint $table) {
            $table->dropColumn('subject');
        });
    }
};
