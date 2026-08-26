<?php

namespace Database\Seeders;

use App\Models\User;
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
        $this->call(AdminRoleSeeder::class);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@example.test'],
            [
                'name' => 'Admin',
                'password' => 'password',
            ],
        );
        if (! $admin->hasAnyRole(['super_admin', 'admin'])) {
            $admin->assignRole('admin');
        }

        $this->call(LeadGenerationQuizSeeder::class);
    }
}
