<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;

class AuthService
{
    /**
     * Register a new user and issue a token.
     *
     * @param  array{name: string, email: string, password: string}  $data
     * @return array{user: User, token: string, expires_at: Carbon}
     */
    public function register(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $user = User::create($data);

            return $this->issueToken($user);
        });
    }

    /**
     * Authenticate and issue a token.
     *
     * @param  array{email: string, password: string}  $data
     * @return array{user: User, token: string, expires_at: Carbon}
     */
    public function login(array $data): array
    {
        $user = User::query()->where('email', $data['email'])->first();
        $valid = Hash::check(
            $data['password'],
            $user?->password ?? '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.'
        );
        abort_unless($user && $valid, 401, 'Invalid credentials.');

        return $this->issueToken($user);
    }

    public function logout(User $user): void
    {
        /** @var PersonalAccessToken $token */
        $token = $user->currentAccessToken();
        $token->delete();
    }

    /**
     * @return array{user: User, token: string, expires_at: Carbon}
     */
    private function issueToken(User $user): array
    {
        $expires = now()->addMinutes((int) config('sanctum.expiration'));
        $token = $user->createToken('api', ['*'], $expires);

        return [
            'user' => $user,
            'token' => $token->plainTextToken,
            'expires_at' => $expires,
        ];
    }
}
