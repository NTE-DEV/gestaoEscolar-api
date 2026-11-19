<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\SchoolGroup;
use App\Models\SchoolGroupMember;
use App\Services\Auth\PortalSemedAuthService;
use App\Services\Auth\SchoolGroupAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class SchoolGroupController extends Controller
{
    #[OA\Get(
        path: '/api/v1/schools',
        tags: ['School Groups'],
        summary: 'Listar escolas oficiais via Portal SEMED API',
        description: 'Consulta e cacheia (5 minutos) a listagem pública de unidades escolares do Portal SEMED para vínculo com school_uid.',
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Termo de busca por nome, INEP ou região', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número da página', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Itens por página (1 a 200)', schema: new OA\Schema(type: 'integer', default: 150, minimum: 1, maximum: 200)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista de unidades escolares retornada pelo Portal SEMED'),
            new OA\Response(response: 503, description: 'Portal SEMED indisponível'),
        ]
    )]
    public function schools(Request $request, PortalSemedAuthService $portalAuth): JsonResponse
    {
        $search = $request->query('search');
        $page = max(1, $request->integer('page', 1));
        $perPage = min(max(1, $request->integer('per_page', 150)), 200);

        $result = $portalAuth->fetchPublicSchools($search, $page, $perPage);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Erro ao consultar escolas no Portal SEMED.',
                'data' => [],
            ], $result['status'] ?? 503);
        }

        return response()->json([
            'success' => true,
            'data' => $result['data'],
            'meta' => $result['meta'] ?? [],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/school-groups',
        tags: ['School Groups'],
        summary: 'Listar grupos escolares cadastrados',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Filtrar por school_uid, nome ou INEP', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Itens por página (5 a 100)', schema: new OA\Schema(type: 'integer', default: 20, minimum: 5, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada de grupos escolares'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = SchoolGroup::query()->withCount('members');

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('school_name', 'like', "%{$search}%")
                    ->orWhere('school_uid', 'like', "%{$search}%")
                    ->orWhere('school_inep', 'like', "%{$search}%");
            });
        }

        $perPage = min(max($request->integer('per_page', 20), 5), 100);

        return response()->json([
            'success' => true,
            'data' => $query->orderBy('school_name')->paginate($perPage),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/school-groups/{school_uid}/members',
        tags: ['School Groups'],
        summary: 'Listar membros vinculados a uma unidade escolar',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'school_uid', in: 'path', required: true, description: 'UID da unidade escolar', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Grupo e lista de membros vinculados'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function members(string $schoolUid): JsonResponse
    {
        $group = SchoolGroup::with(['members' => fn ($q) => $q->orderBy('name')->orderBy('email')])
            ->where('school_uid', $schoolUid)
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'school_group' => $group ? $group->only(['id', 'school_uid', 'school_name', 'school_inep', 'active', 'last_synced_at']) : null,
                'members' => $group?->members ?? [],
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/school-groups/{school_uid}/members',
        tags: ['School Groups'],
        summary: 'Vincular ou atualizar membro no grupo de uma escola',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'school_uid', in: 'path', required: true, description: 'UID da unidade escolar', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'school_name', type: 'string', nullable: true, example: 'UMEF Alger Ribeiro Bossois'),
                    new OA\Property(property: 'school_inep', type: 'string', nullable: true, example: '32037710'),
                    new OA\Property(property: 'name', type: 'string', nullable: true, example: 'Maria da Silva'),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true, example: 'diretor.escola@semed.vilavelha.es.gov.br'),
                    new OA\Property(property: 'cpf', type: 'string', nullable: true, example: '123.456.789-00'),
                    new OA\Property(property: 'portal_user_id', type: 'integer', nullable: true, example: 842),
                    new OA\Property(property: 'portal_user_uid', type: 'string', format: 'uuid', nullable: true),
                    new OA\Property(property: 'role', type: 'string', nullable: true, example: 'Gestor Escolar'),
                    new OA\Property(property: 'active', type: 'boolean', nullable: true, example: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Membro vinculado com sucesso'),
            new OA\Response(response: 200, description: 'Membro atualizado no grupo'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
            new OA\Response(response: 422, description: 'Erro de validação'),
        ]
    )]
    public function storeMember(Request $request, string $schoolUid, SchoolGroupAuthService $groupAuth): JsonResponse
    {
        $validated = $request->validate([
            'school_name' => ['nullable', 'string', 'max:180'],
            'school_inep' => ['nullable', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:180'],
            'cpf' => ['nullable', 'string', 'max:20'],
            'portal_user_id' => ['nullable', 'integer', 'min:1'],
            'portal_user_uid' => ['nullable', 'uuid'],
            'role' => ['nullable', 'string', 'max:80'],
            'active' => ['nullable', 'boolean'],
        ]);

        $email = SchoolGroupMember::normalizeEmail($validated['email'] ?? null);
        $cpf = $this->validateNormalizedCpf($validated['cpf'] ?? null);

        if ($email === null && $cpf === null) {
            throw ValidationException::withMessages([
                'email' => ['Informe ao menos um e-mail ou CPF para vincular ao grupo escolar.'],
            ]);
        }

        $group = SchoolGroup::firstOrCreate(
            ['school_uid' => $schoolUid],
            [
                'school_name' => $validated['school_name'] ?? null,
                'school_inep' => $validated['school_inep'] ?? null,
                'active' => true,
            ]
        );

        $groupUpdates = array_filter([
            'school_name' => $validated['school_name'] ?? null,
            'school_inep' => $validated['school_inep'] ?? null,
        ], fn ($value) => $value !== null && trim((string) $value) !== '');
        if ($groupUpdates !== []) {
            $group->update($groupUpdates);
        }

        $existing = $this->resolveExistingMemberForUpsert($group->id, $email, $cpf);

        $attributes = [
            'school_group_id' => $group->id,
            'school_uid' => $schoolUid,
            'portal_user_id' => $validated['portal_user_id'] ?? $existing?->portal_user_id,
            'portal_user_uid' => $validated['portal_user_uid'] ?? $existing?->portal_user_uid,
            'name' => $validated['name'] ?? $existing?->name,
            'email' => $email ?? $existing?->email,
            'cpf' => $cpf ?? $existing?->cpf,
            'role' => trim((string) ($validated['role'] ?? $existing?->role ?? 'Membro Escolar')) ?: 'Membro Escolar',
            'active' => array_key_exists('active', $validated) ? (bool) $validated['active'] : ($existing?->active ?? true),
        ];

        $isNew = $existing === null;
        if ($existing) {
            $existing->update($attributes);
            $member = $existing->fresh();
        } else {
            $member = SchoolGroupMember::create($attributes);
        }

        $groupAuth->recordAudit(
            $request,
            $isNew ? 'CREATE' : 'UPDATE',
            ($isNew ? 'Adicionou' : 'Atualizou') . " membro ({$member->email}{$member->cpf}) no grupo da escola {$schoolUid}.",
            'SchoolGroupMember',
            (string) $member->id,
            user: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => $isNew ? 'Membro vinculado ao grupo escolar.' : 'Membro atualizado no grupo escolar.',
            'data' => $member,
        ], $isNew ? 201 : 200);
    }

    #[OA\Patch(
        path: '/api/v1/school-groups/{school_uid}/members/{member_id}',
        tags: ['School Groups'],
        summary: 'Atualizar membro de um grupo escolar',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'school_uid', in: 'path', required: true, description: 'UID da unidade escolar', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'member_id', in: 'path', required: true, description: 'ID do membro no grupo', schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', nullable: true),
                    new OA\Property(property: 'email', type: 'string', format: 'email', nullable: true),
                    new OA\Property(property: 'cpf', type: 'string', nullable: true),
                    new OA\Property(property: 'role', type: 'string', nullable: true),
                    new OA\Property(property: 'active', type: 'boolean', nullable: true),
                    new OA\Property(property: 'portal_user_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'portal_user_uid', type: 'string', format: 'uuid', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Membro atualizado com sucesso'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
            new OA\Response(response: 404, description: 'Membro não encontrado'),
            new OA\Response(response: 422, description: 'Erro de validação'),
        ]
    )]
    public function updateMember(
        Request $request,
        string $schoolUid,
        int $memberId,
        SchoolGroupAuthService $groupAuth
    ): JsonResponse {
        $member = SchoolGroupMember::where('school_uid', $schoolUid)->findOrFail($memberId);

        $validated = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'cpf' => ['sometimes', 'nullable', 'string', 'max:20'],
            'portal_user_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'portal_user_uid' => ['sometimes', 'nullable', 'uuid'],
            'role' => ['sometimes', 'string', 'max:80'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $email = array_key_exists('email', $validated)
            ? SchoolGroupMember::normalizeEmail($validated['email'])
            : $member->email;
        $cpf = array_key_exists('cpf', $validated)
            ? $this->validateNormalizedCpf($validated['cpf'])
            : $member->cpf;

        if ($email === null && $cpf === null) {
            throw ValidationException::withMessages([
                'email' => ['O membro precisa manter ao menos um e-mail ou CPF cadastrado.'],
            ]);
        }

        if ($email !== null && SchoolGroupMember::where('school_group_id', $member->school_group_id)->where('email', $email)->where('id', '!=', $member->id)->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Já existe outro membro com este e-mail neste grupo escolar.'],
            ]);
        }

        if ($cpf !== null && SchoolGroupMember::where('school_group_id', $member->school_group_id)->where('cpf', $cpf)->where('id', '!=', $member->id)->exists()) {
            throw ValidationException::withMessages([
                'cpf' => ['Já existe outro membro com este CPF neste grupo escolar.'],
            ]);
        }

        $member->update($validated);

        $groupAuth->recordAudit(
            $request,
            'UPDATE',
            "Atualizou membro #{$member->id} do grupo da escola {$schoolUid}.",
            'SchoolGroupMember',
            (string) $member->id,
            user: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Membro atualizado com sucesso.',
            'data' => $member->fresh(),
        ]);
    }

    #[OA\Delete(
        path: '/api/v1/school-groups/{school_uid}/members/{member_id}',
        tags: ['School Groups'],
        summary: 'Remover membro de um grupo escolar',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'school_uid', in: 'path', required: true, description: 'UID da unidade escolar', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'member_id', in: 'path', required: true, description: 'ID do membro no grupo', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Membro removido com sucesso'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
            new OA\Response(response: 404, description: 'Membro não encontrado'),
        ]
    )]
    public function destroyMember(
        Request $request,
        string $schoolUid,
        int $memberId,
        SchoolGroupAuthService $groupAuth
    ): JsonResponse {
        $member = SchoolGroupMember::where('school_uid', $schoolUid)->findOrFail($memberId);
        $identifier = $member->email ?? $member->cpf ?? (string) $member->id;
        $member->delete();

        $groupAuth->recordAudit(
            $request,
            'DELETE',
            "Removeu membro #{$memberId} ({$identifier}) do grupo da escola {$schoolUid}.",
            'SchoolGroupMember',
            (string) $memberId,
            user: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Membro removido do grupo escolar.',
        ]);
    }

    #[OA\Post(
        path: '/api/v1/school-groups/{school_uid}/sync',
        tags: ['School Groups'],
        summary: 'Sincronizar membros do grupo escolar via Portal SEMED',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'school_uid', in: 'path', required: true, description: 'UID da unidade escolar', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'school_name', type: 'string', nullable: true, example: 'UMEF Alger Ribeiro Bossois'),
                    new OA\Property(property: 'portal_token', type: 'string', nullable: true, description: 'Token Bearer opcional para sobrescrever PORTAL_API_TOKEN'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Sincronização realizada com sucesso'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
            new OA\Response(response: 422, description: 'Token do Portal não configurado ou dados inválidos'),
            new OA\Response(response: 503, description: 'Falha ao comunicar com o Portal SEMED'),
        ]
    )]
    public function sync(Request $request, string $schoolUid, SchoolGroupAuthService $groupAuth): JsonResponse
    {
        $validated = $request->validate([
            'school_name' => ['nullable', 'string', 'max:180'],
            'portal_token' => ['nullable', 'string'],
        ]);

        $result = $groupAuth->syncSchoolGroupFromPortal(
            $schoolUid,
            $validated['school_name'] ?? null,
            $validated['portal_token'] ?? null
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Falha ao sincronizar grupo escolar.',
            ], $result['status'] ?? 502);
        }

        $groupAuth->recordAudit(
            $request,
            'SYNC',
            "Sincronizou {$result['synced_count']} membro(s) do grupo escolar {$schoolUid} via Portal Semed.",
            'SchoolGroup',
            $schoolUid,
            user: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Grupo escolar sincronizado com sucesso.',
            'data' => [
                'group' => $result['group'],
                'synced_count' => $result['synced_count'],
            ],
        ]);
    }

    private function validateNormalizedCpf(?string $rawCpf): ?string
    {
        if ($rawCpf === null || trim($rawCpf) === '') {
            return null;
        }

        $normalized = SchoolGroupMember::normalizeCpf($rawCpf);
        if ($normalized === null || strlen($normalized) !== 11) {
            throw ValidationException::withMessages([
                'cpf' => ['Informe um CPF válido com 11 dígitos.'],
            ]);
        }

        return $normalized;
    }

    private function resolveExistingMemberForUpsert(int $groupId, ?string $email, ?string $cpf): ?SchoolGroupMember
    {
        $byEmail = $email !== null
            ? SchoolGroupMember::where('school_group_id', $groupId)->where('email', $email)->first()
            : null;
        $byCpf = $cpf !== null
            ? SchoolGroupMember::where('school_group_id', $groupId)->where('cpf', $cpf)->first()
            : null;

        if ($byEmail && $byCpf && $byEmail->id !== $byCpf->id) {
            throw ValidationException::withMessages([
                'cpf' => ['E-mail e CPF informados pertencem a membros diferentes neste grupo escolar.'],
            ]);
        }

        return $byEmail ?? $byCpf;
    }
}
