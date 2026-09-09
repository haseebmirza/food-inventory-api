<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\AuthRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;

class AuthController extends Controller
{
    public function __construct(private AuthService $auth) {}

    public function register(AuthRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->safe()->only(['name', 'email', 'password']));

        return $this->tokenResponse($result, 201);
    }

    public function login(AuthRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->validated());

        return $this->tokenResponse($result);
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout($request->user());

        return response()->noContent();
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    /**
     * @param  array{user: User, token: string, expires_at: Carbon}  $result
     */
    private function tokenResponse(array $result, int $status = 200): JsonResponse
    {
        return response()->json([
            'data' => [
                'user' => new UserResource($result['user']),
                'token' => $result['token'],
                'token_type' => 'Bearer',
                'expires_at' => $result['expires_at'],
            ],
        ], $status)->header('Cache-Control', 'no-store');
    }
}
