<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Platform admin roles as data (AdminRoles): the four that shipped as an enum
 * are system roles, and custom ones can be made from the permission set.
 * users.platform_role names a role by its key, as it always has - twenty
 * characters at most, the width of that column.
 *
 * A system role's permissions are its enum's, read from code, so a
 * permission added later reaches it without a migration; the column is kept
 * for the record. Rank orders who may act on whose access; only the owner is
 * at the top.
 */
return new class extends Migration
{
    private const SYSTEM = [
        'owner' => ['Owner', 4, 'Everything, including managing admins and the platform itself.'],
        'operations' => ['Operations', 3, 'Runs the service: reviews companies, licences, accounts, the queue.'],
        'support' => ['Support', 2, 'Helps people: reads everything, and resets a lost second factor.'],
        'auditor' => ['Auditor', 1, 'Checks the record: reads everything, including the audit log.'],
    ];

    public function up(): void
    {
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key', 20)->unique();
            $table->string('name', 100);
            $table->string('description', 500)->nullable();
            $table->text('permissions');
            $table->unsignedTinyInteger('rank');
            $table->boolean('system')->default(false);
            $table->uuid('updated_by')->nullable();
            $table->timestamps();
        });

        $now = now();

        foreach (self::SYSTEM as $key => [$name, $rank, $description]) {
            DB::table('admin_roles')->insert([
                'id' => (string) Str::uuid(),
                'key' => $key,
                'name' => $name,
                'description' => $description,
                'permissions' => '[]',
                'rank' => $rank,
                'system' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_roles');
    }
};
