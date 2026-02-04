<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Document folders
        Schema::create('document_folders', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('document_folders')->nullOnDelete();
            $table->string('name');
            $table->string('color', 7)->nullable(); // Hex color
            $table->string('icon', 50)->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false); // System folders can't be deleted
            $table->string('access_level', 20)->default('organization'); // organization, branch, private
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'parent_id']);
        });

        // Documents
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('document_folders')->nullOnDelete();
            $table->string('name');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('file_size');
            $table->string('extension', 10);
            $table->text('description')->nullable();
            $table->json('tags')->nullable();
            $table->string('document_type', 50)->nullable(); // contract, invoice, receipt, id_proof, etc.
            $table->date('document_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->boolean('is_expiry_notified')->default(false);
            $table->morphs('documentable'); // Attached to: employee, customer, invoice, etc.
            $table->string('access_level', 20)->default('organization'); // organization, branch, private
            $table->boolean('is_archived')->default(false);
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('last_accessed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'folder_id']);
            $table->index(['organization_id', 'document_type']);
            $table->index(['documentable_type', 'documentable_id']);
            $table->index(['organization_id', 'expiry_date']);
            $table->fullText(['name', 'description']);
        });

        // Document versions
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version_number');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size');
            $table->string('change_summary')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
        });

        // Document access permissions
        Schema::create('document_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('folder_id')->nullable()->constrained('document_folders')->cascadeOnDelete();
            $table->morphs('permissible'); // user or role
            $table->string('permission', 20); // view, download, edit, delete, manage
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['document_id', 'permissible_type', 'permissible_id']);
            $table->index(['folder_id', 'permissible_type', 'permissible_id']);
        });

        // Document activity log
        Schema::create('document_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('action', 30); // viewed, downloaded, uploaded, edited, shared, deleted
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['document_id', 'created_at']);
        });

        // Document shares (external sharing)
        Schema::create('document_shares', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shared_by')->constrained('users')->cascadeOnDelete();
            $table->string('share_type', 20)->default('link'); // link, email
            $table->string('recipient_email')->nullable();
            $table->string('access_code', 32)->nullable(); // Optional password
            $table->boolean('allow_download')->default(true);
            $table->unsignedInteger('max_downloads')->nullable();
            $table->unsignedInteger('download_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['uuid', 'is_active']);
        });

        // Digital signatures
        Schema::create('digital_signatures', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signer_email');
            $table->string('signer_name');
            $table->string('status', 20)->default('pending'); // pending, signed, declined, expired
            $table->text('signature_data')->nullable(); // Base64 signature image
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('verification_code', 32)->nullable();
            $table->timestamps();

            $table->index(['document_id', 'status']);
            $table->index(['signer_email', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('digital_signatures');
        Schema::dropIfExists('document_shares');
        Schema::dropIfExists('document_activities');
        Schema::dropIfExists('document_permissions');
        Schema::dropIfExists('document_versions');
        Schema::dropIfExists('documents');
        Schema::dropIfExists('document_folders');
    }
};
