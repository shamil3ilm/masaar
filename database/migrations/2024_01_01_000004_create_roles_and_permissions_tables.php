<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Permissions table (global - not organization-specific)
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Human-readable name
            $table->string('slug')->unique(); // e.g., 'sales.invoices.create'
            $table->string('module', 50); // e.g., 'sales', 'accounting', 'inventory'
            $table->string('description')->nullable();
            $table->timestamps();

            $table->index('module');
        });

        // Roles table (organization-specific)
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(false); // System roles can't be deleted
            $table->timestamps();

            // Slug unique within organization (null org = global)
            $table->unique(['organization_id', 'slug']);
            $table->index('is_system');
        });

        // Role-Permission pivot table
        Schema::create('role_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['role_id', 'permission_id']);
        });

        // User-Role pivot table (with optional branch scoping)
        Schema::create('user_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->cascadeOnDelete(); // null = all branches
            $table->timestamps();

            $table->unique(['user_id', 'role_id', 'branch_id']);
        });

        // User-Branch pivot table
        Schema::create('user_branches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'branch_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_branches');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
