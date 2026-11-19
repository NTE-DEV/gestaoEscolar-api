import React, { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Building2,
  Pencil,
  Plus,
  Power,
  RefreshCw,
  School,
  Search,
  Trash2,
  UserPlus,
  Users,
  X,
} from 'lucide-react'
import { Layout } from '../components/Layout'
import { api } from '../services/api'

interface SchoolGroupItem {
  id: number
  school_uid: string
  school_name: string | null
  unit_type: 'school' | 'sector' | string
  school_inep: string | null
  active: boolean
  last_synced_at: string | null
  members_count: number
  active_members_count?: number
  created_at?: string
  updated_at?: string
}

interface GroupStats {
  total_groups: number
  active_groups: number
  schools_count: number
  sectors_count: number
  total_members: number
}

interface PortalUnitItem {
  uid: string
  name: string
  type: 'school' | 'sector'
  type_label?: string
  code?: string | null
  email?: string | null
  phone?: string | null
  active?: boolean
}

interface GroupMemberItem {
  id: number
  school_group_id: number
  school_uid: string
  portal_user_id: number | null
  portal_user_uid: string | null
  name: string | null
  email: string | null
  cpf: string | null
  role: string
  active: boolean
  created_at?: string
}

interface GroupFormState {
  school_uid: string
  school_name: string
  unit_type: 'school' | 'sector'
  school_inep: string
  active: boolean
}

interface MemberFormState {
  name: string
  email: string
  cpf: string
  role: string
  active: boolean
}

const INITIAL_GROUP_FORM: GroupFormState = {
  school_uid: '',
  school_name: '',
  unit_type: 'school',
  school_inep: '',
  active: true,
}

const INITIAL_MEMBER_FORM: MemberFormState = {
  name: '',
  email: '',
  cpf: '',
  role: 'Membro Escolar',
  active: true,
}

function extractApiError(err: unknown, fallback: string): string {
  const maybeErr = err as {
    response?: {
      data?: {
        message?: string
        errors?: Record<string, string[] | string>
      }
    }
  }
  const data = maybeErr?.response?.data
  if (data?.errors && typeof data.errors === 'object') {
    const firstKey = Object.keys(data.errors)[0]
    if (firstKey) {
      const val = data.errors[firstKey]
      if (Array.isArray(val) && val.length > 0) {
        return val[0]
      }
      if (typeof val === 'string' && val.trim() !== '') {
        return val
      }
    }
  }
  if (typeof data?.message === 'string' && data.message.trim() !== '') {
    return data.message
  }
  return fallback
}

function formatCpf(raw: string | null): string {
  if (!raw) return '—'
  const digits = raw.replace(/\D/g, '')
  if (digits.length !== 11) return raw
  return `${digits.slice(0, 3)}.${digits.slice(3, 6)}.${digits.slice(6, 9)}-${digits.slice(9)}`
}

export const GroupsPage: React.FC = () => {
  const [groups, setGroups] = useState<SchoolGroupItem[]>([])
  const [stats, setStats] = useState<GroupStats>({
    total_groups: 0,
    active_groups: 0,
    schools_count: 0,
    sectors_count: 0,
    total_members: 0,
  })
  const [loading, setLoading] = useState(true)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)

  const [search, setSearch] = useState('')
  const [unitTypeFilter, setUnitTypeFilter] = useState('ALL')
  const [statusFilter, setStatusFilter] = useState('all')

  const [feedback, setFeedback] = useState<{ type: 'success' | 'error'; message: string } | null>(null)

  // Modal Criar / Editar Grupo
  const [groupModalOpen, setGroupModalOpen] = useState(false)
  const [editingGroup, setEditingGroup] = useState<SchoolGroupItem | null>(null)
  const [groupForm, setGroupForm] = useState<GroupFormState>(INITIAL_GROUP_FORM)
  const [groupSubmitting, setGroupSubmitting] = useState(false)
  const [groupModalError, setGroupModalError] = useState<string | null>(null)

  // Unidades do Portal SEMED para seleção rápida ao criar
  const [portalUnits, setPortalUnits] = useState<PortalUnitItem[]>([])
  const [unitsLoaded, setUnitsLoaded] = useState(false)
  const [unitsLoading, setUnitsLoading] = useState(false)
  const [unitsWarning, setUnitsWarning] = useState<string | null>(null)
  const [unitSearch, setUnitSearch] = useState('')

  // Modal Excluir Grupo
  const [deletingGroup, setDeletingGroup] = useState<SchoolGroupItem | null>(null)
  const [deleteSubmitting, setDeleteSubmitting] = useState(false)

  // Modal Gerenciar Membros
  const [membersGroup, setMembersGroup] = useState<SchoolGroupItem | null>(null)
  const [members, setMembers] = useState<GroupMemberItem[]>([])
  const [membersLoading, setMembersLoading] = useState(false)
  const [membersError, setMembersError] = useState<string | null>(null)
  const [membersSuccess, setMembersSuccess] = useState<string | null>(null)
  const [memberFormOpen, setMemberFormOpen] = useState(false)
  const [editingMember, setEditingMember] = useState<GroupMemberItem | null>(null)
  const [memberForm, setMemberForm] = useState<MemberFormState>(INITIAL_MEMBER_FORM)
  const [memberSubmitting, setMemberSubmitting] = useState(false)
  const [syncingPortal, setSyncingPortal] = useState(false)

  const fetchGroups = useCallback(
    async (targetPage = page) => {
      setLoading(true)
      try {
        const res = await api.get('/school-groups', {
          params: {
            page: targetPage,
            per_page: 15,
            search: search.trim() || undefined,
            unit_type: unitTypeFilter !== 'ALL' ? unitTypeFilter : undefined,
            active: statusFilter !== 'all' ? statusFilter : undefined,
          },
        })
        const paginator = res.data?.data || {}
        setGroups(paginator.data || [])
        setPage(paginator.current_page || 1)
        setLastPage(paginator.last_page || 1)
        if (res.data?.stats) {
          setStats(res.data.stats)
        }
      } catch (err) {
        setFeedback({
          type: 'error',
          message: extractApiError(err, 'Não foi possível carregar os grupos cadastrados.'),
        })
      } finally {
        setLoading(false)
      }
    },
    [page, search, unitTypeFilter, statusFilter],
  )

  useEffect(() => {
    fetchGroups(1)
  }, [unitTypeFilter, statusFilter])

  const loadPortalUnitsOnce = useCallback(async () => {
    if (unitsLoaded || unitsLoading) return
    setUnitsLoading(true)
    setUnitsWarning(null)
    try {
      const res = await api.get('/units', { params: { per_page: 250 } })
      setPortalUnits(res.data?.data || [])
      setUnitsLoaded(true)
    } catch (err) {
      setUnitsWarning(
        extractApiError(
          err,
          'Não foi possível consultar a lista automática do Portal SEMED. Você ainda pode preencher o UID manualmente abaixo.',
        ),
      )
    } finally {
      setUnitsLoading(false)
    }
  }, [unitsLoaded, unitsLoading])

  const filteredPortalUnits = useMemo(() => {
    const q = unitSearch.trim().toLowerCase()
    if (!q) return portalUnits
    return portalUnits.filter((u) => {
      const name = (u.name || '').toLowerCase()
      const code = (u.code || '').toLowerCase()
      const uid = (u.uid || '').toLowerCase()
      return name.includes(q) || code.includes(q) || uid.includes(q)
    })
  }, [portalUnits, unitSearch])

  const openCreateModal = () => {
    setEditingGroup(null)
    setGroupForm(INITIAL_GROUP_FORM)
    setGroupModalError(null)
    setUnitSearch('')
    setGroupModalOpen(true)
    loadPortalUnitsOnce()
  }

  const openEditModal = (item: SchoolGroupItem) => {
    setEditingGroup(item)
    setGroupForm({
      school_uid: item.school_uid,
      school_name: item.school_name || '',
      unit_type: item.unit_type === 'sector' ? 'sector' : 'school',
      school_inep: item.school_inep || '',
      active: Boolean(item.active),
    })
    setGroupModalError(null)
    setGroupModalOpen(true)
  }

  const handleSelectPortalUnit = (uid: string) => {
    const found = portalUnits.find((u) => u.uid === uid)
    if (!found) {
      setGroupForm((prev) => ({ ...prev, school_uid: uid }))
      return
    }
    setGroupForm((prev) => ({
      ...prev,
      school_uid: found.uid,
      school_name: found.name,
      unit_type: found.type === 'sector' ? 'sector' : 'school',
      school_inep: found.code || '',
    }))
  }

  const handleGroupSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setGroupModalError(null)
    setGroupSubmitting(true)

    try {
      if (editingGroup) {
        await api.patch(`/school-groups/${editingGroup.school_uid}`, {
          school_name: groupForm.school_name.trim(),
          unit_type: groupForm.unit_type,
          school_inep: groupForm.school_inep.trim() || null,
          active: groupForm.active,
        })
        setFeedback({ type: 'success', message: 'Grupo atualizado com sucesso.' })
      } else {
        await api.post('/school-groups', {
          school_uid: groupForm.school_uid.trim(),
          school_name: groupForm.school_name.trim(),
          unit_type: groupForm.unit_type,
          school_inep: groupForm.school_inep.trim() || null,
          active: groupForm.active,
        })
        setFeedback({ type: 'success', message: 'Grupo criado com sucesso.' })
      }
      setGroupModalOpen(false)
      await fetchGroups(editingGroup ? page : 1)
    } catch (err) {
      setGroupModalError(extractApiError(err, 'Erro ao salvar grupo.'))
    } finally {
      setGroupSubmitting(false)
    }
  }

  const handleToggleGroupActive = async (item: SchoolGroupItem) => {
    setFeedback(null)
    try {
      await api.patch(`/school-groups/${item.school_uid}`, {
        active: !item.active,
      })
      setFeedback({
        type: 'success',
        message: `Grupo "${item.school_name || item.school_uid}" ${!item.active ? 'ativado' : 'inativado'} com sucesso.`,
      })
      await fetchGroups(page)
    } catch (err) {
      setFeedback({
        type: 'error',
        message: extractApiError(err, 'Erro ao alterar status do grupo.'),
      })
    }
  }

  const handleConfirmDeleteGroup = async () => {
    if (!deletingGroup) return
    setDeleteSubmitting(true)
    try {
      await api.delete(`/school-groups/${deletingGroup.school_uid}`)
      setFeedback({
        type: 'success',
        message: `Grupo "${deletingGroup.school_name || deletingGroup.school_uid}" removido com sucesso.`,
      })
      setDeletingGroup(null)
      await fetchGroups(1)
    } catch (err) {
      setFeedback({
        type: 'error',
        message: extractApiError(err, 'Erro ao excluir grupo.'),
      })
      setDeletingGroup(null)
    } finally {
      setDeleteSubmitting(false)
    }
  }

  const loadGroupMembers = useCallback(async (schoolUid: string) => {
    setMembersLoading(true)
    setMembersError(null)
    try {
      const res = await api.get(`/school-groups/${schoolUid}/members`)
      setMembers(res.data?.data?.members || [])
    } catch (err) {
      setMembersError(extractApiError(err, 'Não foi possível carregar os membros do grupo.'))
    } finally {
      setMembersLoading(false)
    }
  }, [])

  const openMembersModal = (item: SchoolGroupItem) => {
    setMembersGroup(item)
    setMembers([])
    setMembersError(null)
    setMembersSuccess(null)
    setMemberFormOpen(false)
    setEditingMember(null)
    setMemberForm({
      ...INITIAL_MEMBER_FORM,
      role: item.unit_type === 'sector' ? 'Membro Setorial' : 'Membro Escolar',
    })
    loadGroupMembers(item.school_uid)
  }

  const openNewMemberForm = () => {
    setEditingMember(null)
    setMemberForm({
      ...INITIAL_MEMBER_FORM,
      role: membersGroup?.unit_type === 'sector' ? 'Membro Setorial' : 'Membro Escolar',
    })
    setMemberFormOpen(true)
    setMembersError(null)
  }

  const openEditMemberForm = (m: GroupMemberItem) => {
    setEditingMember(m)
    setMemberForm({
      name: m.name || '',
      email: m.email || '',
      cpf: m.cpf || '',
      role: m.role || 'Membro',
      active: Boolean(m.active),
    })
    setMemberFormOpen(true)
    setMembersError(null)
  }

  const handleMemberSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    if (!membersGroup) return
    setMemberSubmitting(true)
    setMembersError(null)
    setMembersSuccess(null)

    try {
      if (editingMember) {
        await api.patch(`/school-groups/${membersGroup.school_uid}/members/${editingMember.id}`, {
          name: memberForm.name.trim() || null,
          email: memberForm.email.trim() || null,
          cpf: memberForm.cpf.trim() || null,
          role: memberForm.role.trim() || 'Membro',
          active: memberForm.active,
        })
        setMembersSuccess('Membro atualizado com sucesso.')
      } else {
        await api.post(`/school-groups/${membersGroup.school_uid}/members`, {
          school_name: membersGroup.school_name,
          unit_type: membersGroup.unit_type === 'sector' ? 'sector' : 'school',
          school_inep: membersGroup.school_inep,
          name: memberForm.name.trim() || null,
          email: memberForm.email.trim() || null,
          cpf: memberForm.cpf.trim() || null,
          role: memberForm.role.trim() || 'Membro',
          active: memberForm.active,
        })
        setMembersSuccess('Membro vinculado ao grupo com sucesso.')
      }

      setMemberFormOpen(false)
      setEditingMember(null)
      await Promise.all([loadGroupMembers(membersGroup.school_uid), fetchGroups(page)])
    } catch (err) {
      setMembersError(extractApiError(err, 'Erro ao salvar membro no grupo.'))
    } finally {
      setMemberSubmitting(false)
    }
  }

  const handleToggleMemberActive = async (m: GroupMemberItem) => {
    if (!membersGroup) return
    setMembersError(null)
    setMembersSuccess(null)
    try {
      await api.patch(`/school-groups/${membersGroup.school_uid}/members/${m.id}`, {
        active: !m.active,
      })
      setMembersSuccess(`Vínculo de ${m.name || m.email || m.cpf} ${!m.active ? 'ativado' : 'inativado'}.`)
      await Promise.all([loadGroupMembers(membersGroup.school_uid), fetchGroups(page)])
    } catch (err) {
      setMembersError(extractApiError(err, 'Erro ao atualizar status do membro.'))
    }
  }

  const handleRemoveMember = async (m: GroupMemberItem) => {
    if (!membersGroup) return
    setMembersError(null)
    setMembersSuccess(null)
    try {
      await api.delete(`/school-groups/${membersGroup.school_uid}/members/${m.id}`)
      setMembersSuccess('Membro removido do grupo.')
      await Promise.all([loadGroupMembers(membersGroup.school_uid), fetchGroups(page)])
    } catch (err) {
      setMembersError(extractApiError(err, 'Erro ao remover membro do grupo.'))
    }
  }

  const handleSyncPortal = async () => {
    if (!membersGroup) return
    setSyncingPortal(true)
    setMembersError(null)
    setMembersSuccess(null)
    try {
      const res = await api.post(`/school-groups/${membersGroup.school_uid}/sync`, {
        school_name: membersGroup.school_name,
        unit_type: membersGroup.unit_type === 'sector' ? 'sector' : 'school',
      })
      const count = res.data?.data?.synced_count ?? 0
      setMembersSuccess(`Sincronização concluída: ${count} membro(s) importado(s)/atualizado(s) do Portal SEMED.`)
      await Promise.all([loadGroupMembers(membersGroup.school_uid), fetchGroups(page)])
    } catch (err) {
      setMembersError(extractApiError(err, 'Falha ao sincronizar membros com o Portal SEMED.'))
    } finally {
      setSyncingPortal(false)
    }
  }

  return (
    <Layout>
      <div className="page-header">
        <div>
          <h1>Gestão de Grupos</h1>
          <p>Cadastro e controle de grupos de unidades (escolas e setores) e seus membros autorizados.</p>
        </div>
        <div className="header-actions">
          <button type="button" className="btn" onClick={() => fetchGroups(page)} disabled={loading}>
            <RefreshCw size={16} />
            <span>Atualizar</span>
          </button>
          <button type="button" className="btn btn-primary" onClick={openCreateModal}>
            <Plus size={16} />
            <span>Novo Grupo</span>
          </button>
        </div>
      </div>

      {/* 5 Cards KPI */}
      <div className="grid-5" style={{ marginBottom: '1.5rem' }}>
        <div className="kpi-box">
          <div className="kpi-label">Total de Grupos</div>
          <div className="kpi-val">{stats.total_groups}</div>
        </div>
        <div className="kpi-box">
          <div className="kpi-label">Grupos Ativos</div>
          <div className="kpi-val" style={{ color: 'var(--brand-green)' }}>{stats.active_groups}</div>
        </div>
        <div className="kpi-box">
          <div className="kpi-label">Escolas</div>
          <div className="kpi-val" style={{ color: 'var(--brand-blue)' }}>{stats.schools_count}</div>
        </div>
        <div className="kpi-box">
          <div className="kpi-label">Setores</div>
          <div className="kpi-val" style={{ color: 'var(--brand-cyan)' }}>{stats.sectors_count}</div>
        </div>
        <div className="kpi-box">
          <div className="kpi-label">Total de Membros</div>
          <div className="kpi-val">{stats.total_members}</div>
        </div>
      </div>

      {feedback && (
        <div className={feedback.type === 'success' ? 'alert-success' : 'alert-error'}>
          <span>{feedback.message}</span>
          <button
            type="button"
            className="btn btn-sm"
            onClick={() => setFeedback(null)}
            aria-label="Fechar aviso"
          >
            <X size={14} />
          </button>
        </div>
      )}

      <div className="section-card">
        <div className="filters-bar">
          <div style={{ display: 'flex', gap: '0.4rem' }}>
            <input
              type="text"
              className="form-control"
              placeholder="Buscar por nome, UID ou INEP..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && fetchGroups(1)}
            />
            <button type="button" className="btn" onClick={() => fetchGroups(1)} title="Buscar">
              <Search size={15} />
            </button>
          </div>

          <select
            className="form-control"
            value={unitTypeFilter}
            onChange={(e) => setUnitTypeFilter(e.target.value)}
          >
            <option value="ALL">Todos os Tipos (Escolas e Setores)</option>
            <option value="school">Somente Escolas</option>
            <option value="sector">Somente Setores</option>
          </select>

          <select
            className="form-control"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value)}
          >
            <option value="all">Todos os Status</option>
            <option value="1">Somente Ativos</option>
            <option value="0">Somente Inativos</option>
          </select>
        </div>

        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Unidade / Grupo</th>
                <th>Tipo</th>
                <th>Membros</th>
                <th>Status</th>
                <th>Última Sincronização</th>
                <th>Ações</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={6} style={{ textAlign: 'center', padding: '2rem', color: '#64748b' }}>
                    Carregando grupos cadastrados...
                  </td>
                </tr>
              ) : groups.length === 0 ? (
                <tr>
                  <td colSpan={6} style={{ textAlign: 'center', padding: '2rem', color: '#64748b' }}>
                    Nenhum grupo encontrado. Clique em &ldquo;Novo Grupo&rdquo; para cadastrar.
                  </td>
                </tr>
              ) : (
                groups.map((item) => {
                  const isSector = item.unit_type === 'sector'
                  const inactiveCount =
                    typeof item.active_members_count === 'number'
                      ? item.members_count - item.active_members_count
                      : 0

                  return (
                    <tr key={item.id}>
                      <td>
                        <div style={{ fontWeight: 700 }}>{item.school_name || 'Sem nome'}</div>
                        <div style={{ fontFamily: 'monospace', fontSize: '0.75rem', color: '#64748b' }}>
                          {item.school_uid}
                        </div>
                        {item.school_inep && (
                          <div style={{ fontSize: '0.75rem', color: '#475569' }}>
                            INEP/Cód: {item.school_inep}
                          </div>
                        )}
                      </td>
                      <td>
                        <span className={`badge ${isSector ? 'badge-cyan' : 'badge-blue'}`}>
                          {isSector ? <Building2 size={12} /> : <School size={12} />}
                          <span>{isSector ? 'Setor' : 'Escola'}</span>
                        </span>
                      </td>
                      <td>
                        <button
                          type="button"
                          className="btn btn-sm"
                          onClick={() => openMembersModal(item)}
                          title="Gerenciar membros deste grupo"
                        >
                          <Users size={13} />
                          <span>{item.members_count} membro(s)</span>
                        </button>
                        {inactiveCount > 0 && typeof item.active_members_count === 'number' && (
                          <div style={{ fontSize: '0.72rem', color: '#64748b', marginTop: '0.25rem' }}>
                            ({item.active_members_count} ativo(s))
                          </div>
                        )}
                      </td>
                      <td>
                        <span className={`badge ${item.active ? 'badge-green' : 'badge-red'}`}>
                          {item.active ? 'Ativo' : 'Inativo'}
                        </span>
                      </td>
                      <td style={{ whiteSpace: 'nowrap', fontSize: '0.82rem', color: '#475569' }}>
                        {item.last_synced_at
                          ? new Date(item.last_synced_at).toLocaleString('pt-BR')
                          : 'Cadastro manual'}
                      </td>
                      <td>
                        <div className="actions-cell">
                          <button
                            type="button"
                            className="btn btn-sm"
                            onClick={() => openMembersModal(item)}
                            title="Membros do grupo"
                          >
                            <Users size={13} />
                            <span>Membros</span>
                          </button>
                          <button
                            type="button"
                            className="btn btn-sm"
                            onClick={() => openEditModal(item)}
                            title="Editar grupo"
                          >
                            <Pencil size={13} />
                            <span>Editar</span>
                          </button>
                          <button
                            type="button"
                            className="btn btn-sm"
                            onClick={() => handleToggleGroupActive(item)}
                            title={item.active ? 'Inativar grupo' : 'Ativar grupo'}
                          >
                            <Power size={13} />
                            <span>{item.active ? 'Inativar' : 'Ativar'}</span>
                          </button>
                          <button
                            type="button"
                            className="btn btn-sm btn-danger"
                            onClick={() => setDeletingGroup(item)}
                            title="Excluir grupo"
                          >
                            <Trash2 size={13} />
                            <span>Excluir</span>
                          </button>
                        </div>
                      </td>
                    </tr>
                  )
                })
              )}
            </tbody>
          </table>
        </div>

        {lastPage > 1 && (
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.5rem', marginTop: '1rem' }}>
            <button type="button" className="btn" disabled={page <= 1} onClick={() => fetchGroups(page - 1)}>
              Anterior
            </button>
            <span style={{ alignSelf: 'center', fontSize: '0.85rem', color: '#475569' }}>
              Página {page} de {lastPage}
            </span>
            <button
              type="button"
              className="btn"
              disabled={page >= lastPage}
              onClick={() => fetchGroups(page + 1)}
            >
              Próxima
            </button>
          </div>
        )}
      </div>

      {/* Modal Criar / Editar Grupo */}
      {groupModalOpen && (
        <div className="modal-backdrop" onClick={() => setGroupModalOpen(false)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <Users size={18} />
                <h3 style={{ margin: 0 }}>{editingGroup ? 'Editar Grupo' : 'Novo Grupo (Escola ou Setor)'}</h3>
              </div>
              <button type="button" className="btn btn-sm" onClick={() => setGroupModalOpen(false)}>
                <X size={16} />
              </button>
            </div>

            {groupModalError && <div className="alert-error">{groupModalError}</div>}

            {!editingGroup && (
              <div className="inline-panel">
                <div className="form-group" style={{ marginBottom: '0.6rem' }}>
                  <label htmlFor="portal-unit-filter">Selecionar Escola ou Setor do Portal SEMED</label>
                  <input
                    id="portal-unit-filter"
                    type="text"
                    className="form-control"
                    placeholder="Filtrar lista por nome, INEP ou UID..."
                    value={unitSearch}
                    onChange={(e) => setUnitSearch(e.target.value)}
                    style={{ marginBottom: '0.5rem' }}
                  />
                  <select
                    className="form-control"
                    value={groupForm.school_uid}
                    onChange={(e) => handleSelectPortalUnit(e.target.value)}
                    disabled={unitsLoading}
                  >
                    <option value="">
                      {unitsLoading
                        ? 'Carregando unidades do Portal SEMED...'
                        : '— Selecione uma escola ou setor (ou preencha manualmente) —'}
                    </option>
                    {filteredPortalUnits.map((u) => (
                      <option key={u.uid} value={u.uid}>
                        [{u.type === 'sector' ? 'Setor' : 'Escola'}] {u.name}
                        {u.code ? ` — INEP ${u.code}` : ''}
                      </option>
                    ))}
                  </select>
                </div>
                {unitsWarning && (
                  <div style={{ fontSize: '0.78rem', color: '#92400e', background: '#fef3c7', padding: '0.5rem 0.65rem', borderRadius: '6px' }}>
                    {unitsWarning}
                  </div>
                )}
              </div>
            )}

            <form onSubmit={handleGroupSubmit}>
              <div className="form-group">
                <label htmlFor="group-uid">UID da Unidade (UUID) *</label>
                <input
                  id="group-uid"
                  type="text"
                  className="form-control"
                  value={groupForm.school_uid}
                  onChange={(e) => setGroupForm((prev) => ({ ...prev, school_uid: e.target.value }))}
                  placeholder="9d8a12b4-4e56-4c89-a123-7890abcdef12"
                  disabled={Boolean(editingGroup)}
                  required
                />
                <div className="form-hint">Identificador UUID oficial da escola ou setor no Portal SEMED.</div>
              </div>

              <div className="form-group">
                <label htmlFor="group-name">Nome da Unidade / Grupo *</label>
                <input
                  id="group-name"
                  type="text"
                  className="form-control"
                  value={groupForm.school_name}
                  onChange={(e) => setGroupForm((prev) => ({ ...prev, school_name: e.target.value }))}
                  placeholder="Ex.: UMEF Alger Ribeiro Bossois ou Alimentação Escolar"
                  required
                />
              </div>

              <div className="form-grid-2">
                <div className="form-group">
                  <label htmlFor="group-type">Tipo de Unidade *</label>
                  <select
                    id="group-type"
                    className="form-control"
                    value={groupForm.unit_type}
                    onChange={(e) =>
                      setGroupForm((prev) => ({
                        ...prev,
                        unit_type: e.target.value === 'sector' ? 'sector' : 'school',
                      }))
                    }
                  >
                    <option value="school">Escola</option>
                    <option value="sector">Setor</option>
                  </select>
                </div>

                <div className="form-group">
                  <label htmlFor="group-inep">Código INEP / Sigla</label>
                  <input
                    id="group-inep"
                    type="text"
                    className="form-control"
                    value={groupForm.school_inep}
                    onChange={(e) => setGroupForm((prev) => ({ ...prev, school_inep: e.target.value }))}
                    placeholder="Opcional (ex.: 32066309)"
                  />
                </div>
              </div>

              <label className="checkbox-row">
                <input
                  type="checkbox"
                  checked={groupForm.active}
                  onChange={(e) => setGroupForm((prev) => ({ ...prev, active: e.target.checked }))}
                />
                <span>Grupo ativo para autenticação (Passo 1)</span>
              </label>

              <div className="modal-footer">
                <button type="button" className="btn" onClick={() => setGroupModalOpen(false)}>
                  Cancelar
                </button>
                <button type="submit" className="btn btn-primary" disabled={groupSubmitting}>
                  {groupSubmitting ? 'Salvando...' : editingGroup ? 'Salvar Alterações' : 'Criar Grupo'}
                </button>
              </div>
            </form>
          </div>
        </div>
      )}

      {/* Modal Gerenciar Membros */}
      {membersGroup && (
        <div className="modal-backdrop" onClick={() => setMembersGroup(null)}>
          <div className="modal-card modal-lg" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                  <Users size={18} />
                  <h3 style={{ margin: 0 }}>Membros: {membersGroup.school_name || membersGroup.school_uid}</h3>
                </div>
                <div style={{ fontSize: '0.78rem', color: '#64748b', marginTop: '0.2rem' }}>
                  {membersGroup.unit_type === 'sector' ? 'Setor' : 'Escola'} •{' '}
                  <span style={{ fontFamily: 'monospace' }}>{membersGroup.school_uid}</span>
                </div>
              </div>
              <button type="button" className="btn btn-sm" onClick={() => setMembersGroup(null)}>
                <X size={16} />
              </button>
            </div>

            {membersError && <div className="alert-error">{membersError}</div>}
            {membersSuccess && (
              <div className="alert-success">
                <span>{membersSuccess}</span>
                <button type="button" className="btn btn-sm" onClick={() => setMembersSuccess(null)}>
                  <X size={14} />
                </button>
              </div>
            )}

            <div style={{ display: 'flex', justifyContent: 'space-between', flexWrap: 'wrap', gap: '0.5rem', marginBottom: '1rem' }}>
              <button type="button" className="btn btn-primary btn-sm" onClick={openNewMemberForm}>
                <UserPlus size={14} />
                <span>+ Novo Membro</span>
              </button>
              <button
                type="button"
                className="btn btn-cyan btn-sm"
                onClick={handleSyncPortal}
                disabled={syncingPortal}
              >
                <RefreshCw size={14} />
                <span>{syncingPortal ? 'Sincronizando...' : 'Sincronizar Portal SEMED'}</span>
              </button>
            </div>

            {memberFormOpen && (
              <form className="inline-panel" onSubmit={handleMemberSubmit}>
                <h4 style={{ margin: '0 0 0.75rem', fontSize: '0.92rem' }}>
                  {editingMember ? `Editar Membro #${editingMember.id}` : 'Vincular Novo Membro ao Grupo'}
                </h4>
                <div className="form-grid-2">
                  <div className="form-group">
                    <label htmlFor="member-name">Nome</label>
                    <input
                      id="member-name"
                      type="text"
                      className="form-control"
                      value={memberForm.name}
                      onChange={(e) => setMemberForm((prev) => ({ ...prev, name: e.target.value }))}
                      placeholder="Nome completo"
                    />
                  </div>
                  <div className="form-group">
                    <label htmlFor="member-email">E-mail</label>
                    <input
                      id="member-email"
                      type="email"
                      className="form-control"
                      value={memberForm.email}
                      onChange={(e) => setMemberForm((prev) => ({ ...prev, email: e.target.value }))}
                      placeholder="usuario@edu.vilavelha.es.gov.br"
                    />
                  </div>
                  <div className="form-group">
                    <label htmlFor="member-cpf">CPF</label>
                    <input
                      id="member-cpf"
                      type="text"
                      className="form-control"
                      value={memberForm.cpf}
                      onChange={(e) => setMemberForm((prev) => ({ ...prev, cpf: e.target.value }))}
                      placeholder="000.000.000-00"
                    />
                  </div>
                  <div className="form-group">
                    <label htmlFor="member-role">Papel no Grupo</label>
                    <input
                      id="member-role"
                      type="text"
                      className="form-control"
                      value={memberForm.role}
                      onChange={(e) => setMemberForm((prev) => ({ ...prev, role: e.target.value }))}
                      placeholder="Ex.: Gestor Escolar, Nutricionista"
                    />
                  </div>
                </div>
                <label className="checkbox-row">
                  <input
                    type="checkbox"
                    checked={memberForm.active}
                    onChange={(e) => setMemberForm((prev) => ({ ...prev, active: e.target.checked }))}
                  />
                  <span>Membro ativo</span>
                </label>
                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.5rem', marginTop: '0.75rem' }}>
                  <button
                    type="button"
                    className="btn btn-sm"
                    onClick={() => {
                      setMemberFormOpen(false)
                      setEditingMember(null)
                    }}
                  >
                    Cancelar
                  </button>
                  <button type="submit" className="btn btn-primary btn-sm" disabled={memberSubmitting}>
                    {memberSubmitting ? 'Salvando...' : editingMember ? 'Salvar Membro' : 'Vincular Membro'}
                  </button>
                </div>
              </form>
            )}

            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Nome</th>
                    <th>E-mail</th>
                    <th>CPF</th>
                    <th>Papel</th>
                    <th>Status</th>
                    <th>Ações</th>
                  </tr>
                </thead>
                <tbody>
                  {membersLoading ? (
                    <tr>
                      <td colSpan={6} style={{ textAlign: 'center', padding: '1.5rem', color: '#64748b' }}>
                        Carregando membros...
                      </td>
                    </tr>
                  ) : members.length === 0 ? (
                    <tr>
                      <td colSpan={6} style={{ textAlign: 'center', padding: '1.5rem', color: '#64748b' }}>
                        Nenhum membro vinculado a este grupo ainda.
                      </td>
                    </tr>
                  ) : (
                    members.map((m) => (
                      <tr key={m.id}>
                        <td style={{ fontWeight: 600 }}>{m.name || '—'}</td>
                        <td>{m.email || '—'}</td>
                        <td style={{ fontFamily: 'monospace', fontSize: '0.8rem' }}>{formatCpf(m.cpf)}</td>
                        <td>
                          <span className="badge badge-slate">{m.role || 'Membro'}</span>
                        </td>
                        <td>
                          <span className={`badge ${m.active ? 'badge-green' : 'badge-red'}`}>
                            {m.active ? 'Ativo' : 'Inativo'}
                          </span>
                        </td>
                        <td>
                          <div className="actions-cell">
                            <button
                              type="button"
                              className="btn btn-sm"
                              onClick={() => openEditMemberForm(m)}
                              title="Editar membro"
                            >
                              <Pencil size={12} />
                            </button>
                            <button
                              type="button"
                              className="btn btn-sm"
                              onClick={() => handleToggleMemberActive(m)}
                              title={m.active ? 'Inativar vínculo' : 'Ativar vínculo'}
                            >
                              <Power size={12} />
                            </button>
                            <button
                              type="button"
                              className="btn btn-sm btn-danger"
                              onClick={() => handleRemoveMember(m)}
                              title="Remover do grupo"
                            >
                              <Trash2 size={12} />
                            </button>
                          </div>
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}

      {/* Modal Confirmar Exclusão de Grupo */}
      {deletingGroup && (
        <div className="modal-backdrop" onClick={() => !deleteSubmitting && setDeletingGroup(null)}>
          <div className="modal-card" style={{ maxWidth: '480px' }} onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', color: 'var(--brand-red)' }}>
                <Trash2 size={18} />
                <h3 style={{ margin: 0 }}>Confirmar Exclusão</h3>
              </div>
              <button
                type="button"
                className="btn btn-sm"
                onClick={() => setDeletingGroup(null)}
                disabled={deleteSubmitting}
              >
                <X size={16} />
              </button>
            </div>

            <p style={{ margin: '0 0 0.75rem' }}>
              Tem certeza que deseja excluir o grupo{' '}
              <strong>{deletingGroup.school_name || deletingGroup.school_uid}</strong>?
            </p>
            <div style={{ fontFamily: 'monospace', fontSize: '0.78rem', color: '#64748b', marginBottom: '0.75rem' }}>
              UID: {deletingGroup.school_uid}
            </div>

            {deletingGroup.members_count > 0 && (
              <div className="alert-error" style={{ marginBottom: '0.75rem' }}>
                <span>
                  Atenção: este grupo possui <strong>{deletingGroup.members_count} membro(s)</strong> vinculado(s).
                  Todos os vínculos serão removidos em cascata.
                </span>
              </div>
            )}

            <div className="modal-footer">
              <button
                type="button"
                className="btn"
                onClick={() => setDeletingGroup(null)}
                disabled={deleteSubmitting}
              >
                Cancelar
              </button>
              <button
                type="button"
                className="btn btn-danger"
                onClick={handleConfirmDeleteGroup}
                disabled={deleteSubmitting}
              >
                <Trash2 size={14} />
                <span>{deleteSubmitting ? 'Excluindo...' : 'Excluir Grupo'}</span>
              </button>
            </div>
          </div>
        </div>
      )}
    </Layout>
  )
}
