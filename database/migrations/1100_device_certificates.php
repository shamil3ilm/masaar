<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every production certificate a device has held, oldest first.
 *
 * A document is filed with the certificate that signed it, and a renewal
 * gives the device a new one while documents signed with the old one may
 * still be queued, retried or reported late. So a renewal adds a row and
 * marks the previous one superseded rather than replacing it.
 *
 * The certificate, its secret and its private key stay in the encrypted
 * credential store; this holds what is safe to query. certificate_id is the
 * digest ChainRecorder writes on every chain entry, which is how a document
 * finds the version that signed it.
 *
 * A compliance record: status changes, so it has updated_at, and it is never
 * deleted, so it has no deleted_at. It keeps who created it and, as its own
 * lifecycle actor, who revoked it and why: nobody for a revocation the
 * scheduler read from the CA's list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('org_id');
            // The organization's id for head office, a branch's for a branch.
            $table->uuid('device_id');
            $table->unsignedInteger('version');
            $table->string('certificate_id', 64);
            $table->string('serial_number', 128)->nullable();
            $table->string('issuer', 512)->nullable();
            // dateTime, not timestamp: a certificate may be valid past 2038,
            // where a MySQL TIMESTAMP ends.
            $table->dateTime('valid_from')->nullable();
            $table->dateTime('valid_to')->nullable();
            // 'active', 'superseded' or 'revoked'.
            $table->string('status', 16);
            $table->string('revoked_reason', 500)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['device_id', 'certificate_id'], 'device_certificates_device_certificate_unique');
            $table->unique(['device_id', 'version'], 'device_certificates_device_version_unique');
            $table->index(['org_id'], 'device_certificates_org_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_certificates');
    }
};
