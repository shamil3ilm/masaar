<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The membership's working-branch column (0810) comes and goes on every
 * engine the suite runs on. Its down() dropped the foreign key by the name
 * 0810 gave it, which SQLite cannot do: SQLite drops a key by its column.
 */
class WorkingBranchMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_column_rolls_back_and_forward(): void
    {
        $moved = require database_path('migrations/1090_working_branch_customization.php');
        $column = require database_path('migrations/0810_working_branch.php');

        // Back to just after 0810, then before it.
        $moved->down();
        $this->assertTrue(Schema::hasColumn('organization_user', 'working_branch_id'));

        $column->down();
        $this->assertFalse(Schema::hasColumn('organization_user', 'working_branch_id'));

        // And forward again.
        $column->up();
        $moved->up();
        $this->assertFalse(Schema::hasColumn('organization_user', 'working_branch_id'));
    }
}
