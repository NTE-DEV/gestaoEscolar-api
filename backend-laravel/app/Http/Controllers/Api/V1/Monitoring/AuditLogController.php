<?php

namespace App\Http\Controllers\Api\V1\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    #[OA\Get(
        path: '/api/v1/audit-logs',
        tags: ['Audit Logs'],
        summary: 'Listar logs de auditoria paginados',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Busca por usuário, e-mail, ação, descrição ou IP', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'app_source', in: 'query', required: false, description: 'Filtro por aplicação de origem (ou ALL)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'action', in: 'query', required: false, description: 'Filtro por ação (CREATE, UPDATE, DELETE, LOGIN, LOGOUT, RESET ou ALL)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: 'Data inicial (YYYY-MM-DD)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Data final (YYYY-MM-DD)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, description: 'Itens por página (5 a 100)', schema: new OA\Schema(type: 'integer', default: 20, minimum: 5, maximum: 100)),
            new OA\Parameter(name: 'page', in: 'query', required: false, description: 'Número da página', schema: new OA\Schema(type: 'integer', default: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Lista paginada de logs de auditoria'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $query = $this->filteredQuery($request);
        $perPage = min(max($request->integer('per_page', 20), 5), 100);
        $logs = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $logs,
        ]);
    }

    #[OA\Get(
        path: '/api/v1/audit-logs/stats',
        tags: ['Audit Logs'],
        summary: 'Obter contadores agregados de auditoria',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(
                response: 200,
                description: 'Estatísticas de auditoria',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(
                            property: 'data',
                            type: 'object',
                            properties: [
                                new OA\Property(property: 'total', type: 'integer', example: 1250),
                                new OA\Property(property: 'today', type: 'integer', example: 34),
                                new OA\Property(property: 'creates', type: 'integer', example: 400),
                                new OA\Property(property: 'updates', type: 'integer', example: 320),
                                new OA\Property(property: 'deletes', type: 'integer', example: 45),
                            ]
                        ),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total' => AuditLog::count(),
                'today' => AuditLog::whereDate('created_at', today())->count(),
                'creates' => AuditLog::where('action', 'CREATE')->count(),
                'updates' => AuditLog::where('action', 'UPDATE')->count(),
                'deletes' => AuditLog::where('action', 'DELETE')->count(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/api/v1/audit-logs/{id}',
        tags: ['Audit Logs'],
        summary: 'Detalhar um log de auditoria',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'id', in: 'path', required: true, description: 'ID do registro de auditoria', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Detalhes do log de auditoria'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
            new OA\Response(response: 404, description: 'Registro não encontrado'),
        ]
    )]
    public function show(int $id): JsonResponse
    {
        $log = AuditLog::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $log,
        ]);
    }

    #[OA\Post(
        path: '/api/v1/audit-logs',
        tags: ['Audit Logs'],
        summary: 'Registrar evento de auditoria de aplicação ou microsserviço',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['app_source', 'action'],
                properties: [
                    new OA\Property(property: 'app_source', type: 'string', maxLength: 80, example: 'progpse'),
                    new OA\Property(property: 'action', type: 'string', maxLength: 40, example: 'CREATE'),
                    new OA\Property(property: 'resource_type', type: 'string', maxLength: 100, nullable: true, example: 'Project'),
                    new OA\Property(property: 'resource_id', type: 'string', maxLength: 100, nullable: true, example: '15'),
                    new OA\Property(property: 'description', type: 'string', maxLength: 1000, nullable: true, example: 'Novo projeto criado.'),
                    new OA\Property(property: 'old_values', type: 'object', nullable: true),
                    new OA\Property(property: 'new_values', type: 'object', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Log de auditoria registrado'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 422, description: 'Erro de validação'),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'app_source' => ['required', 'string', 'max:80'],
            'action' => ['required', 'string', 'max:40'],
            'resource_type' => ['nullable', 'string', 'max:100'],
            'resource_id' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:1000'],
            'old_values' => ['nullable', 'array'],
            'new_values' => ['nullable', 'array'],
        ]);

        $user = $request->user();

        $log = AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'user_role' => implode(', ', $user?->roles ?? []),
            'app_source' => $validated['app_source'],
            'action' => strtoupper($validated['action']),
            'resource_type' => $validated['resource_type'] ?? null,
            'resource_id' => $validated['resource_id'] ?? null,
            'description' => $validated['description'] ?? null,
            'old_values' => $validated['old_values'] ?? null,
            'new_values' => $validated['new_values'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            'created_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => $log,
        ], 201);
    }

    #[OA\Get(
        path: '/api/v1/audit-logs/export',
        tags: ['Audit Logs'],
        summary: 'Exportar logs de auditoria em CSV',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'search', in: 'query', required: false, description: 'Busca por usuário, e-mail, ação, descrição ou IP', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'app_source', in: 'query', required: false, description: 'Filtro por aplicação de origem (ou ALL)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'action', in: 'query', required: false, description: 'Filtro por ação (ou ALL)', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date_from', in: 'query', required: false, description: 'Data inicial (YYYY-MM-DD)', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', required: false, description: 'Data final (YYYY-MM-DD)', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Arquivo CSV (text/csv; charset=UTF-8)'),
            new OA\Response(response: 401, description: 'Não autenticado'),
            new OA\Response(response: 403, description: 'Sem permissão administrativa'),
        ]
    )]
    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filteredQuery($request)->orderByDesc('created_at')->limit(5000)->get();
        $filename = 'auditoria_semed_'.now()->format('Ymd_His').'.csv';

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
            fputcsv($out, ['ID', 'Data/Hora', 'Usuário', 'E-mail', 'Role', 'Aplicação', 'Ação', 'Recurso', 'Descrição', 'IP'], ';');
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->id,
                    optional($row->created_at)->format('d/m/Y H:i:s'),
                    $row->user_name,
                    $row->user_email,
                    $row->user_role,
                    $row->app_source,
                    $row->action,
                    trim(($row->resource_type ?? '').' #'.($row->resource_id ?? ''), ' #'),
                    $row->description,
                    $row->ip_address,
                ], ';');
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filteredQuery(Request $request)
    {
        $query = AuditLog::query();

        if ($search = trim((string) $request->input('search'))) {
            $query->where(function ($q) use ($search) {
                $q->where('user_name', 'like', "%{$search}%")
                    ->orWhere('user_email', 'like', "%{$search}%")
                    ->orWhere('action', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        if (($app = $request->input('app_source')) && $app !== 'ALL') {
            $query->where('app_source', $app);
        }

        if (($action = $request->input('action')) && $action !== 'ALL') {
            $query->where('action', $action);
        }

        if ($from = $request->input('date_from')) {
            $query->whereDate('created_at', '>=', $from);
        }

        if ($to = $request->input('date_to')) {
            $query->whereDate('created_at', '<=', $to);
        }

        return $query;
    }
}
