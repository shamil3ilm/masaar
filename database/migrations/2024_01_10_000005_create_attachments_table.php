<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // Polymorphic relation
            $table->string('attachable_type', 100);
            $table->unsignedBigInteger('attachable_id');

            // File details
            $table->string('file_name', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size'); // bytes
            $table->string('disk', 50)->default('local'); // local, s3, etc.
            $table->string('path', 500);

            // Metadata
            $table->string('category', 50)->nullable(); // receipt, contract, image, etc.
            $table->string('description', 500)->nullable();
            $table->json('metadata')->nullable(); // Image dimensions, PDF pages, etc.

            // Security
            $table->boolean('is_public')->default(false);
            $table->string('visibility', 20)->default('private'); // private, organization, public
            $table->timestamp('expires_at')->nullable();

            // Audit
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index(['organization_id', 'category']);
        });

        // Attachment access logs (for audit)
        Schema::create('attachment_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attachment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 20); // view, download, share
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index(['attachment_id', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachment_access_logs');
        Schema::dropIfExists('attachments');
    }
};
