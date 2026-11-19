<?php

namespace App\Http\Controllers;

use OpenApi\Attributes as OA;

#[OA\OpenApi(
    openapi: '3.0.0',
    servers: [
        new OA\Server(url: '/', description: 'Servidor Local / Direto (/api/v1)'),
        new OA\Server(url: '/admin', description: 'Gateway Produção (/admin/api/v1)'),
        new OA\Server(url: '/dev/admin', description: 'Gateway Homologação (/dev/admin/api/v1)'),
    ]
)]
#[OA\Info(
    version: '1.0.0',
    title: 'Gestão Escolar API — SEMED Vila Velha',
    description: 'API Core, Autenticação, Auditoria e Monitoramento de Infraestrutura do Sistema de Gestão Escolar SEMED.'
)]
#[OA\SecurityScheme(
    securityScheme: 'bearerAuth',
    type: 'http',
    scheme: 'bearer',
    bearerFormat: 'Sanctum',
    description: 'Informe o token Bearer retornado no endpoint /api/v1/auth/login'
)]
#[OA\Get(
    path: '/api/v1/health',
    summary: 'Health check da API Gestão Escolar',
    description: 'Verifica se a API Gestão Escolar está operacional.',
    tags: ['Health & Status'],
    responses: [
        new OA\Response(
            response: 200,
            description: 'Serviço operacional',
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'success', type: 'boolean', example: true),
                    new OA\Property(property: 'service', type: 'string', example: 'gestao-escolar-api'),
                    new OA\Property(property: 'timestamp', type: 'string', format: 'date-time', example: '2026-11-18T16:20:00+00:00'),
                ]
            )
        ),
    ]
)]
abstract class Controller
{
    //
}
