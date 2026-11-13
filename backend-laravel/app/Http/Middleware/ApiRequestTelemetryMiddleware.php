<?php

namespace App\Http\Middleware;

use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiRequestTelemetryMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $startedAt = microtime(true);
        $response = $next($request);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        try {
            $routePath = '/'.ltrim($request->path(), '/');
            ApiRequestLog::create([
                'service_name' => $request->header('X-Service-Name', 'gestao-escolar-api'),
                'method' => $request->method(),
                'route' => mb_substr($routePath, 0, 255),
                'status_code' => $response->getStatusCode(),
                'response_time_ms' => max(0, $elapsedMs),
                'ip_address' => $request->ip(),
                'user_id' => $request->user()?->id,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Telemetria nunca deve quebrar a requisição principal
        }

        return $response;
    }
}
