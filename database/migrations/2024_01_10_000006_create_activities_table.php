<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // Subject (the entity the activity is about)
            $table->string('subject_type', 100);
            $table->unsignedBigInteger('subject_id');

            // Causer (who/what caused this activity - could be user, system, API)
            $table->string('causer_type', 100)->nullable();
            $table->unsignedBigInteger('causer_id')->nullable();

            // Activity details
            $table->string('event', 50); // created, updated, deleted, sent, approved, etc.
            $table->string('description', 500)->nullable();

            // Change tracking
            $table->json('properties')->nullable(); // Additional properties
            $table->json('old_values')->nullable(); // Values before change
            $table->json('new_values')->nullable(); // Values after change

            // Metadata
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('source', 30)->default('web'); // web, api, system, import

            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['organization_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
            $table->index('event');
        });

        // Comments on entities (timeline items)
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Commentable entity
            $table->string('commentable_type', 100);
            $table->unsignedBigInteger('commentable_id');

            // Parent comment for threading
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();

            $table->text('content');
            $table->boolean('is_internal')->default(false); // Internal note vs customer-visible
            $table->boolean('is_pinned')->default(false);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['commentable_type', 'commentable_id']);
        });

        // Mentions in comments
        Schema::create('mentions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->unique(['comment_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mentions');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('activities');
    }
};
