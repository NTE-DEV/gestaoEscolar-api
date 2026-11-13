<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminRole
{
    public const DEFAULT_ALLOWED = [
        'Master',
        'Administrador do Sistema',
        'Gestor do Sistema',
        'Gestor de Sistema',
    ];

    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Não autenticado.',
            ], 401);
        }

        $allowed = empty($roles) ? self::DEFAULT_ALLOWED : $roles;

        if (! $user->hasAnyRole($allowed)) {
            return response()->json([
                'success' => false,
                'message' => 'Acesso negado. Perfil sem permissão para este recurso.',
            ], 403);
        }

        return $next($request);
    }
}
