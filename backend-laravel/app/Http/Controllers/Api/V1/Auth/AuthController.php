<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\PortalSemedAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(Request $request, PortalSemedAuthService $portalAuth): JsonResponse
    {
        $validated = $request->validate([
            'login' => ['required', 'string', 'max:150'],
            'password' => ['required', 'string', 'max:150'],
            'device_id' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $portalAuth->authenticate(
            $validated['login'],
            $validated['password'],
            $validated['device_id'] ?? null
        );

        if (! $result['ok']) {
            AuditLog::create([
                'user_name' => $validated['login'],
                'app_source' => 'gestao-escolar-admin',
                'action' => 'LOGIN_FAILED',
                'resource_type' => 'Auth',
                'description' => $result['message'],
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $result['message'],
            ], $result['status']);
        }

        $portalUser = $result['user'];
        $roles = $result['roles'];
        $email = $portalUser['email'] ?? ($validated['login'].'@semed.local');

        $user = User::updateOrCreate(
            ['portal_user_id' => $portalUser['id'] ?? null],
            [
                'name' => $portalUser['name'] ?? 'Administrador SEMED',
                'email' => $email,
                'cpf' => $portalUser['cpf'] ?? null,
                'roles' => $roles,
                'last_login_at' => now(),
            ]
        );

        $token = $user->createToken('gestao-escolar-admin')->plainTextToken;

        AuditLog::create([
            'user_id' => $user->id,
            'user_name' => $user->name,
            'user_email' => $user->email,
            'user_role' => implode(', ', $roles),
            'app_source' => 'gestao-escolar-admin',
            'action' => 'LOGIN',
            'resource_type' => 'Auth',
            'resource_id' => (string) $user->id,
            'description' => 'Autenticação realizada via Portal Semed API.',
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Login realizado com sucesso.',
            'data' => [
                'token' => $token,
                'user' => [
                    'id' => $user->id,
                    'portal_user_id' => $user->portal_user_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'cpf' => $user->cpf,
                    'roles' => $user->roles,
                ],
            ],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'portal_user_id' => $user->portal_user_id,
                'name' => $user->name,
                'email' => $user->email,
                'cpf' => $user->cpf,
                'roles' => $user->roles,
                'last_login_at' => $user->last_login_at,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            AuditLog::create([
                'user_id' => $user->id,
                'user_name' => $user->name,
                'user_email' => $user->email,
                'user_role' => implode(', ', $user->roles ?? []),
                'app_source' => 'gestao-escolar-admin',
                'action' => 'LOGOUT',
                'resource_type' => 'Auth',
                'resource_id' => (string) $user->id,
                'description' => 'Sessão encerrada pelo usuário.',
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
                'created_at' => now(),
            ]);

            $user->currentAccessToken()?->delete();
        }

        return response()->json([
            'success' => true,
            'message' => 'Logout realizado com sucesso.',
        ]);
    }
}
