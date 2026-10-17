<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A platform admin's switch for one feature of one organization
 * (FeatureOverride, docs/architecture/ACCESS.md).
 *
 * One row per organization and feature, so the unique key is the switch.
 * state is 'off' (refused whatever the plan says) or 'on' (granted beyond
 * the plan); no row is the default. The reason and who set it stay on the
 * row; every change, with the value before and after, is in the admin audit
 * log. Deleting the organization takes its switches with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_feature_overrides', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('feature', 40);
            $table->string('state', 3);
            $table->string('reason', 500);
            $table->string('set_by')->nullable();
            $table->timestamps();

            $table->unique(['org_id', 'feature']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_feature_overrides');
    }
};
