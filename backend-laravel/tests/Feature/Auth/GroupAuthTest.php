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
    private string $sectorUid = 'f25224ef-1b3d-4136-a9a1-ee9fd2b03020';

    public function test_step1_denies_user_not_in_school_group_without_calling_portal(): void
    {
        Http::fake();

        $group = SchoolGroup::create([
            'school_uid' => $this->schoolUid,
            'school_name' => 'UMEF Teste',
            'unit_type' => 'school',
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
            'unit_type' => 'school',
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
            'school_name' => 'UMEF Paulo Maresguia',
            'active' => true,
        ]);

        SchoolGroupMember::create([
            'school_group_id' => $group->id,
            'school_uid' => $this->schoolUid,
            'cpf' => '12345678900',
            'email' => 'membro.cpf@semed.vilavelha.es.gov.br',
            'role' => 'Secretário Escolar',
            'active' => true,
        ]);

        $res = $this->postJson('/api/v1/auth/group-login', [
            'school_uid' => $this->schoolUid,
            'login' => '123.456.789-00',
            'password' => 'senha-errada',
        ]);

        $res->assertStatus(401)
            ->assertJsonPath('success', false)
            ->assertJsonPath('step', 'portal_semed_authentication');

        Http::assertSent(function (HttpClientRequest $request) {
            return str_contains($request->url(), '/api/v1/login')
                && $request['login'] === '12345678900'
                && $request['origin'] === 'portal';
        });

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_GROUP_FAILED',
            'resource_id' => $this->schoolUid,
        ]);
    }

    public function test_step1_and_step2_success_creates_sanctum_session_with_unit_and_school_scope(): void
    {
        Http::fake([
            '*/api/v1/login' => Http::response([
                'success' => true,
                'user' => [
                    'id' => 500,
                    'uid' => 'cccc3333-3333-4333-8333-333333333333',
                    'name' => 'Professor Silva',
                    'email' => 'professor.silva@semed.vilavelha.es.gov.br',
                    'cpf' => '55566677788',
                    'roles' => [['name' => 'Professor']],
                ],
                'token' => 'portal-mock-token',
            ], 200),
        ]);

        $group = SchoolGroup::create([
            'school_uid' => $this->schoolUid,
            'school_name' => 'UMEF Praia da Costa',
            'active' => true,
        ]);

        $member = SchoolGroupMember::create([
            'school_group_id' => $group->id,
            'school_uid' => $this->schoolUid,
            'email' => 'professor.silva@semed.vilavelha.es.gov.br',
            'role' => 'Professor Articulador',
            'active' => true,
        ]);

        $res = $this->postJson('/api/v1/auth/group-login', [
            'unit_uid' => $this->schoolUid,
            'login' => 'PROFESSOR.SILVA@semed.vilavelha.es.gov.br',
            'password' => 'senha123',
            'device_id' => 'pc-sala-professores',
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.portal_user_id', 500)
            ->assertJsonPath('data.user.email', 'professor.silva@semed.vilavelha.es.gov.br')
            ->assertJsonPath('data.unit_group.uid', $this->schoolUid)
            ->assertJsonPath('data.school_group.school_uid', $this->schoolUid)
            ->assertJsonPath('data.school_group.member_role', 'Professor Articulador');

        $tokenStr = $res->json('data.token');
        $this->assertNotEmpty($tokenStr);

        $member->refresh();
        $this->assertSame(500, $member->portal_user_id);
        $this->assertNotNull($member->local_user_id);

        $user = User::where('portal_user_id', 500)->first();
        $this->assertNotNull($user);

        // Valida abilities escopadas
        $pat = PersonalAccessToken::findToken($tokenStr);
        $this->assertNotNull($pat);
        $this->assertTrue($pat->can('unit:' . $this->schoolUid));
        $this->assertTrue($pat->can('school:' . $this->schoolUid));
        $this->assertTrue($pat->can('group-auth'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_GROUP',
            'resource_id' => $this->schoolUid,
            'user_id' => $user->id,
        ]);
    }

    public function test_step1_and_step2_success_on_sector_group(): void
    {
        Http::fake([
            '*/api/v1/login' => Http::response([
                'success' => true,
                'user' => [
                    'id' => 600,
                    'uid' => 'dddd4444-4444-4444-8444-444444444444',
                    'name' => 'Nutricionista Chefe',
                    'email' => 'nutri@edu.vilavelha.es.gov.br',
                    'cpf' => '99988877766',
                    'roles' => [['name' => 'Nutricionista']],
                ],
                'token' => 'portal-mock-token-sector',
            ], 200),
        ]);

        $sectorGroup = SchoolGroup::create([
            'school_uid' => $this->sectorUid,
            'school_name' => 'Alimentação Escolar',
            'unit_type' => 'sector',
            'active' => true,
        ]);

        $member = SchoolGroupMember::create([
            'school_group_id' => $sectorGroup->id,
            'school_uid' => $this->sectorUid,
            'email' => 'nutri@edu.vilavelha.es.gov.br',
            'role' => 'Nutricionista RT',
            'active' => true,
        ]);

        $res = $this->postJson('/api/v1/auth/group-login', [
            'unit_uid' => $this->sectorUid,
            'login' => 'nutri@edu.vilavelha.es.gov.br',
            'password' => 'nutri123',
        ]);

        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.unit_group.type', 'sector')
            ->assertJsonPath('data.unit_group.name', 'Alimentação Escolar')
            ->assertJsonPath('data.unit_group.uid', $this->sectorUid);

        $tokenStr = $res->json('data.token');
        $pat = PersonalAccessToken::findToken($tokenStr);
        $this->assertTrue($pat->can('unit:' . $this->sectorUid));
    }

    public function test_anti_spoofing_detects_mismatch_between_step1_and_step2(): void
    {
        Http::fake([
            '*/api/v1/login' => Http::response([
                'success' => true,
                'user' => [
                    'id' => 999,
                    'email' => 'outro.usuario@semed.vilavelha.es.gov.br',
                    'cpf' => '00011122233',
                    'roles' => [['name' => 'Aluno']],
                ],
                'token' => 'fake-token',
            ], 200),
        ]);

        $group = SchoolGroup::create([
            'school_uid' => $this->schoolUid,
            'school_name' => 'UMEF Ilha da Jussara',
            'active' => true,
        ]);

        SchoolGroupMember::create([
            'school_group_id' => $group->id,
            'school_uid' => $this->schoolUid,
            'email' => 'vitima@semed.vilavelha.es.gov.br',
            'cpf' => '99988877766',
            'role' => 'Coordenador',
            'active' => true,
        ]);

        $this->postJson('/api/v1/auth/group-login', [
            'school_uid' => $this->schoolUid,
            'login' => 'vitima@semed.vilavelha.es.gov.br',
            'password' => 'qualquer',
        ])
            ->assertStatus(403)
            ->assertJsonPath('step', 'school_group_validation');

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'LOGIN_GROUP_DENIED',
            'resource_id' => $this->schoolUid,
        ]);
    }

    public function test_schools_endpoint_returns_unified_list_with_schools_and_sectors(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/v1/publics/schools*' => Http::response([
                'success' => true,
                'data' => [
                    'data' => [
                        ['id' => 10, 'uid' => $this->schoolUid, 'name' => 'Alger Ribeiro Bossois', 'type' => 'UMEF', 'inep' => '32066309'],
                    ],
                ],
            ], 200),
            '*/api/v1/publics/sectors*' => Http::response([
                'success' => true,
                'data' => [
                    'data' => [
                        ['id' => 13, 'uid' => $this->sectorUid, 'name' => 'Alimentação Escolar', 'type' => 'coordenacao', 'email' => 'nutri@edu.vilavelha.es.gov.br'],
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

        // 1. Lista pública unificada (ambos na mesma lista)
        $resUnified = $this->getJson('/api/v1/schools');
        $resUnified->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total_schools', 1)
            ->assertJsonPath('meta.total_sectors', 1)
            ->assertJsonPath('meta.total_units', 2);

        $types = collect($resUnified->json('data'))->pluck('type')->all();
        $this->assertContains('school', $types);
        $this->assertContains('sector', $types);

        // 2. Rota alias /api/v1/units
        $this->getJson('/api/v1/units')->assertOk();

        // 3. Filtro somente setores
        $resSectors = $this->getJson('/api/v1/schools?type=sector');
        $resSectors->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'sector')
            ->assertJsonPath('data.0.uid', $this->sectorUid);

        // 4. Filtro somente escolas
        $resSchools = $this->getJson('/api/v1/schools?type=school');
        $resSchools->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'school')
            ->assertJsonPath('data.0.uid', $this->schoolUid);

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
            'unit_type' => 'school',
            'name' => 'Maria Diretora',
            'email' => 'MARIA@semed.vilavelha.es.gov.br',
            'cpf' => '987.654.321-00',
            'role' => 'Diretor Escolar',
        ])->assertCreated();

        $memberId = $createRes->json('data.id');
        $this->assertSame('maria@semed.vilavelha.es.gov.br', $createRes->json('data.email'));
        $this->assertSame('98765432100', $createRes->json('data.cpf'));

        // Verify Passo 1 com unit_uid
        $this->postJson('/api/v1/auth/group-verify', [
            'unit_uid' => $this->schoolUid,
            'login' => '98765432100',
        ])
            ->assertOk()
            ->assertJsonPath('data.authorized', true)
            ->assertJsonPath('data.unit_type', 'school')
            ->assertJsonPath('data.member.role', 'Diretor Escolar');

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
    }

    public function test_admin_can_crud_school_groups_and_filter_list(): void
    {
        $admin = User::create([
            'portal_user_id' => 2,
            'name' => 'Master SEMED',
            'email' => 'master@semed.vilavelha.es.gov.br',
            'roles' => ['Master'],
        ]);
        Sanctum::actingAs($admin);

        // 1. Criar grupo de escola e grupo de setor
        $this->postJson('/api/v1/school-groups', [
            'school_uid' => $this->schoolUid,
            'school_name' => 'UMEF Alger Ribeiro Bossois',
            'unit_type' => 'school',
            'school_inep' => '32066309',
            'active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.school_uid', $this->schoolUid)
            ->assertJsonPath('data.unit_type', 'school');

        $this->postJson('/api/v1/school-groups', [
            'unit_uid' => $this->sectorUid,
            'school_name' => 'Alimentação Escolar',
            'unit_type' => 'sector',
            'active' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.school_uid', $this->sectorUid)
            ->assertJsonPath('data.unit_type', 'sector');

        // Tentativa duplicada do mesmo school_uid -> 422
        $this->postJson('/api/v1/school-groups', [
            'school_uid' => $this->schoolUid,
            'school_name' => 'Duplicada',
            'unit_type' => 'school',
        ])->assertStatus(422);

        // Vincula membro ao grupo escola para validar exclusão em cascata depois
        $group = SchoolGroup::where('school_uid', $this->schoolUid)->firstOrFail();
        SchoolGroupMember::create([
            'school_group_id' => $group->id,
            'school_uid' => $this->schoolUid,
            'email' => 'diretor.crud@semed.vilavelha.es.gov.br',
            'role' => 'Diretor',
            'active' => true,
        ]);

        // 2. Listagem com stats e filtros
        $this->getJson('/api/v1/school-groups')
            ->assertOk()
            ->assertJsonPath('stats.total_groups', 2)
            ->assertJsonPath('stats.active_groups', 2)
            ->assertJsonPath('stats.schools_count', 1)
            ->assertJsonPath('stats.sectors_count', 1)
            ->assertJsonPath('stats.total_members', 1);

        $this->getJson('/api/v1/school-groups?unit_type=sector')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.school_uid', $this->sectorUid);

        $this->getJson('/api/v1/school-groups?search=Alger&active=1')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.school_uid', $this->schoolUid);

        // 3. Atualizar grupo
        $this->patchJson("/api/v1/school-groups/{$this->schoolUid}", [
            'school_name' => 'UMEF Alger Ribeiro Bossois Atualizada',
            'unit_type' => 'school',
            'school_inep' => '32066310',
            'active' => false,
        ])
            ->assertOk()
            ->assertJsonPath('data.school_name', 'UMEF Alger Ribeiro Bossois Atualizada')
            ->assertJsonPath('data.school_inep', '32066310')
            ->assertJsonPath('data.active', false);

        // Valida filtro de inativos
        $this->getJson('/api/v1/school-groups?active=0')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.school_uid', $this->schoolUid);

        // 4. Excluir grupo e seus membros em cascata
        $this->deleteJson("/api/v1/school-groups/{$this->schoolUid}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('school_groups', ['school_uid' => $this->schoolUid]);
        $this->assertDatabaseMissing('school_group_members', ['school_uid' => $this->schoolUid]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'DELETE',
            'resource_type' => 'SchoolGroup',
            'resource_id' => $this->schoolUid,
        ]);
    }

    public function test_schools_endpoint_fetches_all_paginated_pages_from_portal(): void
    {
        Cache::flush();

        Http::fake([
            '*/api/v1/publics/schools*' => function (HttpClientRequest $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                $pages = [
                    1 => [
                        ['id' => 1, 'uid' => '11111111-1111-4111-8111-111111111101', 'name' => 'Escola A', 'type' => 'UMEF', 'inep' => '1001'],
                        ['id' => 2, 'uid' => '11111111-1111-4111-8111-111111111102', 'name' => 'Escola B', 'type' => 'UMEF', 'inep' => '1002'],
                    ],
                    2 => [
                        ['id' => 3, 'uid' => '11111111-1111-4111-8111-111111111103', 'name' => 'Escola C', 'type' => 'UMEI', 'inep' => '1003'],
                        ['id' => 4, 'uid' => '11111111-1111-4111-8111-111111111104', 'name' => 'Escola D', 'type' => 'UMEI', 'inep' => '1004'],
                    ],
                    3 => [
                        ['id' => 5, 'uid' => '11111111-1111-4111-8111-111111111105', 'name' => 'Escola E', 'type' => 'UMEF', 'inep' => '1005'],
                    ],
                ];

                return Http::response([
                    'success' => true,
                    'data' => [
                        'current_page' => $page,
                        'last_page' => 3,
                        'per_page' => 2,
                        'total' => 5,
                        'data' => $pages[$page] ?? [],
                    ],
                ], 200);
            },
            '*/api/v1/publics/sectors*' => function (HttpClientRequest $request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                $pages = [
                    1 => [
                        ['id' => 10, 'uid' => '22222222-2222-4222-8222-222222222201', 'name' => 'Setor Alpha', 'type' => 'coordenacao'],
                    ],
                    2 => [
                        ['id' => 11, 'uid' => '22222222-2222-4222-8222-222222222202', 'name' => 'Setor Beta', 'type' => 'gerencia'],
                    ],
                ];

                return Http::response([
                    'success' => true,
                    'data' => [
                        'current_page' => $page,
                        'last_page' => 2,
                        'per_page' => 1,
                        'total' => 2,
                        'data' => $pages[$page] ?? [],
                    ],
                ], 200);
            },
        ]);

        $res = $this->getJson('/api/v1/units');
        $res->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('meta.total_schools', 5)
            ->assertJsonPath('meta.total_sectors', 2)
            ->assertJsonPath('meta.total_units', 7)
            ->assertJsonCount(7, 'data');
    }
}
