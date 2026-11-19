<?php

namespace App\Services\Auth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PortalSemedAuthService
{
    public const ALLOWED_ADMIN_ROLES = [
        'Master',
        'Administrador do Sistema',
        'Gestor do Sistema',
        'Gestor de Sistema',
    ];

    /**
     * Autentica credenciais diretamente na API do Portal Semed (/api/v1/login) para /admin.
     */
    public function authenticate(string $login, string $password, ?string $deviceId = null): array
    {
        $response = $this->requestPortalLogin(
            $login,
            $password,
            'app',
            $deviceId ?: 'gestao-escolar-admin'
        );

        if (! $response['ok']) {
            return $response;
        }

        $portalUser = $response['user'];
        $roles = $this->extractRoleNames($portalUser['roles'] ?? []);

        if (! $this->hasAllowedAdminRole($roles)) {
            Log::warning('PortalSemedAuthService: role não autorizada para /admin', [
                'portal_user_id' => $portalUser['id'] ?? null,
                'roles' => $roles,
            ]);

            return [
                'ok' => false,
                'status' => 403,
                'message' => 'Acesso restrito aos perfis Master, Administrador do Sistema ou Gestor do Sistema.',
            ];
        }

        return [
            'ok' => true,
            'status' => 200,
            'user' => $portalUser,
            'roles' => $roles,
            'portal_token' => $response['portal_token'] ?? null,
        ];
    }

    /**
     * Autentica credenciais no Portal Semed sem exigir role administrativa global
     * (a autorização por unidade escolar/setorial é validada no Passo 1 do SchoolGroupAuthService).
     */
    public function authenticateForSchoolGroup(string $login, string $password, ?string $deviceId = null): array
    {
        $response = $this->requestPortalLogin(
            $login,
            $password,
            'portal',
            $deviceId ?: 'gestao-escolar-group'
        );

        if (! $response['ok']) {
            return $response;
        }

        $portalUser = $response['user'];

        return [
            'ok' => true,
            'status' => 200,
            'user' => $portalUser,
            'roles' => $this->extractRoleNames($portalUser['roles'] ?? []),
            'portal_token' => $response['portal_token'] ?? null,
        ];
    }

    /**
     * Consulta a listagem pública de escolas e/ou setores diretamente na API do Portal Semed (com cache de 5 minutos).
     */
    public function fetchPublicSchools(?string $search = null, int $page = 1, int $perPage = 150, ?string $type = 'all'): array
    {
        return $this->fetchPublicUnits($search, $type ?: 'all', $page, $perPage);
    }

    /**
     * Consulta a listagem unificada de unidades (Escolas e/ou Setores) com normalização de campos.
     */
    public function fetchPublicUnits(?string $search = null, string $type = 'all', int $page = 1, int $perPage = 150): array
    {
        $searchTerm = trim((string) $search);
        $type = in_array(strtolower(trim($type)), ['school', 'sector', 'all'], true) ? strtolower(trim($type)) : 'all';
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 250);

        $cacheKey = sprintf('portal_semed:units:%s:%s:%d:%d', md5($searchTerm), $type, $page, $perPage);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $allUnits = [];
        $totalSchools = 0;
        $totalSectors = 0;

        // 1. Carrega Escolas se type for 'all' ou 'school'
        if ($type === 'all' || $type === 'school') {
            $schoolsRaw = $this->fetchRawSchools();
            $totalSchools = count($schoolsRaw);
            foreach ($schoolsRaw as $s) {
                $allUnits[] = $this->normalizeSchoolItem($s);
            }
        }

        // 2. Carrega Setores se type for 'all' ou 'sector'
        if ($type === 'all' || $type === 'sector') {
            $sectorsRaw = $this->fetchRawSectors();
            $totalSectors = count($sectorsRaw);
            foreach ($sectorsRaw as $sec) {
                $allUnits[] = $this->normalizeSectorItem($sec);
            }
        }

        // 3. Aplica filtro de busca textual se houver
        if ($searchTerm !== '') {
            $termLower = mb_strtolower($searchTerm, 'UTF-8');
            $allUnits = array_values(array_filter($allUnits, function ($item) use ($termLower) {
                $name = mb_strtolower((string) ($item['name'] ?? ''), 'UTF-8');
                $code = mb_strtolower((string) ($item['code'] ?? ''), 'UTF-8');
                $email = mb_strtolower((string) ($item['email'] ?? ''), 'UTF-8');

                return str_contains($name, $termLower)
                    || str_contains($code, $termLower)
                    || str_contains($email, $termLower);
            }));
        }

        // Ordenação por nome
        usort($allUnits, fn ($a, $b) => strcasecmp((string) $a['name'], (string) $b['name']));

        // Paginação manual em memória
        $totalItems = count($allUnits);
        $offset = ($page - 1) * $perPage;
        $paginatedItems = array_slice($allUnits, $offset, $perPage);
        $lastPage = (int) ceil(max(1, $totalItems) / $perPage);

        $result = [
            'ok' => true,
            'status' => 200,
            'data' => $paginatedItems,
            'meta' => [
                'current_page' => $page,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $totalItems,
                'total_schools' => $totalSchools,
                'total_sectors' => $totalSectors,
                'total_units' => $totalSchools + $totalSectors,
                'type_filter' => $type,
            ],
        ];

        Cache::put($cacheKey, $result, now()->addMinutes(5));

        return $result;
    }

    /**
     * Busca lista crua de escolas com cache interno de 10 minutos.
     */
    public function fetchRawSchools(): array
    {
        return Cache::remember('portal_semed:raw_schools', now()->addMinutes(10), function () {
            $baseUrl = rtrim((string) config('services.portal_semed.base_url'), '/');
            $timeout = (int) config('services.portal_semed.timeout', 15);

            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->get("{$baseUrl}/api/v1/publics/schools", ['per_page' => 250]);

                if (! $response->successful()) {
                    return [];
                }

                [$items] = $this->unwrapPaginatedPayload($response->json() ?? []);
                return $items;
            } catch (\Throwable $e) {
                Log::error('PortalSemedAuthService: erro ao buscar escolas cruas', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Busca lista crua de setores com cache interno de 10 minutos.
     */
    public function fetchRawSectors(): array
    {
        return Cache::remember('portal_semed:raw_sectors', now()->addMinutes(10), function () {
            $baseUrl = rtrim((string) config('services.portal_semed.base_url'), '/');
            $timeout = (int) config('services.portal_semed.timeout', 15);

            try {
                $response = Http::timeout($timeout)
                    ->acceptJson()
                    ->get("{$baseUrl}/api/v1/publics/sectors", ['per_page' => 250]);

                if (! $response->successful()) {
                    return [];
                }

                [$items] = $this->unwrapPaginatedPayload($response->json() ?? []);
                return $items;
            } catch (\Throwable $e) {
                Log::error('PortalSemedAuthService: erro ao buscar setores crus', ['error' => $e->getMessage()]);
                return [];
            }
        });
    }

    /**
     * Normaliza item de escola para padrão unificado de unidade.
     */
    private function normalizeSchoolItem(array $s): array
    {
        $schoolType = trim((string) ($s['type'] ?? 'UMEF'));
        $label = $schoolType !== '' ? "Escola ({$schoolType})" : 'Escola';

        return [
            'id' => $s['id'] ?? null,
            'uid' => (string) ($s['uid'] ?? ''),
            'name' => (string) ($s['name'] ?? ''),
            'type' => 'school',
            'type_label' => $label,
            'code' => $s['inep'] ?? null,
            'email' => $s['email_primary'] ?? $s['email_alternative'] ?? $s['email'] ?? null,
            'phone' => $s['phone'] ?? $s['cellphone'] ?? null,
            'active' => ! ($s['trash'] ?? false),
            'raw_type' => $schoolType,
        ];
    }

    /**
     * Normaliza item de setor para padrão unificado de unidade.
     */
    private function normalizeSectorItem(array $sec): array
    {
        $sectorType = trim((string) ($sec['type'] ?? 'setor'));
        $label = $sectorType !== '' ? 'Setor (' . ucfirst($sectorType) . ')' : 'Setor';

        return [
            'id' => $sec['id'] ?? null,
            'uid' => (string) ($sec['uid'] ?? ''),
            'name' => (string) ($sec['name'] ?? ''),
            'type' => 'sector',
            'type_label' => $label,
            'code' => null,
            'email' => $sec['email'] ?? null,
            'phone' => $sec['phone'] ?? $sec['mobile'] ?? null,
            'active' => (bool) ($sec['active'] ?? true),
            'raw_type' => $sectorType,
        ];
    }

    /**
     * Consulta os membros do grupo vinculado ao UID da unidade (escola ou setor) no Portal Semed.
     */
    public function fetchSchoolGroupMembersFromPortal(string $schoolUid, ?string $bearerToken = null, string $type = 'school'): array
    {
        $token = trim((string) ($bearerToken ?: config('services.portal_semed.api_token')));
        if ($token === '') {
            return [
                'ok' => false,
                'status' => 422,
                'message' => 'Token da API do Portal Semed (PORTAL_API_TOKEN) não configurado.',
                'data' => [],
            ];
        }

        $baseUrl = rtrim((string) config('services.portal_semed.base_url'), '/');
        $timeout = (int) config('services.portal_semed.timeout', 15);
        $groupType = in_array(strtolower($type), ['school', 'sector'], true) ? strtolower($type) : 'school';

        try {
            $response = Http::timeout($timeout)
                ->withToken($token)
                ->acceptJson()
                ->get("{$baseUrl}/api/v1/groups", [
                    'uid_from_who' => $schoolUid,
                    'type' => $groupType,
                    'active' => 1,
                    'per_page' => 200,
                ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'status' => $response->status() ?: 502,
                    'message' => $response->json('message') ?? 'Falha ao consultar grupo no Portal Semed.',
                    'data' => [],
                ];
            }

            [$items, $meta] = $this->unwrapPaginatedPayload($response->json() ?? []);

            return [
                'ok' => true,
                'status' => 200,
                'data' => $items,
                'meta' => $meta,
            ];
        } catch (\Throwable $e) {
            Log::error('PortalSemedAuthService: erro ao sincronizar grupo no Portal Semed', [
                'school_uid' => $schoolUid,
                'type' => $groupType,
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Não foi possível comunicar com a API de grupos do Portal Semed.',
                'data' => [],
            ];
        }
    }

    private function requestPortalLogin(string $login, string $password, string $origin, string $deviceId): array
    {
        $baseUrl = rtrim((string) config('services.portal_semed.base_url'), '/');
        $timeout = (int) config('services.portal_semed.timeout', 15);

        $payload = [
            'login' => trim($login),
            'password' => $password,
            'origin' => $origin,
            'device_id' => $deviceId,
        ];

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->asJson()
                ->post("{$baseUrl}/api/v1/login", $payload);

            $body = $response->json();

            if (! $response->successful() || empty($body['success'])) {
                $status = $response->status();

                return [
                    'ok' => false,
                    'status' => in_array($status, [401, 403, 422], true) ? $status : 401,
                    'message' => $body['message'] ?? 'Credenciais inválidas ou acesso não autorizado no Portal Semed.',
                ];
            }

            return [
                'ok' => true,
                'status' => 200,
                'user' => $body['user'] ?? [],
                'portal_token' => $body['token'] ?? null,
            ];
        } catch (\Throwable $e) {
            Log::error('PortalSemedAuthService: erro de comunicação com Portal Semed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Não foi possível comunicar com o serviço de autenticação do Portal Semed.',
            ];
        }
    }

    /**
     * Extrai lista de itens e metadados de paginação do formato padrão do Portal Semed.
     *
     * @return array{0: array<int, mixed>, 1: array<string, mixed>}
     */
    private function unwrapPaginatedPayload(array $json): array
    {
        $meta = [];
        $items = [];

        if (isset($json['data']) && is_array($json['data'])) {
            if (isset($json['data']['data']) && is_array($json['data']['data'])) {
                $items = $json['data']['data'];
                $meta = [
                    'current_page' => $json['data']['current_page'] ?? 1,
                    'last_page' => $json['data']['last_page'] ?? 1,
                    'per_page' => $json['data']['per_page'] ?? count($items),
                    'total' => $json['data']['total'] ?? count($items),
                ];
            } else {
                $items = $json['data'];
                $meta = ['total' => count($items)];
            }
        } elseif (array_is_list($json)) {
            $items = $json;
            $meta = ['total' => count($items)];
        }

        return [$items, $meta];
    }

    public function extractRoleNames(array $rawRoles): array
    {
        return collect($rawRoles)
            ->map(function ($role) {
                if (is_string($role)) {
                    return trim($role);
                }
                if (is_array($role)) {
                    return trim((string) ($role['name'] ?? $role['role'] ?? ''));
                }
                return '';
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function hasAllowedAdminRole(array $roles): bool
    {
        $normalizedUser = collect($roles)->map(fn ($r) => mb_strtolower(trim($r), 'UTF-8'));
        $normalizedAllowed = collect(self::ALLOWED_ADMIN_ROLES)->map(fn ($r) => mb_strtolower(trim($r), 'UTF-8'));

        return $normalizedUser->intersect($normalizedAllowed)->isNotEmpty();
    }
}
