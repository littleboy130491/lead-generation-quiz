<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class CreateUser
{
    /** @var list<string> */
    public const ALLOWED_ROLES = [
        'super_admin',
        'admin',
        'quiz_manager',
        'submission_manager',
    ];

    /**
     * @param  array{name: string, email: string, password: string, roles: list<string>, email_verified?: bool}  $data
     */
    public function handle(array $data): User
    {
        $roles = array_values(array_unique($data['roles']));
        $unknown = array_values(array_diff($roles, self::ALLOWED_ROLES));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'roles' => 'Roles must be one or more of: '.implode(', ', self::ALLOWED_ROLES).'.',
            ]);
        }

        $missing = [];
        foreach ($roles as $role) {
            if (! Role::query()->where('name', $role)->exists()) {
                $missing[] = $role;
            }
        }
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'roles' => 'Unknown or unseeded roles: '.implode(', ', $missing).'. Run AdminRoleSeeder first.',
            ]);
        }

        return DB::transaction(function () use ($data, $roles): User {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'password' => $data['password'],
            ]);

            if ($data['email_verified'] ?? true) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            $user->syncRoles($roles);

            return $user->fresh(['roles']);
        });
    }
}
