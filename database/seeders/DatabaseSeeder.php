<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RolePermissionSeeder::class);

        $demo = [
            'admin@example.com' => ['Administrator', 'administrator'],
            'creator@example.com' => ['Content Creator', 'content-creator'],
            'reviewer@example.com' => ['Reviewer Demo', 'reviewer'],
            'publisher@example.com' => ['Publisher Demo', 'publisher'],
            'test@example.com' => ['Test User', 'content-creator'],
        ];

        foreach ($demo as $email => [$name, $roleSlug]) {
            User::updateOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                    'role_id' => Role::where('slug', $roleSlug)->value('id'),
                ],
            );
        }
    }
}
