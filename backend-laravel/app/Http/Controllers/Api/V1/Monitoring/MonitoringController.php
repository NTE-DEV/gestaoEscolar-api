<?php

namespace App\Http\Controllers\Api\V1\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\ApiRequestLog;
use App\Models\AuditLog;
use App\Models\SystemMetricHistory;
use App\Services\Monitoring\DockerEngineService;
use App\Services\Monitoring\HostMetricsService;
use App\Services\Monitoring\StorageAnalyzerService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use OpenApi\Attributes as OA;

class MonitoringController extends Controller
{
    #[OA\Get(
        path: '/api/v1/monitoring/system',
        tags: ['Monitoring'],
        summary: 'Obter snapshot em tempo real do servidor',
        description: 'Retorna métricas instantâneas de CPU, memória, disco e uptime do host.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Snapshot de métricas do host'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function system(HostMetricsService $hostMetrics): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $hostMetrics->snapshot(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/monitoring/system-history',
        tags: ['Monitoring'],
        summary: 'Obter histórico diário consolidado de CPU e memória',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, description: 'Janela em dias (5 a 60)', schema: new OA\Schema(type: 'integer', default: 20, minimum: 5, maximum: 60)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Histórico consolidado de métricas do host'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function systemHistory(Request $request): JsonResponse
    {
        $days = min(max($request->integer('days', 20), 5), 60);
        $startDate = Carbon::today()->subDays($days - 1);

        $records = SystemMetricHistory::where('metric_date', '>=', $startDate->format('Y-m-d'))
            ->orderBy('metric_date')
            ->get()
            ->keyBy(fn ($r) => Carbon::parse($r->metric_date)->format('Y-m-d'));

        $totalMemBytes = 16 * 1024 * 1024 * 1024;
        if ($records->isNotEmpty() && $records->last()->memory_total_bytes > 0) {
            $totalMemBytes = $records->last()->memory_total_bytes;
        }

        $history = [];
        for ($i = 0; $i < $days; $i++) {
            $date = Carbon::today()->subDays($days - 1 - $i);
            $key = $date->format('Y-m-d');
            $label = $date->format('d/m');

            if ($records->has($key)) {
                $rec = $records->get($key);
                $memTotalGb = round(($rec->memory_total_bytes ?: $totalMemBytes) / (1024 ** 3), 2);
                $memUsedGb = round(($rec->memory_peak_used_bytes ?: ($totalMemBytes * ($rec->memory_peak_percent / 100))) / (1024 ** 3), 2);
                $history[] = [
                    'date' => $key,
                    'label' => $label,
                    'cpu_peak' => $rec->cpu_peak_percent,
                    'cpu_avg' => $rec->cpu_avg_percent,
                    'cpu_peak_time' => $rec->cpu_peak_time,
                    'memory_peak' => $rec->memory_peak_percent,
                    'memory_avg' => $rec->memory_avg_percent,
                    'memory_used_gb' => $memUsedGb,
                    'memory_total_gb' => $memTotalGb,
                    'samples' => $rec->samples_count,
                    'has_data' => true,
                ];
            } else {
                $history[] = [
                    'date' => $key,
                    'label' => $label,
                    'cpu_peak' => 0.0,
                    'cpu_avg' => 0.0,
                    'cpu_peak_time' => null,
                    'memory_peak' => 0.0,
                    'memory_avg' => 0.0,
                    'memory_used_gb' => 0.0,
                    'memory_total_gb' => round($totalMemBytes / (1024 ** 3), 2),
                    'samples' => 0,
                    'has_data' => false,
                ];
            }
        }

        $recorded = array_values(array_filter($history, fn ($h) => $h['has_data']));
        $hasData = count($recorded) > 0;
        $cpuPeaks = array_column($recorded, 'cpu_peak');
        $memPeaks = array_column($recorded, 'memory_peak');

        $maxCpu = $cpuPeaks ? max($cpuPeaks) : 0;
        $avgCpu = $cpuPeaks ? round(array_sum($cpuPeaks) / count($cpuPeaks), 1) : 0;
        $maxMem = $memPeaks ? max($memPeaks) : 0;
        $avgMem = $memPeaks ? round(array_sum($memPeaks) / count($memPeaks), 1) : 0;

        $cpuAction = $maxCpu >= 85 ? 'scale_up' : 'normal';
        $memAction = $maxMem >= 85 ? 'scale_up' : 'normal';

        return response()->json([
            'success' => true,
            'data' => [
                'window_days' => $days,
                'has_data' => $hasData,
                'recorded_days' => count($recorded),
                'summary' => [
                    'cpu_peak_max' => $maxCpu,
                    'cpu_peak_avg' => $avgCpu,
                    'memory_peak_max' => $maxMem,
                    'memory_peak_avg' => $avgMem,
                ],
                'recommendation' => [
                    'cpu_action' => $cpuAction,
                    'memory_action' => $memAction,
                    'message' => ! $hasData
                        ? 'Aguardando consolidação das amostras pelo agendador (a cada 5 minutos).'
                        : ($maxCpu >= 85 || $maxMem >= 85
                            ? 'Atenção: Picos elevados (>=85%) detectados. Recomenda-se planejar escalonamento.'
                            : ($maxCpu >= 70 || $maxMem >= 70
                                ? 'Moderado: Picos entre 70% e 84% observados.'
                                : 'Normal: Recursos operando dentro dos limites confortáveis.')),
                ],
                'history' => $history,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/monitoring/docker',
        tags: ['Monitoring'],
        summary: 'Listar containers Docker monitorados',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Lista de containers e respectivos status'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function docker(DockerEngineService $docker): JsonResponse
    {
        $result = $docker->listContainers();

        return response()->json([
            'success' => $result['ok'],
            'source' => $result['source'],
            'message' => $result['message'],
            'data' => $result['data'],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/monitoring/docker/{id}/logs',
        tags: ['Monitoring'],
        summary: 'Obter logs recentes de um container Docker (Master)',
        description: 'Acesso restrito a usuários com perfil Master.',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID ou nome do container Docker', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'tail', in: 'query', required: false, description: 'Quantidade de linhas recentes', schema: new OA\Schema(type: 'integer', default: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Logs do container'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Acesso restrito ao perfil Master'),
            new OA\Response(response: 500, description: 'Erro ao consultar logs do Docker Engine'),
        ]
    )]
    public function dockerLogs(string $id, Request $request, DockerEngineService $docker): JsonResponse
    {
        $tail = $request->integer('tail', 100);
        $result = $docker->getContainerLogs($id, $tail);

        return response()->json([
            'success' => $result['ok'],
            'message' => $result['message'] ?? 'Logs carregados.',
            'data' => $result['logs'],
        ], $result['ok'] ? 200 : 500);
    }

    #[OA\Get(
        path: '/api/v1/monitoring/requests/stats',
        tags: ['Monitoring'],
        summary: 'Obter estatísticas agregadas de requisições da API',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Métricas de volume, erros, latência média, usuários ativos e top rotas/serviços'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function requestsStats(): JsonResponse
    {
        $totalRequests = ApiRequestLog::count();
        $totalErrors = ApiRequestLog::where('status_code', '>=', 400)->count();
        $avgMs = round((float) ApiRequestLog::avg('response_time_ms'), 2);
        $activeUsers = ApiRequestLog::where('created_at', '>=', now()->subMinutes(15))
            ->whereNotNull('user_id')
            ->distinct('user_id')
            ->count('user_id');

        $rpmWindow = ApiRequestLog::where('created_at', '>=', now()->subMinutes(5))->count();
        $rpm = round($rpmWindow / 5, 1);

        $topRoutes = ApiRequestLog::select('route', DB::raw('COUNT(*) as total'))
            ->groupBy('route')
            ->orderByDesc('total')
            ->limit(8)
            ->get();

        $topServices = ApiRequestLog::select('service_name', DB::raw('COUNT(*) as total'))
            ->groupBy('service_name')
            ->orderByDesc('total')
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'total_requests' => $totalRequests,
                'total_errors' => $totalErrors,
                'average_response_time_ms' => $avgMs,
                'active_users' => $activeUsers,
                'requests_per_minute' => $rpm,
                'top_routes' => $topRoutes,
                'top_services' => $topServices,
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/monitoring/requests/daily',
        tags: ['Monitoring'],
        summary: 'Obter série diária de requisições e erros HTTP',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'days', in: 'query', required: false, description: 'Janela em dias (5 a 60)', schema: new OA\Schema(type: 'integer', default: 20, minimum: 5, maximum: 60)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Série diária de totais e erros'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function requestsDaily(Request $request): JsonResponse
    {
        $days = min(max($request->integer('days', 20), 5), 60);
        $start = Carbon::today()->subDays($days - 1);

        $rows = ApiRequestLog::select(
            DB::raw('DATE(created_at) as day'),
            DB::raw('COUNT(*) as total'),
            DB::raw('SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as errors')
        )
            ->where('created_at', '>=', $start->startOfDay())
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy('day');

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $d = Carbon::today()->subDays($days - 1 - $i);
            $key = $d->format('Y-m-d');
            $row = $rows->get($key);
            $series[] = [
                'date' => $key,
                'label' => $d->format('d/m'),
                'total' => $row ? (int) $row->total : 0,
                'errors' => $row ? (int) $row->errors : 0,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => ['days' => $series],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/monitoring/requests/top-ips',
        tags: ['Monitoring'],
        summary: 'Listar IPs com maior volume de requisições',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'limit', in: 'query', required: false, description: 'Limite de registros (5 a 100)', schema: new OA\Schema(type: 'integer', default: 20, minimum: 5, maximum: 100)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Ranking de IPs por volume de requisições'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function topIps(Request $request): JsonResponse
    {
        $limit = min(max($request->integer('limit', 20), 5), 100);
        $ips = ApiRequestLog::select(
            'ip_address',
            DB::raw('COUNT(*) as total'),
            DB::raw('SUM(CASE WHEN status_code >= 400 THEN 1 ELSE 0 END) as errors'),
            DB::raw('MAX(created_at) as last_seen')
        )
            ->whereNotNull('ip_address')
            ->groupBy('ip_address')
            ->orderByDesc('total')
            ->limit($limit)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $ips,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/monitoring/requests/reset',
        tags: ['Monitoring'],
        summary: 'Reiniciar histórico de métricas de requisições (Master)',
        description: 'Trunca a tabela de logs de requisições e grava evento de auditoria. Restrito ao perfil Master.',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Métricas resetadas com sucesso',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'message', type: 'string', example: 'Métricas de requisições resetadas com sucesso.'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Acesso restrito ao perfil Master'),
        ]
    )]
    public function resetRequests(Request $request): JsonResponse
    {
        $deleted = ApiRequestLog::count();
        ApiRequestLog::truncate();

        $user = $request->user();
        AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'user_role' => implode(', ', $user?->roles ?? []),
            'app_source' => 'gestao-escolar-admin',
            'action' => 'RESET',
            'resource_type' => 'ApiRequestLog',
            'description' => "Métricas de requisições reiniciadas ({$deleted} registros removidos).",
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Métricas de requisições resetadas com sucesso.',
        ]);
    }

    #[OA\Get(
        path: '/api/v1/monitoring/storage',
        tags: ['Monitoring'],
        summary: 'Analisar uso de armazenamento em disco dos módulos',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Relatório de utilização de armazenamento'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function storage(StorageAnalyzerService $storage): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $storage->analyze(),
        ]);
    }
}
