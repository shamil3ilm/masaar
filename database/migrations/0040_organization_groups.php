<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_groups', function (Blueprint $table) {
            $table->uuid('id');
            $table->string('name', 255);
            $table->string('status', 255)->default('active');
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_groups');
    }
};
