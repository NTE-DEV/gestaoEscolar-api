<?php

namespace App\Http\Controllers\Api\V1\Monitoring;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
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

    public function show(int $id): JsonResponse
    {
        $log = AuditLog::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $log,
        ]);
    }

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
