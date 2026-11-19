<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GroupLoginRequest;
use App\Http\Requests\Auth\GroupVerifyRequest;
use App\Models\SchoolGroup;
use App\Services\Auth\PortalSemedAuthService;
use App\Services\Auth\SchoolGroupAuthService;
use Illuminate\Http\JsonResponse;
use OpenApi\Attributes as OA;

class GroupAuthController extends Controller
{
    #[OA\Post(
        path: '/api/v1/auth/group-login',
        tags: ['Auth (Group)'],
        summary: 'Autenticar usuário por grupo escolar (2 passos)',
        description: 'Passo 1: valida vínculo ativo do e-mail ou CPF no grupo vinculado ao school_uid. Passo 2: autentica no Portal SEMED e cria sessão Sanctum.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['school_uid', 'login', 'password'],
                properties: [
                    new OA\Property(property: 'school_uid', type: 'string', format: 'uuid', example: '9d8a12b4-4e56-4c89-a123-7890abcdef12'),
                    new OA\Property(property: 'login', type: 'string', maxLength: 150, example: 'diretor.escola@semed.vilavelha.es.gov.br', description: 'E-mail ou CPF do usuário'),
                    new OA\Property(property: 'password', type: 'string', format: 'password', maxLength: 150, example: '********'),
                    new OA\Property(property: 'device_id', type: 'string', maxLength: 120, nullable: true, example: 'web-escolar'),
                ]
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Autenticação realizada e sessão criada',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Autenticação por grupo realizada com sucesso.'),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'token', type: 'string', example: '12|xYz987654321AbCdEfGhIjKlMnOpQrStUv'),
                                new OA\Property(property: 'token_type', type: 'string', example: 'Bearer'),
                                new OA\Property(property: 'abilities', type: 'array', items: new OA\Items(type: 'string', example: 'school:9d8a12b4-4e56-4c89-a123-7890abcdef12')),
                                new OA\Property(property: 'user', type: 'object'),
                                new OA\Property(property: 'school_group', type: 'object'),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Credenciais inválidas no Portal SEMED (Passo 2)'),
            new OA\Response(response: 403, description: 'Usuário sem vínculo ativo no grupo da escola (Passo 1)'),
            new OA\Response(response: 422, description: 'Erro de validação'),
            new OA\Response(response: 503, description: 'Portal SEMED indisponível'),
        ]
    )]
    public function groupLogin(
        GroupLoginRequest $request,
        SchoolGroupAuthService $groupAuth,
        PortalSemedAuthService $portalAuth
    ): JsonResponse {
        $validated = $request->validated();
        $schoolUid = $validated['school_uid'];
        $rawLogin = $validated['login'];
        $normalized = $groupAuth->normalizeLogin($rawLogin);

        // Passo 1: Fail-Fast validando vínculo ativo no grupo da unidade escolar antes de chamar o Portal SEMED.
        $member = $groupAuth->verifyGroupMembership($schoolUid, $rawLogin);

        if (! $member) {
            $groupAuth->recordAudit(
                $request,
                'LOGIN_GROUP_DENIED',
                "Tentativa de login negada no Passo 1 (sem vínculo ativo para school_uid {$schoolUid}).",
                'SchoolGroup',
                $schoolUid,
                userName: $normalized['value'] ?: $rawLogin,
                userEmail: $normalized['type'] === 'email' ? $normalized['value'] : null,
            );

            return response()->json([
                'success' => false,
                'message' => 'Usuário não possui vínculo ativo no grupo da unidade escolar informada.',
                'step' => 'school_group_validation',
            ], 403);
        }

        // Passo 2: Autentica credenciais no portalSemed-api (origin: portal).
        $portalLogin = $normalized['value'] !== '' ? $normalized['value'] : $rawLogin;
        $result = $portalAuth->authenticateForSchoolGroup(
            $portalLogin,
            $validated['password'],
            $validated['device_id'] ?? null
        );

        if (! $result['ok']) {
            $groupAuth->recordAudit(
                $request,
                'LOGIN_GROUP_FAILED',
                "Falha de autenticação no Portal Semed (Passo 2) para {$schoolUid}: {$result['message']}",
                'SchoolGroup',
                $schoolUid,
                userName: $member->name ?: $portalLogin,
                userEmail: $member->email,
                userRole: $member->role,
            );

            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'step' => 'portal_semed_authentication',
            ], $result['status']);
        }

        // Verificação anti-spoofing entre membro encontrado no Passo 1 e usuário retornado pelo Portal Semed.
        if (! $groupAuth->matchesPortalUser($member, $result['user'])) {
            $groupAuth->recordAudit(
                $request,
                'LOGIN_GROUP_DENIED',
                "Divergência de identidade entre membro do grupo ({$schoolUid}) e usuário retornado pelo Portal Semed.",
                'SchoolGroup',
                $schoolUid,
                userName: $member->name ?: $portalLogin,
                userEmail: $member->email,
                userRole: $member->role,
            );

            return response()->json([
                'success' => false,
                'message' => 'Identidade autenticada não corresponde ao membro vinculado ao grupo escolar.',
                'step' => 'school_group_validation',
            ], 403);
        }

        $session = $groupAuth->createSessionForMember(
            $member,
            $result['user'],
            $result['roles'],
            $portalLogin,
            $validated['device_id'] ?? null
        );

        $groupAuth->recordAudit(
            $request,
            'LOGIN_GROUP',
            "Autenticação por grupo escolar realizada ({$schoolUid} — papel {$member->role}).",
            'SchoolGroup',
            $schoolUid,
            user: $session['user'],
        );

        return response()->json([
            'success' => true,
            'message' => 'Autenticação por grupo realizada com sucesso.',
            'data' => [
                'token' => $session['token'],
                'token_type' => 'Bearer',
                'abilities' => $session['abilities'],
                'user' => [
                    'id' => $session['user']->id,
                    'portal_user_id' => $session['user']->portal_user_id,
                    'name' => $session['user']->name,
                    'email' => $session['user']->email,
                    'cpf' => $session['user']->cpf,
                    'roles' => $session['user']->roles,
                ],
                'school_group' => [
                    'id' => $member->group->id,
                    'school_uid' => $member->school_uid,
                    'school_name' => $member->group->school_name,
                    'member_role' => $member->role,
                ],
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/auth/group-verify',
        tags: ['Auth (Group)'],
        summary: 'Validar vínculo de usuário em grupo escolar (Passo 1)',
        description: 'Verifica sem senha se um e-mail ou CPF está vinculado e ativo no grupo da unidade escolar.',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['school_uid', 'login'],
                properties: [
                    new OA\Property(property: 'school_uid', type: 'string', format: 'uuid', example: '9d8a12b4-4e56-4c89-a123-7890abcdef12'),
                    new OA\Property(property: 'login', type: 'string', maxLength: 150, example: '123.456.789-00', description: 'E-mail ou CPF do usuário'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Resultado da verificação de vínculo escolar'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
            new OA\Response(response: 422, description: 'Erro de validação'),
        ]
    )]
    public function groupVerify(GroupVerifyRequest $request, SchoolGroupAuthService $groupAuth): JsonResponse
    {
        $validated = $request->validated();
        $schoolUid = $validated['school_uid'];
        $member = $groupAuth->verifyGroupMembership($schoolUid, $validated['login']);

        if (! $member) {
            return response()->json([
                'success' => true,
                'data' => [
                    'authorized' => false,
                    'school_uid' => $schoolUid,
                    'school_name' => SchoolGroup::where('school_uid', $schoolUid)->value('school_name'),
                    'member' => null,
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'authorized' => true,
                'school_uid' => $member->school_uid,
                'school_name' => $member->group?->school_name,
                'member' => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'cpf' => $member->cpf,
                    'role' => $member->role,
                ],
            ],
        ]);
    }
}
