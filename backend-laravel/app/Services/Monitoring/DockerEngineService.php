<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Facades\Log;

class DockerEngineService
{
    /**
     * Serviços esperados no ecossistema a partir do nginx/default.conf.
     */
    public const ECOSYSTEM_MAP = [
        'gestao-proxy' => ['env' => 'infra', 'route' => '/'],
        'sgps-web' => ['env' => 'production', 'route' => '/sgps/'],
        'sgps-app' => ['env' => 'production', 'route' => '/s-api/'],
        'nutrirede-web' => ['env' => 'production', 'route' => '/nutrirede/'],
        'nutrirede-app' => ['env' => 'production', 'route' => '/n-api/'],
        'lp-web' => ['env' => 'production', 'route' => '/lp/'],
        'rm-web' => ['env' => 'production', 'route' => '/rm/'],
        'progsaed-web' => ['env' => 'production', 'route' => '/progsaed/'],
        'progsaed-app' => ['env' => 'production', 'route' => '/progsaed/'],
        'progpse-web' => ['env' => 'production', 'route' => '/progpse/'],
        'progpse-app' => ['env' => 'production', 'route' => '/progpse/'],
        'gestao-escolar-web' => ['env' => 'production', 'route' => '/admin/'],
        'gestao-escolar-app' => ['env' => 'production', 'route' => '/GE/v1/'],
        'sgps-web-homolog' => ['env' => 'homolog', 'route' => '/dev/sgps/'],
        'sgps-app-homolog' => ['env' => 'homolog', 'route' => '/dev/sgps/'],
        'nutrirede-web-homolog' => ['env' => 'homolog', 'route' => '/dev/nutrirede/'],
        'nutrirede-app-homolog' => ['env' => 'homolog', 'route' => '/dev/nutrirede/'],
        'lp-web-homolog' => ['env' => 'homolog', 'route' => '/dev/lp/'],
        'rm-web-homolog' => ['env' => 'homolog', 'route' => '/dev/rm/'],
        'progsaed-web-homolog' => ['env' => 'homolog', 'route' => '/dev/progsaed/'],
        'progsaed-app-homolog' => ['env' => 'homolog', 'route' => '/dev/progsaed/'],
        'progpse-web-homolog' => ['env' => 'homolog', 'route' => '/dev/progpse/'],
        'progpse-app-homolog' => ['env' => 'homolog', 'route' => '/dev/progpse/'],
        'gestao-escolar-web-homolog' => ['env' => 'homolog', 'route' => '/dev/admin/'],
        'gestao-escolar-app-homolog' => ['env' => 'homolog', 'route' => '/GE/dev/v1/'],
    ];

    public function listContainers(): array
    {
        $dockerHttpHost = config('services.docker.host_http');
        $socketPath = config('services.docker.socket_path', '/var/run/docker.sock');
        $useHttpProxy = ! empty($dockerHttpHost);

        if (! $useHttpProxy && (! file_exists($socketPath) || ! is_readable($socketPath))) {
            $reason = ! file_exists($socketPath) ? 'Arquivo de socket não encontrado' : 'Sem permissão de leitura no socket';
            Log::warning("DockerEngineService@listContainers: {$reason} em {$socketPath}");

            if (in_array(config('app.env'), ['local', 'development', 'develop', 'testing']) || config('app.debug')) {
                return [
                    'ok' => true,
                    'source' => 'mock',
                    'message' => 'Containers simulados para ambiente local de desenvolvimento.',
                    'data' => $this->mockContainers(),
                ];
            }

            return [
                'ok' => false,
                'source' => 'unavailable',
                'message' => "Docker inacessível ({$reason}). Verifique DOCKER_HOST_HTTP (docker-socket-proxy).",
                'data' => [],
            ];
        }

        try {
            $raw = $this->requestDocker('/containers/json?all=true');
            $containers = json_decode($raw, true);

            if (! is_array($containers)) {
                return [
                    'ok' => false,
                    'source' => 'error',
                    'message' => 'Erro ao decodificar resposta do Docker.',
                    'data' => [],
                ];
            }

            $mapped = array_map(function ($c) {
                $fullId = $c['Id'] ?? '';
                $rawName = $c['Names'][0] ?? '';
                $name = ltrim($rawName, '/');
                $meta = self::ECOSYSTEM_MAP[$name] ?? null;
                $environment = $meta['env'] ?? (str_contains($name, 'homolog') ? 'homolog' : (str_contains($name, 'proxy') ? 'infra' : 'production'));

                $ports = [];
                if (! empty($c['Ports']) && is_array($c['Ports'])) {
                    foreach ($c['Ports'] as $p) {
                        $ports[] = [
                            'host' => $p['IP'] ?? '0.0.0.0',
                            'host_port' => $p['PublicPort'] ?? null,
                            'container_port' => $p['PrivatePort'] ?? 0,
                            'protocol' => $p['Type'] ?? 'tcp',
                        ];
                    }
                }

                $networks = array_keys($c['NetworkSettings']['Networks'] ?? []);

                return [
                    'id' => substr($fullId, 0, 12),
                    'full_id' => $fullId,
                    'name' => $name,
                    'environment' => $environment,
                    'nginx_route' => $meta['route'] ?? null,
                    'state' => strtolower((string) ($c['State'] ?? 'unknown')),
                    'status' => $c['Status'] ?? '',
                    'uptime' => $c['Status'] ?? '',
                    'image' => $c['Image'] ?? '',
                    'command' => $c['Command'] ?? '',
                    'created_at' => isset($c['Created']) ? date('Y-m-d H:i:s', (int) $c['Created']) : null,
                    'ports' => $ports,
                    'networks' => $networks,
                ];
            }, $containers);

            return [
                'ok' => true,
                'source' => $useHttpProxy ? 'docker-socket-proxy' : 'unix-socket',
                'message' => 'Containers carregados com sucesso.',
                'data' => $mapped,
            ];
        } catch (\Throwable $e) {
            Log::error('DockerEngineService@listContainers: '.$e->getMessage());
            return [
                'ok' => false,
                'source' => 'error',
                'message' => 'Falha ao consultar o Docker Engine: '.$e->getMessage(),
                'data' => [],
            ];
        }
    }

    public function getContainerLogs(string $containerId, int $tail = 100): array
    {
        $tail = min(max($tail, 10), 500);
        try {
            $raw = $this->requestDocker("/containers/{$containerId}/logs?stdout=true&stderr=true&tail={$tail}");
            $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $raw) ?? $raw;
            return [
                'ok' => true,
                'logs' => explode("\n", trim($cleaned)),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'logs' => [],
            ];
        }
    }

    private function requestDocker(string $path): string
    {
        $dockerHttpHost = config('services.docker.host_http');
        $socketPath = config('services.docker.socket_path', '/var/run/docker.sock');

        $ch = curl_init();
        if (! empty($dockerHttpHost)) {
            curl_setopt($ch, CURLOPT_URL, rtrim($dockerHttpHost, '/').$path);
        } else {
            curl_setopt($ch, CURLOPT_URL, "http://localhost{$path}");
            curl_setopt($ch, CURLOPT_UNIX_SOCKET_PATH, $socketPath);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);

        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException($err);
        }
        curl_close($ch);

        return (string) $response;
    }

    private function mockContainers(): array
    {
        return [
            ['id' => 'prx001a2b3c4', 'full_id' => 'prx001a2b3c4', 'name' => 'gestao-proxy', 'environment' => 'infra', 'nginx_route' => '/', 'state' => 'running', 'status' => 'Up 14 days', 'uptime' => 'Up 14 days', 'image' => 'nginx:alpine', 'command' => 'nginx -g "daemon off;"', 'created_at' => now()->subDays(14)->format('Y-m-d H:i:s'), 'ports' => [['host' => '0.0.0.0', 'host_port' => 443, 'container_port' => 443, 'protocol' => 'tcp']], 'networks' => ['gestao-net']],
            ['id' => 'geweb01a2b3c', 'full_id' => 'geweb01a2b3c', 'name' => 'gestao-escolar-web', 'environment' => 'production', 'nginx_route' => '/admin/', 'state' => 'running', 'status' => 'Up 2 days', 'uptime' => 'Up 2 days', 'image' => 'gestao-escolar-web:latest', 'command' => 'nginx -g "daemon off;"', 'created_at' => now()->subDays(2)->format('Y-m-d H:i:s'), 'ports' => [], 'networks' => ['gestao-net']],
            ['id' => 'geapp01a2b3c', 'full_id' => 'geapp01a2b3c', 'name' => 'gestao-escolar-app', 'environment' => 'production', 'nginx_route' => '/GE/v1/', 'state' => 'running', 'status' => 'Up 2 days', 'uptime' => 'Up 2 days', 'image' => 'gestao-escolar-app:latest', 'command' => 'php-fpm', 'created_at' => now()->subDays(2)->format('Y-m-d H:i:s'), 'ports' => [], 'networks' => ['gestao-net']],
            ['id' => 'sgps01a2b3c4', 'full_id' => 'sgps01a2b3c4', 'name' => 'sgps-web', 'environment' => 'production', 'nginx_route' => '/sgps/', 'state' => 'running', 'status' => 'Up 10 days', 'uptime' => 'Up 10 days', 'image' => 'sgps-web:latest', 'command' => 'nginx -g "daemon off;"', 'created_at' => now()->subDays(10)->format('Y-m-d H:i:s'), 'ports' => [], 'networks' => ['gestao-net']],
            ['id' => 'nutr01a2b3c4', 'full_id' => 'nutr01a2b3c4', 'name' => 'nutrirede-web', 'environment' => 'production', 'nginx_route' => '/nutrirede/', 'state' => 'running', 'status' => 'Up 10 days', 'uptime' => 'Up 10 days', 'image' => 'nutrirede-web:latest', 'command' => 'nginx -g "daemon off;"', 'created_at' => now()->subDays(10)->format('Y-m-d H:i:s'), 'ports' => [], 'networks' => ['gestao-net']],
            ['id' => 'lp0001a2b3c4', 'full_id' => 'lp0001a2b3c4', 'name' => 'lp-web', 'environment' => 'production', 'nginx_route' => '/lp/', 'state' => 'running', 'status' => 'Up 5 days', 'uptime' => 'Up 5 days', 'image' => 'lp-web:latest', 'command' => 'nginx -g "daemon off;"', 'created_at' => now()->subDays(5)->format('Y-m-d H:i:s'), 'ports' => [], 'networks' => ['gestao-net']],
            ['id' => 'rm0001a2b3c4', 'full_id' => 'rm0001a2b3c4', 'name' => 'rm-web', 'environment' => 'production', 'nginx_route' => '/rm/', 'state' => 'running', 'status' => 'Up 5 days', 'uptime' => 'Up 5 days', 'image' => 'rm-web:latest', 'command' => 'nginx -g "daemon off;"', 'created_at' => now()->subDays(5)->format('Y-m-d H:i:s'), 'ports' => [], 'networks' => ['gestao-net']],
            ['id' => 'saed01a2b3c4', 'full_id' => 'saed01a2b3c4', 'name' => 'progsaed-web', 'environment' => 'production', 'nginx_route' => '/progsaed/', 'state' => 'running', 'status' => 'Up 7 days', 'uptime' => 'Up 7 days', 'image' => 'progsaed-web:latest', 'command' => 'nginx -g "daemon off;"', 'created_at' => now()->subDays(7)->format('Y-m-d H:i:s'), 'ports' => [], 'networks' => ['gestao-net']],
            ['id' => 'pse001a2b3c4', 'full_id' => 'pse001a2b3c4', 'name' => 'progpse-web', 'environment' => 'production', 'nginx_route' => '/progpse/', 'state' => 'running', 'status' => 'Up 7 days', 'uptime' => 'Up 7 days', 'image' => 'progpse-web:latest', 'command' => 'nginx -g "daemon off;"', 'created_at' => now()->subDays(7)->format('Y-m-d H:i:s'), 'ports' => [], 'networks' => ['gestao-net']],
            ['id' => 'gehom01a2b3c', 'full_id' => 'gehom01a2b3c', 'name' => 'gestao-escolar-web-homolog', 'environment' => 'homolog', 'nginx_route' => '/dev/admin/', 'state' => 'running', 'status' => 'Up 1 day', 'uptime' => 'Up 1 day', 'image' => 'gestao-escolar-web:homolog', 'command' => 'nginx -g "daemon off;"', 'created_at' => now()->subDay()->format('Y-m-d H:i:s'), 'ports' => [], 'networks' => ['gestao-net']],
        ];
    }
}
