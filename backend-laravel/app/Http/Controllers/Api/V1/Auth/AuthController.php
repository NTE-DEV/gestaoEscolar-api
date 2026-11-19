<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auth\PortalSemedAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AuthController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/login',
        tags: ['Auth'],
        summary: 'Autenticar usuário via Portal SEMED API',
        description: 'Valida credenciais no Portal SEMED, sincroniza o usuário local e gera um token Laravel Sanctum.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['login', 'password'],
                properties: [
                    new OA\Property(property: 'login', type: 'string', maxLength: 150, example: 'usuario@semed.vilavelha.es.gov.br'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', maxLength: 150, example: '********'),
                    new OA\Property(property: 'device_id', type: 'string', maxLength: 120, nullable: true, example: 'web-admin-browser'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Login realizado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Login realizado com sucesso.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'token', type: 'string', example: '1|aBcDeFgHiJkLmNoPqRsTuVwXyZ'),
                                new OA\Property(
                                    property: 'user',
                                    type: 'object',
                                    properties: [
                                        new OA\Property(property: 'id', type: 'integer', example: 1),
                                        new OA\Property(property: 'portal_user_id', type: 'string', nullable: true, example: '42'),
                                        new OA\Property(property: 'name', type: 'string', example: 'Administrador SEMED'),
                                        new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@semed.vilavelha.es.gov.br'),
                                        new OA\Property(property: 'cpf', type: 'string', nullable: true, example: '00000000000'),
                                        new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string', example: 'Master')),
                                    ]
                                ),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Credenciais inválidas'),
            new OA\Response(response: 422, description: 'Erro de validação'),
        ]
    )]
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

    #[OA\Get(
        path: '/api/v1/auth/me',
        tags: ['Auth'],
        summary: 'Obter dados do usuário autenticado',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Dados do usuário autenticado',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'id', type: 'integer', example: 1),
                                new OA\Property(property: 'portal_user_id', type: 'string', nullable: true, example: '42'),
                                new OA\Property(property: 'name', type: 'string', example: 'Administrador SEMED'),
                                new OA\Property(property: 'email', type: 'string', format: 'email', example: 'admin@semed.vilavelha.es.gov.br'),
                                new OA\Property(property: 'cpf', type: 'string', nullable: true, example: '00000000000'),
                                new OA\Property(property: 'roles', type: 'array', items: new OA\Items(type: 'string', example: 'Master')),
                                new OA\Property(property: 'last_login_at', type: 'string', format: 'date-time', nullable: true),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
        ]
    )]
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

    #[OA\Post(
        path: '/api/v1/auth/logout',
        tags: ['Auth'],
        summary: 'Encerrar sessão e revogar token atual',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Logout realizado com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Logout realizado com sucesso.'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
        ]
    )]
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
