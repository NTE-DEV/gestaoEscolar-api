<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GestaoEscolarMonitoringTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('service', 'gestao-escolar-api');
    }

    public function test_login_via_portal_semed_allows_admin_roles_and_records_audit_log(): void
    {
        Http::fake([
            '*/api/v1/login' => Http::response([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'token' => 'portal-token-123',
                'user' => [
                    'id' => 99,
                    'name' => 'Master SEMED',
                    'email' => 'master@example.com',
                    'cpf' => '12345678900',
                    'roles' => [['name' => 'Master']],
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'login' => 'master@example.com',
            'password' => 'password',
            'device_id' => 'device-abc-123',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.email', 'master@example.com');

        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'LOGIN', 'user_email' => 'master@example.com']);
    }

    public function test_login_blocks_unauthorized_role_with_403(): void
    {
        Http::fake([
            '*/api/v1/login' => Http::response([
                'success' => true,
                'user' => [
                    'id' => 55,
                    'name' => 'Professor Teste',
                    'email' => 'prof@example.com',
                    'roles' => [['name' => 'Professor']],
                ],
            ], 200),
        ]);

        $this->postJson('/api/v1/auth/login', [
            'login' => 'prof@example.com',
            'password' => 'password',
        ])->assertStatus(403);
    }

    public function test_monitoring_and_audit_endpoints_for_admin_and_master_only_reset(): void
    {
        $gestor = User::create([
            'portal_user_id' => 10,
            'name' => 'Gestor',
            'email' => 'gestor@example.com',
            'roles' => ['Gestor do Sistema'],
        ]);

        Sanctum::actingAs($gestor);

        $this->getJson('/api/v1/monitoring/system')->assertOk();
        $this->getJson('/api/v1/monitoring/system-history?days=20')->assertOk();
        $this->getJson('/api/v1/monitoring/docker')->assertOk();
        $this->getJson('/api/v1/monitoring/requests/stats')->assertOk();
        $this->getJson('/api/v1/monitoring/storage')->assertOk();
        $this->getJson('/api/v1/audit-logs')->assertOk();
        $this->getJson('/api/v1/audit-logs/stats')->assertOk();
        $this->postJson('/api/v1/monitoring/requests/reset')->assertStatus(403);

        $master = User::create([
            'portal_user_id' => 1,
            'name' => 'Master',
            'email' => 'master@example.com',
            'roles' => ['Master'],
        ]);

        Sanctum::actingAs($master);
        $this->postJson('/api/v1/monitoring/requests/reset')->assertOk();
        $this->assertTrue(AuditLog::where('action', 'RESET')->exists());
    }
}
