<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The API keys an organization's administrators make for themselves, in the
 * portal (Integrations).
 *
 * A key is a credential of the organization, acting under its licence: the
 * licence's features and allowances apply to every call made with it, and its
 * scopes are a choice from the licence's. Admin › Licences stays where a
 * platform admin issues the licence itself, to a partner or for the
 * platform's own use.
 *
 * The secret is kept only as its keyed hash (License::hashSecret, peppered),
 * so it is shown once when the key is made and never again. A sandbox key
 * never issues or files where the deployment files with the live authority
 * (SandboxGuard).
 *
 * An entity people remove (class b): revoking a key removes it, and a
 * removed key never authenticates again. The key itself is generated and
 * never issued twice, so it is unique across removed rows.
 *
 * status is 'active', or 'pending' while a production key waits for a
 * platform admin's approval (ApprovalCatalogue: api_keys.production);
 * approval_request_id is that request, so the page can say how it ended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('org_id');
            $table->string('name', 100);
            // 'sandbox' or 'production' (LicenseEnvironment).
            $table->string('environment', 16);
            $table->string('api_key', 64);
            $table->string('secret_hash', 64);
            $table->json('scopes');
            // Addresses or CIDR ranges the key may be used from; null for any.
            $table->json('allowed_ips')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 16);
            $table->uuid('approval_request_id')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('api_key');
            $table->index(['org_id', 'created_at']);
            $table->foreign('org_id')->references('id')->on('organizations')->cascadeOnDelete();
            $table->foreign('approval_request_id')->references('id')->on('approval_requests')->nullOnDelete();

            foreach (['created_by', 'updated_by', 'deleted_by'] as $actor) {
                $table->foreign($actor)->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_keys');
    }
};
