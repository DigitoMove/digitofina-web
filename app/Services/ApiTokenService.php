<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class ApiTokenService
{
    /**
     * @return array{user:User,token:string}
     */
    public function issue(string $login, string $password, string $deviceName = 'api-client'): array
    {
        $user = User::query()
            ->where('email', $login)
            ->orWhere('phone', $login)
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw ValidationException::withMessages(['login' => 'The provided credentials are invalid.']);
        }

        if ($user->status !== 'active' || ! $user->is_active) {
            throw ValidationException::withMessages(['login' => 'This user account is not active.']);
        }

        $token = $user->createToken($deviceName, ['*'], now()->addDays(30))->plainTextToken;

        return ['user' => $user, 'token' => $token];
    }
}
