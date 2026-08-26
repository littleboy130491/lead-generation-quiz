<?php

namespace App\Http\Controllers\Api;

use App\Actions\Users\CreateUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class UserProvisioningController extends Controller
{
    public function __invoke(Request $request, CreateUser $createUser): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
            'roles' => ['required', 'array', 'min:1'],
            'roles.*' => ['required', 'string', 'max:100'],
            'email_verified' => ['sometimes', 'boolean'],
        ]);

        $user = $createUser->handle([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'roles' => array_values($data['roles']),
            'email_verified' => $data['email_verified'] ?? true,
        ]);

        return response()->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roles' => $user->getRoleNames()->values()->all(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'created_at' => $user->created_at?->toIso8601String(),
            ],
        ], 201);
    }
}
