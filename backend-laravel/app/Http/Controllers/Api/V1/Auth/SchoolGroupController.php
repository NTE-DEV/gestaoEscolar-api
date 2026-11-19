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
        summary: 'Listar unidades (escolas e setores) via Portal SEMED API',
        description: 'Consulta e cacheia a listagem unificada de unidades públicas (escolas e setores administrativos da SEMED) para seleção e vínculo com unit_uid/school_uid.',
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Termo de busca por nome, código/INEP ou e-mail', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'type', in: 'query', required: false, description: 'Filtrar por tipo: all (padrão, escolas e setores), school (somente escolas) ou sector (somente setores)', schema: new OA\Schema(type: 'string', enum: ['all', 'school', 'sector'], default: 'all')),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número da página', schema: new OA\Schema(type: 'integer', default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Itens por página (1 a 250)', schema: new OA\Schema(type: 'integer', default: 150, minimum: 1, maximum: 250)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista unificada de escolas e setores retornada pelo Portal SEMED'),
            new OA\Response(response: 503, description: 'Portal SEMED indisponível'),
        ]
    )]
    public function schools(Request $request, PortalSemedAuthService $portalAuth): JsonResponse
    {
        $search = $request->query('search');
        $type = $request->query('type', 'all');
        $page = max(1, $request->integer('page', 1));
        $perPage = min(max(1, $request->integer('per_page', 150)), 250);

        $result = $portalAuth->fetchPublicUnits($search, $type, $page, $perPage);

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Erro ao consultar unidades no Portal SEMED.',
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
        summary: 'Listar grupos cadastrados (escolas e setores)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Filtrar por UID, nome ou INEP/sigla', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'unit_type', in: 'query', required: false, description: 'Filtrar por unit_type: school ou sector', schema: new OA\Schema(type: 'string', enum: ['school', 'sector'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Itens por página (5 a 100)', schema: new OA\Schema(type: 'integer', default: 20, minimum: 5, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada de grupos'),
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

        if ($unitType = trim((string) $request->input('unit_type'))) {
            $query->where('unit_type', $unitType);
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
        summary: 'Listar membros vinculados a uma unidade (escola ou setor)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'school_uid', in: 'path', required: true, description: 'UID da unidade (escola ou setor)', schema: new OA\Schema(type: 'string', format: 'uuid')),
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
                'unit_group' => $group ? $group->only(['id', 'school_uid', 'school_name', 'unit_type', 'school_inep', 'active', 'last_synced_at']) : null,
                'school_group' => $group ? $group->only(['id', 'school_uid', 'school_name', 'unit_type', 'school_inep', 'active', 'last_synced_at']) : null,
                'members' => $group?->members ?? [],
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/school-groups/{school_uid}/members',
        tags: ['School Groups'],
        summary: 'Vincular ou atualizar membro no grupo de uma unidade',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'school_uid', in: 'path', required: true, description: 'UID da unidade (escola ou setor)', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'school_name', type: 'string', nullable: true, example: 'UMEF Alger Ribeiro Bossois'),
                    new OA\Property(property: 'unit_type', type: 'string', nullable: true, enum: ['school', 'sector'], example: 'school'),
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
            'unit_type' => ['nullable', 'string', 'in:school,sector', 'max:30'],
            'school_inep' => ['nullable', 'string', 'max:30'],
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
                'email' => ['Informe ao menos um e-mail ou CPF para vincular ao grupo da unidade.'],
            ]);
        }

        $group = SchoolGroup::firstOrCreate(
            ['school_uid' => $schoolUid],
            [
                'school_name' => $validated['school_name'] ?? null,
                'unit_type' => $validated['unit_type'] ?? 'school',
                'school_inep' => $validated['school_inep'] ?? null,
                'active' => true,
            ]
        );

        $groupUpdates = array_filter([
            'school_name' => $validated['school_name'] ?? null,
            'unit_type' => $validated['unit_type'] ?? null,
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
            'role' => trim((string) ($validated['role'] ?? $existing?->role ?? 'Membro')) ?: 'Membro',
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
            ($isNew ? 'Adicionou' : 'Atualizou') . " membro ({$member->email}{$member->cpf}) no grupo da unidade {$schoolUid}.",
            'SchoolGroupMember',
            (string) $member->id,
            user: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => $isNew ? 'Membro vinculado ao grupo.' : 'Membro atualizado no grupo.',
            'data' => $member,
        ], $isNew ? 201 : 200);
    }

    #[OA\Patch(
        path: '/api/v1/school-groups/{school_uid}/members/{member_id}',
        tags: ['School Groups'],
        summary: 'Atualizar membro de um grupo de unidade',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'school_uid', in: 'path', required: true, description: 'UID da unidade (escola ou setor)', schema: new OA\Schema(type: 'string', format: 'uuid')),
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
            new OA\Response(response: 200, description: 'Membro atualizado'),
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
        $member = SchoolGroupMember::where('id', $memberId)
            ->where('school_uid', $schoolUid)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:180'],
            'cpf' => ['nullable', 'string', 'max:20'],
            'role' => ['nullable', 'string', 'max:80'],
            'active' => ['nullable', 'boolean'],
            'portal_user_id' => ['nullable', 'integer', 'min:1'],
            'portal_user_uid' => ['nullable', 'uuid'],
        ]);

        $email = array_key_exists('email', $validated)
            ? SchoolGroupMember::normalizeEmail($validated['email'])
            : $member->email;

        $cpf = array_key_exists('cpf', $validated)
            ? $this->validateNormalizedCpf($validated['cpf'])
            : $member->cpf;

        if ($email === null && $cpf === null) {
            throw ValidationException::withMessages([
                'email' => ['O membro precisa possuir ao menos um e-mail ou CPF ativo.'],
            ]);
        }

        // Valida unicidade no grupo
        $duplicate = SchoolGroupMember::where('school_group_id', $member->school_group_id)
            ->where('id', '!=', $member->id)
            ->where(function ($q) use ($email, $cpf) {
                if ($email !== null) {
                    $q->orWhere('email', $email);
                }
                if ($cpf !== null) {
                    $q->orWhere('cpf', $cpf);
                }
            })
            ->first();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'email' => ['Já existe outro membro com este e-mail ou CPF cadastrado no grupo desta unidade.'],
            ]);
        }

        $attributes = array_filter([
            'name' => $validated['name'] ?? null,
            'email' => $email,
            'cpf' => $cpf,
            'role' => isset($validated['role']) ? trim($validated['role']) : null,
            'portal_user_id' => $validated['portal_user_id'] ?? null,
            'portal_user_uid' => $validated['portal_user_uid'] ?? null,
        ], fn ($val) => $val !== null);

        if (array_key_exists('active', $validated)) {
            $attributes['active'] = (bool) $validated['active'];
        }

        $member->update($attributes);

        $groupAuth->recordAudit(
            $request,
            'UPDATE',
            "Atualizou atributos do membro #{$member->id} no grupo da unidade {$schoolUid}.",
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
        summary: 'Remover membro de um grupo de unidade',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'school_uid', in: 'path', required: true, description: 'UID da unidade', schema: new OA\Schema(type: 'string', format: 'uuid')),
            new OA\Parameter(name: 'member_id', in: 'path', required: true, description: 'ID do membro no grupo', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Membro removido do grupo'),
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
        $member = SchoolGroupMember::where('id', $memberId)
            ->where('school_uid', $schoolUid)
            ->firstOrFail();

        $identifier = $member->email ?: $member->cpf ?: "#{$member->id}";
        $member->delete();

        $groupAuth->recordAudit(
            $request,
            'DELETE',
            "Removeu membro #{$memberId} ({$identifier}) do grupo da unidade {$schoolUid}.",
            'SchoolGroupMember',
            (string) $memberId,
            user: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Membro removido do grupo.',
        ]);
    }

    #[OA\Post(
        path: '/api/v1/school-groups/{school_uid}/sync',
        tags: ['School Groups'],
        summary: 'Sincronizar membros do grupo via Portal SEMED',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'school_uid', in: 'path', required: true, description: 'UID da unidade (escola ou setor)', schema: new OA\Schema(type: 'string', format: 'uuid')),
        ],
        requestBody: new OA\RequestBody(
            required: false,
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'school_name', type: 'string', nullable: true, example: 'UMEF Alger Ribeiro Bossois'),
                    new OA\Property(property: 'unit_type', type: 'string', nullable: true, enum: ['school', 'sector'], default: 'school'),
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
            'unit_type' => ['nullable', 'string', 'in:school,sector', 'max:30'],
            'portal_token' => ['nullable', 'string'],
        ]);

        $existingType = SchoolGroup::where('school_uid', $schoolUid)->value('unit_type');
        $unitType = $validated['unit_type'] ?? $existingType ?? 'school';

        $result = $groupAuth->syncSchoolGroupFromPortal(
            $schoolUid,
            $validated['school_name'] ?? null,
            $validated['portal_token'] ?? null,
            $unitType
        );

        if (! ($result['ok'] ?? false)) {
            return response()->json([
                'success' => false,
                'message' => $result['message'] ?? 'Falha ao sincronizar grupo.',
            ], $result['status'] ?? 502);
        }

        $groupAuth->recordAudit(
            $request,
            'SYNC',
            "Sincronizou {$result['synced_count']} membro(s) do grupo {$schoolUid} ({$unitType}) via Portal Semed.",
            'SchoolGroup',
            $schoolUid,
            user: $request->user(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Grupo sincronizado com sucesso.',
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
                'cpf' => ['E-mail e CPF informados pertencem a membros diferentes neste grupo.'],
            ]);
        }

        return $byEmail ?? $byCpf;
    }
}
