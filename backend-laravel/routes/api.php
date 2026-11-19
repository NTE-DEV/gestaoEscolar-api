<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Auth\GroupAuthController;
use App\Http\Controllers\Api\V1\Auth\SchoolGroupController;
use App\Http\Controllers\Api\V1\Monitoring\AuditLogController;
use App\Http\Controllers\Api\V1\Monitoring\MonitoringController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Gestão Escolar API — Core & Microsserviços (/api/v1 -> /GE/v1 | /GE/dev/v1)
|--------------------------------------------------------------------------
*/
Route::prefix('v1')->group(function () {
    Route::redirect('/documentation', '/api/documentation');

    Route::get('/health', fn() => response()->json([
        'success' => true,
        'service' => 'gestao-escolar-api',
        'timestamp' => now()->toIso8601String(),
    ]));

    // Lista oficial de unidades escolares (Portal Semed API com cache)
    Route::get('/schools', [SchoolGroupController::class, 'schools']);

    // Autenticação via Portal Semed API
    Route::post('/auth/login', [AuthController::class, 'login']);
    Route::post('/auth/group-login', [GroupAuthController::class, 'groupLogin'])
        ->middleware('throttle:10,1');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        // Ingestão de auditoria para /admin e microsserviços conectados
        Route::post('/audit-logs', [AuditLogController::class, 'store']);

        // Painel Administrativo /admin (Master, Administrador do Sistema, Gestor do Sistema)
        Route::middleware('admin.role')->group(function () {
            Route::post('/auth/group-verify', [GroupAuthController::class, 'groupVerify']);

            Route::prefix('school-groups')->group(function () {
                Route::get('/', [SchoolGroupController::class, 'index']);
                Route::get('/{school_uid}/members', [SchoolGroupController::class, 'members'])
                    ->whereUuid('school_uid');
                Route::post('/{school_uid}/members', [SchoolGroupController::class, 'storeMember'])
                    ->whereUuid('school_uid');
                Route::patch('/{school_uid}/members/{member_id}', [SchoolGroupController::class, 'updateMember'])
                    ->whereUuid('school_uid')
                    ->whereNumber('member_id');
                Route::delete('/{school_uid}/members/{member_id}', [SchoolGroupController::class, 'destroyMember'])
                    ->whereUuid('school_uid')
                    ->whereNumber('member_id');
                Route::post('/{school_uid}/sync', [SchoolGroupController::class, 'sync'])
                    ->whereUuid('school_uid');
            });
            Route::prefix('monitoring')->group(function () {
                Route::get('/system', [MonitoringController::class, 'system']);
                Route::get('/system-history', [MonitoringController::class, 'systemHistory']);
                Route::get('/docker', [MonitoringController::class, 'docker']);
                Route::get('/requests/stats', [MonitoringController::class, 'requestsStats']);
                Route::get('/requests/daily', [MonitoringController::class, 'requestsDaily']);
                Route::get('/requests/top-ips', [MonitoringController::class, 'topIps']);
                Route::get('/storage', [MonitoringController::class, 'storage']);

                // Ações críticas restritas ao perfil Master
                Route::middleware('admin.role:Master')->group(function () {
                    Route::get('/docker/{id}/logs', [MonitoringController::class, 'dockerLogs']);
                    Route::post('/requests/reset', [MonitoringController::class, 'resetRequests']);
                });
            });

            Route::prefix('audit-logs')->group(function () {
                Route::get('/', [AuditLogController::class, 'index']);
                Route::get('/stats', [AuditLogController::class, 'stats']);
                Route::get('/export', [AuditLogController::class, 'export']);
                Route::get('/{id}', [AuditLogController::class, 'show']);
            });
        });
    });
});
