<?php

namespace App\Services\Auth;

use App\Models\AuditLog;
use App\Models\SchoolGroup;
use App\Models\SchoolGroupMember;
use App\Models\User;
use Illuminate\Http\Request;

class SchoolGroupAuthService
{
    public function __construct(
        private readonly PortalSemedAuthService $portalAuth
    ) {}

    /**
     * Passo 1: Normaliza login (e-mail ou CPF) e verifica vínculo ativo no grupo da escola.
     */
    public function verifyGroupMembership(string $schoolUid, string $rawLogin): ?SchoolGroupMember
    {
        $normalized = $this->normalizeLogin($rawLogin);
        if ($normalized['value'] === '') {
            return null;
        }

        $member = $this->findActiveMember($schoolUid, $normalized);

        if (
            ! $member
            && SchoolGroup::where('school_uid', $schoolUid)->doesntExist()
            && config('services.portal_semed.group_auto_sync')
            && ! empty(config('services.portal_semed.api_token'))
        ) {
            $sync = $this->syncSchoolGroupFromPortal($schoolUid);
            if ($sync['ok'] ?? false) {
                $member = $this->findActiveMember($schoolUid, $normalized);
            }
        }

        return $member;
    }

    /**
     * Identifica se o login é e-mail (lowercase) ou CPF (exatamente 11 dígitos).
     *
     * @return array{type: 'email'|'cpf', value: string}
     */
    public function normalizeLogin(string $rawLogin): array
    {
        $trimmed = trim($rawLogin);

        if (filter_var($trimmed, FILTER_VALIDATE_EMAIL)) {
            return [
                'type' => 'email',
                'value' => SchoolGroupMember::normalizeEmail($trimmed) ?? '',
            ];
        }

        $digits = SchoolGroupMember::normalizeCpf($trimmed) ?? '';

        return [
            'type' => 'cpf',
            'value' => strlen($digits) === 11 ? $digits : '',
        ];
    }

    /**
     * Garante coerência entre o membro encontrado no Passo 1 e o usuário autenticado no Passo 2.
     */
    public function matchesPortalUser(SchoolGroupMember $member, array $portalUser): bool
    {
        if ($member->portal_user_id && ! empty($portalUser['id'])) {
            return (int) $member->portal_user_id === (int) $portalUser['id'];
        }

        if ($member->portal_user_uid && ! empty($portalUser['uid'])) {
            return strcasecmp($member->portal_user_uid, (string) $portalUser['uid']) === 0;
        }

        $portalEmail = SchoolGroupMember::normalizeEmail($portalUser['email'] ?? null);
        $portalCpf = SchoolGroupMember::normalizeCpf($portalUser['cpf'] ?? null);

        if ($member->email && $portalEmail !== null && $member->email === $portalEmail) {
            return true;
        }

        if ($member->cpf && $portalCpf !== null && $member->cpf === $portalCpf) {
            return true;
        }

        return false;
    }

    /**
     * Cria ou atualiza o usuário local e emite token Sanctum escopado ao grupo escolar.
     */
    public function createSessionForMember(
        SchoolGroupMember $member,
        array $portalUser,
        array $roles,
        string $loginUsed,
        ?string $deviceId = null
    ): array {
        $portalUserId = $portalUser['id'] ?? $member->portal_user_id;
        $email = $portalUser['email'] ?? $member->email ?? ($loginUsed . '@semed.local');
        $cpf = SchoolGroupMember::normalizeCpf($portalUser['cpf'] ?? $member->cpf ?? null);

        $user = User::updateOrCreate(
            $portalUserId ? ['portal_user_id' => $portalUserId] : ['email' => $email],
            [
                'portal_user_id' => $portalUserId,
                'name' => $portalUser['name'] ?? $member->name ?? 'Usuário Escolar SEMED',
                'email' => $email,
                'cpf' => $cpf,
                'roles' => $roles,
                'last_login_at' => now(),
            ]
        );

        // Completa identificadores ausentes no membro pré-cadastrado sem sobrescrever vínculos diferentes.
        $updates = ['local_user_id' => $user->id];
        if (! $member->portal_user_id && ! empty($portalUser['id'])) {
            $updates['portal_user_id'] = (int) $portalUser['id'];
        }
        if (! $member->portal_user_uid && ! empty($portalUser['uid'])) {
            $updates['portal_user_uid'] = (string) $portalUser['uid'];
        }
        if (! $member->name && $user->name) {
            $updates['name'] = $user->name;
        }
        $member->fill($updates)->save();

        $abilities = ['school:' . $member->school_uid, 'group-auth'];
        $tokenName = 'school-group:' . $member->school_uid . ($deviceId ? ':' . $deviceId : '');
        $token = $user->createToken($tokenName, $abilities)->plainTextToken;

        return [
            'token' => $token,
            'abilities' => $abilities,
            'user' => $user,
        ];
    }

    /**
     * Sincroniza os membros vinculados ao UID da escola a partir do Portal Semed.
     */
    public function syncSchoolGroupFromPortal(
        string $schoolUid,
        ?string $schoolName = null,
        ?string $bearerToken = null
    ): array {
        $portalResult = $this->portalAuth->fetchSchoolGroupMembersFromPortal($schoolUid, $bearerToken);

        if (! ($portalResult['ok'] ?? false)) {
            return $portalResult;
        }

        $groupAttributes = [
            'active' => true,
            'last_synced_at' => now(),
        ];
        if ($schoolName !== null && trim($schoolName) !== '') {
            $groupAttributes['school_name'] = trim($schoolName);
        }

        $group = SchoolGroup::updateOrCreate(
            ['school_uid' => $schoolUid],
            $groupAttributes
        );

        $syncedCount = 0;
        foreach ($portalResult['data'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $userPayload = is_array($entry['user'] ?? null) ? $entry['user'] : $entry;
            $portalUid = $userPayload['uid'] ?? $entry['uid_user'] ?? null;
            $portalId = isset($userPayload['id']) ? (int) $userPayload['id'] : null;
            $email = SchoolGroupMember::normalizeEmail($userPayload['email'] ?? null);
            $cpf = SchoolGroupMember::normalizeCpf($userPayload['cpf'] ?? null);

            if ($email === null && $cpf === null && empty($portalUid)) {
                continue;
            }

            $roleNames = $this->portalAuth->extractRoleNames($userPayload['roles'] ?? []);
            $memberRole = $roleNames[0] ?? ($entry['role'] ?? 'Membro Escolar');

            $existing = SchoolGroupMember::query()
                ->where('school_group_id', $group->id)
                ->where(function ($q) use ($portalUid, $email, $cpf) {
                    if (! empty($portalUid)) {
                        $q->orWhere('portal_user_uid', $portalUid);
                    }
                    if ($email !== null) {
                        $q->orWhere('email', $email);
                    }
                    if ($cpf !== null) {
                        $q->orWhere('cpf', $cpf);
                    }
                })
                ->first();

            $payload = [
                'school_group_id' => $group->id,
                'school_uid' => $schoolUid,
                'portal_user_id' => $portalId,
                'portal_user_uid' => $portalUid,
                'name' => $userPayload['name'] ?? null,
                'email' => $email,
                'cpf' => $cpf,
                'role' => $memberRole,
                'active' => (bool) ($entry['active'] ?? true),
            ];

            if ($existing) {
                $existing->update($payload);
            } else {
                SchoolGroupMember::create($payload);
            }

            $syncedCount++;
        }

        return [
            'ok' => true,
            'status' => 200,
            'group' => $group->fresh('members'),
            'synced_count' => $syncedCount,
        ];
    }

    /**
     * Registra trilha de auditoria para operações de autenticação por grupo.
     */
    public function recordAudit(
        Request $request,
        string $action,
        string $description,
        string $resourceType = 'SchoolGroup',
        ?string $resourceId = null,
        ?User $user = null,
        ?string $userName = null,
        ?string $userEmail = null,
        ?string $userRole = null
    ): void {
        AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? $userName,
            'user_email' => $user?->email ?? $userEmail,
            'user_role' => $user ? implode(', ', $user->roles ?? []) : $userRole,
            'app_source' => 'gestao-escolar-group-auth',
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'description' => $description,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);
    }

    /**
     * @param array{type: 'email'|'cpf', value: string} $normalized
     */
    private function findActiveMember(string $schoolUid, array $normalized): ?SchoolGroupMember
    {
        return SchoolGroupMember::query()
            ->with('group')
            ->where('school_uid', $schoolUid)
            ->where('active', true)
            ->whereHas('group', fn ($q) => $q->where('school_uid', $schoolUid)->where('active', true))
            ->where(function ($q) use ($normalized) {
                if ($normalized['type'] === 'email') {
                    $q->whereRaw('LOWER(email) = ?', [$normalized['value']]);
                } else {
                    $q->where('cpf', $normalized['value']);
                }
            })
            ->first();
    }
}
