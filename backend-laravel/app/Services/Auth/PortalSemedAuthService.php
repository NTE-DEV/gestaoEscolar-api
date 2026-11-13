<?php

namespace App\Services\Auth;

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
     * Autentica credenciais diretamente na API do Portal Semed (/api/v1/login).
     */
    public function authenticate(string $login, string $password, ?string $deviceId = null): array
    {
        $baseUrl = rtrim((string) config('services.portal_semed.base_url'), '/');
        $timeout = (int) config('services.portal_semed.timeout', 15);

        $payload = [
            'login' => trim($login),
            'password' => $password,
            'origin' => 'app',
            'device_id' => $deviceId ?: 'gestao-escolar-admin',
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
                    'status' => in_array($status, [401, 403, 422]) ? $status : 401,
                    'message' => $body['message'] ?? 'Credenciais inválidas ou acesso não autorizado no Portal Semed.',
                ];
            }

            $portalUser = $body['user'] ?? [];
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
