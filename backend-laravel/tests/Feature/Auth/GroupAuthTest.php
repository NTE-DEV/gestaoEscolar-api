<?php

namespace Tests\Feature\Auth;

use App\Models\AuditLog;
use App\Models\SchoolGroup;
use App\Models\SchoolGroupMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class GroupAuthTest extends TestCase
{
    use RefreshDatabase;

    private string $schoolUid = '9d8a12b4-4e56-4c89-a123-7890abcdef12';

    public function test_step1_denies_user_not_in_school_group_without_calling_portal(): void
    {
        Http::fake();

        $group = SchoolGroup::create([
            'school_uid' => $this->schoolUid,
            'school_name' => 'UMEF Teste',
            'active' => true,
        ]);

        SchoolGroupMember::create([
            'school_group_id' => $group->id,
            'school_uid' => $this->schoolUid,
            'email' => 'outro@semed.vilavelha.es.gov.br',
            'role' => 'Gestor Escolar',
            'active' => true,
        ]);

        $this->postJson('/api/v1/auth/group-login', [
            'school_uid' => $this->schoolUid,
            'login' => 'intruso@semed.vilavelha.es.gov.br',
            'password' => 'segredo123',
        ])
            ->assertStatus(403)
            ->assertJsonPath('success', false)
            ->assertJsonPath('step', 'school_group_validation');

        Http::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_GROUP_DENIED',
            'resource_id' => $this->schoolUid,
        ]);
    }

    public function test_step1_denies_inactive_group_or_inactive_member_without_calling_portal(): void
    {
        Http::fake();

        $inactiveGroup = SchoolGroup::create([
            'school_uid' => $this->schoolUid,
            'school_name' => 'UMEF Inativa',
            'active' => false,
        ]);

        SchoolGroupMember::create([
            'school_group_id' => $inactiveGroup->id,
            'school_uid' => $this->schoolUid,
            'email' => 'membro@semed.vilavelha.es.gov.br',
            'active' => true,
        ]);

        $this->postJson('/api/v1/auth/group-login', [
            'school_uid' => $this->schoolUid,
            'login' => 'membro@semed.vilavelha.es.gov.br',
            'password' => 'segredo123',
        ])->assertStatus(403);

        $inactiveGroup->update(['active' => true]);
        SchoolGroupMember::where('school_uid', $this->schoolUid)->update(['active' => false]);

        $this->postJson('/api/v1/auth/group-login', [
            'school_uid' => $this->schoolUid,
            'login' => 'membro@semed.vilavelha.es.gov.br',
            'password' => 'segredo123',
        ])->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_step1_passes_with_masked_cpf_and_step2_returns_401_when_portal_rejects(): void
    {
        Http::fake([
            '*/api/v1/login' => Http::response([
                'success' => false,
                'message' => 'Login ou Senha Inválidas',
            ], 401),
        ]);

        $group = SchoolGroup::create([
            'school_uid' => $this->schoolUid,
            'school_name' => 'UMEF Alger Ribeiro Bossois',
            'active' => true,
        ]);

        SchoolGroupMember::create([
            'school_group_id' => $group->id,
            'school_uid' => $this->schoolUid,
            'name' => 'Servidor Teste',
            'cpf' => '123.456.789-00',
            'role' => 'Secretário Escolar',
            'active' => true,
        ]);

        $this->postJson('/api/v1/auth/group-login', [
            'school_uid' => $this->schoolUid,
            'login' => '123.456.789-00',
            'password' => 'senha-errada',
        ])
            ->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('step', 'portal_semed_authentication');

        Http::assertSentCount(1);
        Http::assertSent(fn (HttpClientRequest $req) => str_ends_with($req->url(), '/api/v1/login')
            && $req['login'] === '12345678900'
            && $req['origin'] === 'portal');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_GROUP_FAILED',
            'resource_id' => $this->schoolUid,
        ]);
    }

    public function test_two_step_group_login_succeeds_by_case_insensitive_email_and_creates_scoped_session(): void
    {
        Http::fake([
            '*/api/v1/login' => Http::response([
                'success' => true,
                'message' => 'Login realizado com sucesso',
                'token' => 'portal-jwt-token',
                'user' => [
                    'id' => 842,
                    'uid' => '7a6b5c4d-1234-4abc-9def-000011112222',
                    'name' => 'Maria da Silva',
                    'email' => 'diretor.escola@semed.vilavelha.es.gov.br',
                    'cpf' => '12345678900',
                    'roles' => [['name' => 'Diretor Escolar']],
                ],
            ], 200),
        ]);

        $group = SchoolGroup::create([
            'school_uid' => $this->schoolUid,
            'school_name' => 'UMEF Alger Ribeiro Bossois',
            'active' => true,
        ]);

        $member = SchoolGroupMember::create([
            'school_group_id' => $group->id,
            'school_uid' => $this->schoolUid,
            'email' => 'Diretor.Escola@semed.vilavelha.es.gov.br',
            'role' => 'Gestor Escolar',
            'active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/group-login', [
            'school_uid' => $this->schoolUid,
            'login' => '  DIRETOR.ESCOLA@semed.vilavelha.es.gov.br ',
            'password' => 'SenhaCorreta123',
            'device_id' => 'diario-web',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.email', 'diretor.escola@semed.vilavelha.es.gov.br')
            ->assertJsonPath('data.school_group.school_uid', $this->schoolUid)
            ->assertJsonPath('data.school_group.member_role', 'Gestor Escolar');

        $plainToken = $response->json('data.token');
        $this->assertNotEmpty($plainToken);

        $accessToken = PersonalAccessToken::findToken($plainToken);
        $this->assertNotNull($accessToken);
        $this->assertTrue($accessToken->can("school:{$this->schoolUid}"));
        $this->assertTrue($accessToken->can('group-auth'));

        $member->refresh();
        $this->assertSame(842, $member->portal_user_id);
        $this->assertSame('7a6b5c4d-1234-4abc-9def-000011112222', $member->portal_user_uid);
        $this->assertNotNull($member->local_user_id);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_GROUP',
            'resource_id' => $this->schoolUid,
            'user_email' => 'diretor.escola@semed.vilavelha.es.gov.br',
        ]);

        $this->withToken($plainToken)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.email', 'diretor.escola@semed.vilavelha.es.gov.br');
    }

    public function test_anti_spoofing_blocks_when_portal_user_does_not_match_group_member(): void
    {
        Http::fake([
            '*/api/v1/login' => Http::response([
                'success' => true,
                'user' => [
                    'id' => 999,
                    'uid' => 'ffffffff-ffff-4fff-8fff-ffffffffffff',
                    'name' => 'Outro Usuário',
                    'email' => 'outro@semed.vilavelha.es.gov.br',
                    'cpf' => '99999999999',
                    'roles' => [['name' => 'Professor']],
                ],
            ], 200),
        ]);

        $group = SchoolGroup::create([
            'school_uid' => $this->schoolUid,
            'school_name' => 'UMEF Teste',
            'active' => true,
        ]);

        SchoolGroupMember::create([
            'school_group_id' => $group->id,
            'school_uid' => $this->schoolUid,
            'cpf' => '12345678900',
            'role' => 'Gestor Escolar',
            'active' => true,
        ]);

        $this->postJson('/api/v1/auth/group-login', [
            'school_uid' => $this->schoolUid,
            'login' => '12345678900',
            'password' => 'SenhaCorreta',
        ])
            ->assertStatus(403)
            ->assertJsonPath('step', 'school_group_validation');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_aliases_and_validation_errors_on_group_login(): void
    {
        Http::fake([
            '*/api/v1/login' => Http::response([
                'success' => true,
                'user' => [
                    'id' => 301,
                    'name' => 'Alias User',
                    'email' => 'alias@semed.vilavelha.es.gov.br',
                    'cpf' => '55566677788',
                    'roles' => ['Editor de Conteúdo'],
                ],
            ], 200),
        ]);

        $group = SchoolGroup::create([
            'school_uid' => $this->schoolUid,
            'school_name' => 'UMEF Alias',
            'active' => true,
        ]);

        SchoolGroupMember::create([
            'school_group_id' => $group->id,
            'school_uid' => $this->schoolUid,
            'cpf' => '55566677788',
            'active' => true,
        ]);

        $this->postJson('/api/v1/auth/group-login', [
            'uid_escola' => 'not-a-uuid',
            'cpf' => '',
            'password' => '',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['school_uid', 'login', 'password']);

        $this->postJson('/api/v1/auth/group-login', [
            'uid_escola' => $this->schoolUid,
            'cpf' => '555.666.777-88',
            'password' => 'secret',
        ])
            ->assertOk()
            ->assertJsonPath('data.user.cpf', '55566677788');
    }

    public function test_group_verify_and_school_groups_crud_and_schools_proxy_and_sync(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/v1/publics/schools*' => Http::response([
                'success' => true,
                'data' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'total' => 1,
                    'data' => [
                        ['id' => 10, 'uid' => $this->schoolUid, 'name' => 'Alger Ribeiro Bossois', 'type' => 'UMEF'],
                    ],
                ],
            ], 200),
            '*/api/v1/groups*' => Http::response([
                'success' => true,
                'data' => [
                    'data' => [
                        [
                            'uid' => 'aaaa1111-1111-4111-8111-111111111111',
                            'uid_user' => 'bbbb2222-2222-4222-8222-222222222222',
                            'uid_from_who' => $this->schoolUid,
                            'active' => true,
                            'user' => [
                                'id' => 77,
                                'uid' => 'bbbb2222-2222-4222-8222-222222222222',
                                'name' => 'Coordenadora Portal',
                                'email' => 'coord@semed.vilavelha.es.gov.br',
                                'cpf' => '11122233344',
                                'roles' => [['name' => 'Editor de Conteúdo']],
                            ],
                        ],
                    ],
                ],
            ], 200),
        ]);

        // Lista pública de escolas com cache (2ª chamada não refaz HTTP)
        $this->getJson('/api/v1/schools')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.0.uid', $this->schoolUid);
        $this->getJson('/api/v1/schools')->assertOk();

        $admin = User::create([
            'portal_user_id' => 1,
            'name' => 'Admin SEMED',
            'email' => 'admin@semed.vilavelha.es.gov.br',
            'roles' => ['Administrador do Sistema'],
        ]);
        Sanctum::actingAs($admin);

        // CRUD: adicionar membro
        $createRes = $this->postJson("/api/v1/school-groups/{$this->schoolUid}/members", [
            'school_name' => 'UMEF Alger Ribeiro Bossois',
            'name' => 'Maria Diretora',
            'email' => 'MARIA@semed.vilavelha.es.gov.br',
            'cpf' => '987.654.321-00',
            'role' => 'Diretor Escolar',
        ])->assertCreated();

        $memberId = $createRes->json('data.id');
        $this->assertSame('maria@semed.vilavelha.es.gov.br', $createRes->json('data.email'));
        $this->assertSame('98765432100', $createRes->json('data.cpf'));

        // Verify Passo 1 (positivo e negativo)
        $this->postJson('/api/v1/auth/group-verify', [
            'school_uid' => $this->schoolUid,
            'login' => '98765432100',
        ])
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.member.role', 'Diretor Escolar');

        $this->postJson('/api/v1/auth/group-verify', [
            'school_uid' => $this->schoolUid,
            'login' => 'desconhecido@semed.vilavelha.es.gov.br',
        ])
            ->assertOk()
            ->assertJsonPath('data.authorized', false);

        // Listagem, update, delete
        $this->getJson('/api/v1/school-groups')
            ->assertOk()
            ->assertJsonPath('data.data.0.members_count', 1);

        $this->patchJson("/api/v1/school-groups/{$this->schoolUid}/members/{$memberId}", [
            'role' => 'Gestor Escolar',
        ])
            ->assertOk()
            ->assertJsonPath('data.role', 'Gestor Escolar');

        // Sync com Portal Semed API
        $this->postJson("/api/v1/school-groups/{$this->schoolUid}/sync", [
            'portal_token' => 'service-token-test',
        ])
            ->assertOk()
            ->assertJsonPath('data.synced_count', 1);

        $this->getJson("/api/v1/school-groups/{$this->schoolUid}/members")
            ->assertOk()
            ->assertJsonCount(2, 'data.members');

        $this->deleteJson("/api/v1/school-groups/{$this->schoolUid}/members/{$memberId}")
            ->assertOk();

        $this->assertTrue(AuditLog::where('action', 'SYNC')->exists());

        Http::assertSent(fn (HttpClientRequest $req) => str_contains($req->url(), '/api/v1/publics/schools'));
        Http::assertSent(fn (HttpClientRequest $req) => str_contains($req->url(), '/api/v1/groups'));
    }
}
