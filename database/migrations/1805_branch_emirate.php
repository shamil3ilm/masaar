<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The emirate a branch of a company in the UAE is in, as the e-invoice
 * codes it (AUH, DXB, SHJ, AJM, UAQ, RAK, FUJ): the VAT return reports each
 * standard rated sale in the emirate of the establishment it is made from
 * (Cabinet Decision No. 52 of 2017 Art 72(2)). Empty for a branch
 * elsewhere.
 *
 * Branches already there are given the emirate their district or city
 * names, where that names exactly one; the rest stay empty for the
 * company to choose.
 */
return new class extends Migration
{
    /** Codes, and the towns an address names in place of its emirate. */
    private const NAMES = [
        'auh' => 'AUH', 'abu dhabi' => 'AUH', 'al ain' => 'AUH', 'ruwais' => 'AUH', 'madinat zayed' => 'AUH',
        'dxb' => 'DXB', 'dubai' => 'DXB', 'jebel ali' => 'DXB', 'hatta' => 'DXB',
        'shj' => 'SHJ', 'sharjah' => 'SHJ', 'khor fakkan' => 'SHJ', 'kalba' => 'SHJ',
        'ajm' => 'AJM', 'ajman' => 'AJM',
        'uaq' => 'UAQ', 'umm al quwain' => 'UAQ', 'umm al-quwain' => 'UAQ',
        'rak' => 'RAK', 'ras al khaimah' => 'RAK', 'ras al-khaimah' => 'RAK',
        'fuj' => 'FUJ', 'fujairah' => 'FUJ',
    ];

    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->string('emirate', 3)->nullable();
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn('emirate');
        });
    }

    /** Each UAE branch without one, given the one emirate its address names. */
    public function backfill(): void
    {
        $branches = DB::table('branches')
            ->join('organizations', 'organizations.id', '=', 'branches.org_id')
            ->where('organizations.country', 'AE')
            ->whereNull('branches.emirate')
            ->select(['branches.id', 'branches.district', 'branches.city'])
            ->get();

        foreach ($branches as $branch) {
            $named = array_values(array_unique(array_filter([
                self::NAMES[mb_strtolower(trim((string) $branch->district))] ?? null,
                self::NAMES[mb_strtolower(trim((string) $branch->city))] ?? null,
            ])));

            if (count($named) === 1) {
                DB::table('branches')->where('id', $branch->id)->update(['emirate' => $named[0]]);
            }
        }
    }
};
