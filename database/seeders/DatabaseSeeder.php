<?php

namespace Database\Seeders;

use App\Domains\Auth\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // A known address with the factory's password is an account anyone
        // can sign in to. Local and testing only, as DevUsersSeeder is.
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DatabaseSeeder creates a test account and only runs in local or testing.');

            return;
        }

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
