<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The paper an invoice prints on and a branch's receipt printer, out of
 * their columns and into the customizations store: the organization's, each
 * branch's and each member's paper, and each branch's printer. A column
 * that was null set nothing, so it moves as no row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach (DB::table('organizations')->whereNotNull('paper_size')->get(['id', 'paper_size']) as $organization) {
            $rows[] = $this->row('printing.paper', 'organization', 'organization:'.$organization->id, $organization->paper_size, ['org_id' => $organization->id], $now);
        }

        foreach (DB::table('branches')->get(['id', 'org_id', 'paper_size', 'receipt_dpi', 'receipt_dots']) as $branch) {
            foreach (['printing.paper' => $branch->paper_size, 'printing.receipt_dpi' => $branch->receipt_dpi, 'printing.receipt_dots' => $branch->receipt_dots] as $key => $value) {
                if ($value !== null) {
                    $rows[] = $this->row($key, 'branch', 'branch:'.$branch->id, $key === 'printing.paper' ? (string) $value : (int) $value,
                        ['org_id' => $branch->org_id, 'branch_id' => $branch->id], $now);
                }
            }
        }

        foreach (DB::table('organization_user')->whereNotNull('paper_size')->get(['org_id', 'user_id', 'paper_size']) as $member) {
            $rows[] = $this->row('printing.paper', 'user', 'member:'.$member->org_id.':'.$member->user_id, $member->paper_size,
                ['org_id' => $member->org_id, 'user_id' => $member->user_id], $now);
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('customizations')->insert($chunk);
        }

        foreach (['organizations', 'branches', 'organization_user'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('paper_size');
            });
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['receipt_dpi', 'receipt_dots']);
        });
    }

    public function down(): void
    {
        foreach (['organizations', 'branches', 'organization_user'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('paper_size', 8)->nullable();
            });
        }

        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedSmallInteger('receipt_dpi')->nullable();
            $table->unsignedSmallInteger('receipt_dots')->nullable();
        });

        $columns = ['printing.paper' => 'paper_size', 'printing.receipt_dpi' => 'receipt_dpi', 'printing.receipt_dots' => 'receipt_dots'];

        foreach (DB::table('customizations')->whereIn('key', array_keys($columns))->get() as $row) {
            $value = json_decode((string) $row->value, true);
            $column = $columns[$row->key];

            match ($row->level) {
                'organization' => DB::table('organizations')->where('id', $row->org_id)->update([$column => $value]),
                'branch' => DB::table('branches')->where('id', $row->branch_id)->update([$column => $value]),
                'user' => DB::table('organization_user')->where('org_id', $row->org_id)->where('user_id', $row->user_id)->update([$column => $value]),
                default => null,
            };
        }

        DB::table('customizations')->whereIn('key', array_keys($columns))->delete();
    }

    /**
     * @param  array<string, string>  $owners
     * @return array<string, mixed>
     */
    private function row(string $key, string $level, string $holder, mixed $value, array $owners, mixed $now): array
    {
        return [
            'id' => (string) Str::uuid(),
            'key' => $key,
            'level' => $level,
            'holder' => $holder,
            'org_id' => $owners['org_id'] ?? null,
            'branch_id' => $owners['branch_id'] ?? null,
            'user_id' => $owners['user_id'] ?? null,
            'value' => json_encode($value),
            'locked' => false,
            'updated_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
