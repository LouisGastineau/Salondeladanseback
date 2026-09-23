<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Services\AuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, AuthService $service): JsonResponse
    {
        $result = $service->register($request->validated(), $request->file('photo'));

        return response()->json(['data' => ['user' => new UserResource($result['user']), 'token' => $result['token'], 'token_type' => 'Bearer']], 201);
    }

    public function login(LoginRequest $request, AuthService $service): JsonResponse
    {
        $result = $service->login($request->validated());

        return response()->json(['data' => ['user' => new UserResource($result['user']), 'token' => $result['token'], 'token_type' => 'Bearer']]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request, AuthService $service): Response
    {
        $service->logout($request->user());

        return response()->noContent();
    }
}
