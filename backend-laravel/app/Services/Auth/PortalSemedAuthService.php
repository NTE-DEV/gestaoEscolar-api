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
     * (a autorização por unidade escolar é validada no Passo 1 do SchoolGroupAuthService).
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
     * Consulta a listagem pública de escolas diretamente na API do Portal Semed (com cache de 5 minutos).
     */
    public function fetchPublicSchools(?string $search = null, int $page = 1, int $perPage = 150): array
    {
        $searchTerm = trim((string) $search);
        $page = max(1, $page);
        $perPage = min(max(1, $perPage), 200);
        $cacheKey = sprintf('portal_semed:schools:%s:%d:%d', md5($searchTerm), $page, $perPage);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $baseUrl = rtrim((string) config('services.portal_semed.base_url'), '/');
        $timeout = (int) config('services.portal_semed.timeout', 15);

        $url = $searchTerm !== ''
            ? "{$baseUrl}/api/v1/publics/schools/search"
            : "{$baseUrl}/api/v1/publics/schools";

        $query = $searchTerm !== ''
            ? ['query' => $searchTerm, 'page' => $page, 'per_page' => $perPage]
            : ['page' => $page, 'per_page' => $perPage];

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->get($url, $query);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'status' => $response->status() ?: 502,
                    'message' => 'Falha ao consultar lista de escolas no Portal Semed.',
                    'data' => [],
                ];
            }

            $json = $response->json() ?? [];
            [$items, $meta] = $this->unwrapPaginatedPayload($json);

            $result = [
                'ok' => true,
                'status' => 200,
                'data' => $items,
                'meta' => $meta,
            ];

            Cache::put($cacheKey, $result, now()->addMinutes(5));

            return $result;
        } catch (\Throwable $e) {
            Log::error('PortalSemedAuthService: erro ao buscar escolas no Portal Semed', [
                'error' => $e->getMessage(),
            ]);

            return [
                'ok' => false,
                'status' => 503,
                'message' => 'Não foi possível comunicar com a API de escolas do Portal Semed.',
                'data' => [],
            ];
        }
    }

    /**
     * Consulta os membros do grupo vinculado ao UID da escola no Portal Semed.
     */
    public function fetchSchoolGroupMembersFromPortal(string $schoolUid, ?string $bearerToken = null): array
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

        try {
            $response = Http::timeout($timeout)
                ->withToken($token)
                ->acceptJson()
                ->get("{$baseUrl}/api/v1/groups", [
                    'uid_from_who' => $schoolUid,
                    'type' => 'school',
                    'active' => 1,
                    'per_page' => 200,
                ]);

            if (! $response->successful()) {
                return [
                    'ok' => false,
                    'status' => $response->status() ?: 502,
                    'message' => $response->json('message') ?? 'Falha ao consultar grupo da escola no Portal Semed.',
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
            Log::error('PortalSemedAuthService: erro ao sincronizar grupo escolar no Portal Semed', [
                'school_uid' => $schoolUid,
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
