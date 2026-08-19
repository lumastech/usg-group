<?php

namespace Database\Seeders;

use App\Enums\MemberRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (MemberRole::values() as $role) {
            Role::findOrCreate($role, 'web');
        }
    }
}
