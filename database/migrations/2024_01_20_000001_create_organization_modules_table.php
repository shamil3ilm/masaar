<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_modules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('module_code', 50);
            $table->boolean('is_enabled')->default(true);
            $table->json('enabled_features')->nullable(); // Specific features within module
            $table->json('settings')->nullable(); // Module-specific settings
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->foreignId('enabled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'module_code']);
            $table->index(['organization_id', 'is_enabled']);
        });

        // Add module_access column to users for quick lookup
        Schema::table('users', function (Blueprint $table) {
            $table->json('module_access')->nullable()->after('is_active');
        });

        // Add subscription tier to organizations if not exists
        if (!Schema::hasColumn('organizations', 'subscription_tier')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->string('subscription_tier', 20)->default('standard')->after('is_active');
                $table->timestamp('subscription_expires_at')->nullable()->after('subscription_tier');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('module_access');
        });

        if (Schema::hasColumn('organizations', 'subscription_tier')) {
            Schema::table('organizations', function (Blueprint $table) {
                $table->dropColumn(['subscription_tier', 'subscription_expires_at']);
            });
        }

        Schema::dropIfExists('organization_modules');
    }
};
