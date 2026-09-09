<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_issues_a_hashed_expiring_token_and_hides_secrets(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Cook', 'email' => 'COOK@example.com',
            'password' => 'secure-password123', 'password_confirmation' => 'secure-password123',
            'id' => 900, 'email_verified_at' => now()->toISOString(),
        ])->assertCreated()->assertJsonPath('data.user.email', 'cook@example.com')
            ->assertJsonMissingPath('data.user.password')->assertJsonMissingPath('data.user.remember_token');

        $user = User::firstOrFail();
        $this->assertTrue(Hash::check('secure-password123', $user->password));
        $this->assertNotSame(900, $user->id);
        $this->assertNull($user->email_verified_at);
        $this->assertNotNull($user->tokens()->first()->expires_at);
        $token = $response->json('data.token');
        $this->assertNotSame(explode('|', $token)[1], $user->tokens()->first()->token);
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
    }

    public function test_login_and_logout_revoke_only_the_current_token(): void
    {
        $user = User::factory()->create(['password' => 'secure-password123']);
        $otherToken = $user->createToken('other')->plainTextToken;
        $token = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'secure-password123'])
            ->assertOk()->json('data.token');

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->getJson('/api/v1/auth/me')->assertOk();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_invalid_credentials_and_expired_tokens_return_401(): void
    {
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'incorrect'])->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.com', 'password' => 'incorrect'])->assertUnauthorized();
        $token = $user->createToken('expired', ['*'], now()->subMinute())->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_registration_validation_and_duplicate_email_return_422(): void
    {
        $this->postJson('/api/v1/auth/register', [])->assertUnprocessable()->assertJsonValidationErrors(['name', 'email', 'password']);
        $user = User::factory()->create();
        $this->postJson('/api/v1/auth/register', ['name' => 'Cook', 'email' => $user->email, 'password' => 'short', 'password_confirmation' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_every_protected_endpoint_requires_authentication_even_without_accept_header(): void
    {
        foreach ([['GET', 'auth/me'], ['POST', 'auth/logout'], ['GET', 'items'], ['POST', 'items'], ['GET', 'items/1'], ['PUT', 'items/1'], ['DELETE', 'items/1'], ['POST', 'inventory-days'], ['POST', 'inventory-days/2026-09-09/movements'], ['POST', 'inventory-days/2026-09-09/close'], ['GET', 'inventory/history'], ['GET', 'inventory/daily-summary']] as [$method, $path]) {
            $this->call($method, '/api/v1/'.$path)->assertUnauthorized()->assertJsonStructure(['message', 'errors']);
        }
    }

    public function test_login_is_rate_limited_and_malformed_email_does_not_cause_500(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => ['bad'], 'password' => 'bad'])->assertUnprocessable();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'absent@example.com', 'password' => 'bad'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'absent@example.com', 'password' => 'bad'])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_multibyte_passwords_over_bcrypt_byte_limit_return_422(): void
    {
        $password = str_repeat('é', 40).'123';
        $this->postJson('/api/v1/auth/register', ['name' => 'Cook', 'email' => 'cook@example.com', 'password' => $password, 'password_confirmation' => $password])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/auth/register', ['name' => 'Cook', 'email' => 'cook@example.com', 'password' => ['bad'], 'password_confirmation' => ['bad']])
            ->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->assertDatabaseCount('users', 0);
    }
}
