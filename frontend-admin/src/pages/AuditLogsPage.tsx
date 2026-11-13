import React, { useCallback, useEffect, useState } from 'react'
import { Download, Eye, FileClock, RefreshCw, Search, X } from 'lucide-react'
import { Layout } from '../components/Layout'
import { api } from '../services/api'

interface AuditItem {
  id: number
  user_name: string | null
  user_email: string | null
  user_role: string | null
  app_source: string
  action: string
  resource_type: string | null
  resource_id: string | null
  description: string | null
  old_values: Record<string, unknown> | null
  new_values: Record<string, unknown> | null
  ip_address: string | null
  user_agent: string | null
  created_at: string
}

interface AuditStats {
  total: number
  today: number
  creates: number
  updates: number
  deletes: number
}

export const AuditLogsPage: React.FC = () => {
  const [logs, setLogs] = useState<AuditItem[]>([])
  const [stats, setStats] = useState<AuditStats>({ total: 0, today: 0, creates: 0, updates: 0, deletes: 0 })
  const [loading, setLoading] = useState(true)
  const [exporting, setExporting] = useState(false)
  const [page, setPage] = useState(1)
  const [lastPage, setLastPage] = useState(1)
  const [selected, setSelected] = useState<AuditItem | null>(null)

  const [search, setSearch] = useState('')
  const [appSource, setAppSource] = useState('ALL')
  const [action, setAction] = useState('ALL')
  const [dateFrom, setDateFrom] = useState('')
  const [dateTo, setDateTo] = useState('')

  const fetchAll = useCallback(async (targetPage = page) => {
    setLoading(true)
    try {
      const params = {
        page: targetPage,
        per_page: 15,
        search: search || undefined,
        app_source: appSource,
        action,
        date_from: dateFrom || undefined,
        date_to: dateTo || undefined,
      }
      const [listRes, statsRes] = await Promise.all([
        api.get('/audit-logs', { params }),
        api.get('/audit-logs/stats'),
      ])
      const paginator = listRes.data.data
      setLogs(paginator.data || [])
      setPage(paginator.current_page || 1)
      setLastPage(paginator.last_page || 1)
      setStats(statsRes.data.data)
    } finally {
      setLoading(false)
    }
  }, [page, search, appSource, action, dateFrom, dateTo])

  useEffect(() => {
    fetchAll(1)
  }, [appSource, action, dateFrom, dateTo])

  const handleExport = async () => {
    setExporting(true)
    try {
      const res = await api.get('/audit-logs/export', {
        params: { search: search || undefined, app_source: appSource, action, date_from: dateFrom || undefined, date_to: dateTo || undefined },
        responseType: 'blob',
      })
      const url = window.URL.createObjectURL(new Blob([res.data]))
      const link = document.createElement('a')
      link.href = url
      link.setAttribute('download', `auditoria_semed_${new Date().toISOString().slice(0, 10)}.csv`)
      document.body.appendChild(link)
      link.click()
      link.remove()
    } finally {
      setExporting(false)
    }
  }

  const actionBadgeClass = (act: string) => {
    if (act === 'CREATE') return 'badge-green'
    if (act === 'UPDATE') return 'badge-blue'
    if (act === 'DELETE' || act === 'LOGIN_FAILED' || act === 'RESET') return 'badge-red'
    return 'badge-slate'
  }

  return (
    <Layout>
      <div className="page-header">
        <div>
          <h1>Auditoria de Ações &amp; Logs</h1>
          <p>Monitoramento de ações de usuários no Gestão Escolar e microsserviços conectados.</p>
        </div>
        <div className="header-actions">
          <button type="button" className="btn" onClick={handleExport} disabled={exporting || loading}>
            <Download size={16} />
            <span>{exporting ? 'Exportando...' : 'Exportar CSV'}</span>
          </button>
          <button type="button" className="btn btn-primary" onClick={() => fetchAll(page)} disabled={loading}>
            <RefreshCw size={16} />
            <span>Atualizar</span>
          </button>
        </div>
      </div>

      {/* 5 Cards de Métricas */}
      <div className="grid-5" style={{ marginBottom: '1.5rem' }}>
        <div className="kpi-box">
          <div className="kpi-label">Total de Ações</div>
          <div className="kpi-val">{stats.total}</div>
        </div>
        <div className="kpi-box">
          <div className="kpi-label">Ações Hoje</div>
          <div className="kpi-val">{stats.today}</div>
        </div>
        <div className="kpi-box">
          <div className="kpi-label">Cadastros</div>
          <div className="kpi-val" style={{ color: 'var(--brand-green)' }}>{stats.creates}</div>
        </div>
        <div className="kpi-box">
          <div className="kpi-label">Alterações</div>
          <div className="kpi-val" style={{ color: 'var(--brand-blue)' }}>{stats.updates}</div>
        </div>
        <div className="kpi-box error">
          <div className="kpi-label">Exclusões</div>
          <div className="kpi-val" style={{ color: 'var(--brand-red)' }}>{stats.deletes}</div>
        </div>
      </div>

      <div className="section-card">
        <div className="filters-bar">
          <div style={{ display: 'flex', gap: '0.4rem' }}>
            <input
              type="text"
              className="form-control"
              placeholder="Buscar usuário, ação, IP..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => e.key === 'Enter' && fetchAll(1)}
            />
            <button type="button" className="btn" onClick={() => fetchAll(1)} title="Buscar">
              <Search size={15} />
            </button>
          </div>

          <select className="form-control" value={appSource} onChange={(e) => setAppSource(e.target.value)}>
            <option value="ALL">Todas as Aplicações</option>
            <option value="gestao-escolar-admin">gestao-escolar-admin</option>
            <option value="sgps">SGPS</option>
            <option value="nutrirede">NutriRede</option>
            <option value="semed-lp">SEMED-LP</option>
            <option value="semed-rm">SEMED-RM</option>
            <option value="progsaed">ProgSAED</option>
            <option value="progpse">ProgPSE</option>
          </select>

          <select className="form-control" value={action} onChange={(e) => setAction(e.target.value)}>
            <option value="ALL">Todas as Ações</option>
            <option value="LOGIN">LOGIN</option>
            <option value="LOGIN_FAILED">LOGIN_FAILED</option>
            <option value="LOGOUT">LOGOUT</option>
            <option value="CREATE">CREATE</option>
            <option value="UPDATE">UPDATE</option>
            <option value="DELETE">DELETE</option>
            <option value="RESET">RESET</option>
          </select>

          <input type="date" className="form-control" value={dateFrom} onChange={(e) => setDateFrom(e.target.value)} />
          <input type="date" className="form-control" value={dateTo} onChange={(e) => setDateTo(e.target.value)} />
        </div>

        <div className="table-wrap">
          <table className="data-table">
            <thead>
              <tr>
                <th>Data/Hora</th>
                <th>Usuário</th>
                <th>Aplicação</th>
                <th>Ação</th>
                <th>Descrição</th>
                <th>IP</th>
                <th>Detalhes</th>
              </tr>
            </thead>
            <tbody>
              {loading ? (
                <tr>
                  <td colSpan={7} style={{ textAlign: 'center', padding: '2rem', color: '#64748b' }}>
                    Carregando registros de auditoria...
                  </td>
                </tr>
              ) : logs.length === 0 ? (
                <tr>
                  <td colSpan={7} style={{ textAlign: 'center', padding: '2rem', color: '#64748b' }}>
                    Nenhum registro encontrado para os filtros selecionados.
                  </td>
                </tr>
              ) : (
                logs.map((item) => (
                  <tr key={item.id}>
                    <td style={{ whiteSpace: 'nowrap' }}>
                      {new Date(item.created_at).toLocaleString('pt-BR')}
                    </td>
                    <td>
                      <div style={{ fontWeight: 600 }}>{item.user_name || '—'}</div>
                      <div style={{ fontSize: '0.75rem', color: '#64748b' }}>{item.user_email || ''}</div>
                    </td>
                    <td>
                      <span className="badge badge-slate">{item.app_source}</span>
                    </td>
                    <td>
                      <span className={`badge ${actionBadgeClass(item.action)}`}>{item.action}</span>
                    </td>
                    <td>{item.description || '—'}</td>
                    <td style={{ fontFamily: 'monospace', fontSize: '0.8rem' }}>{item.ip_address || '—'}</td>
                    <td>
                      <button type="button" className="btn" onClick={() => setSelected(item)}>
                        <Eye size={14} />
                        <span>Ver</span>
                      </button>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>

        {lastPage > 1 && (
          <div style={{ display: 'flex', justifyContent: 'flex-end', gap: '0.5rem', marginTop: '1rem' }}>
            <button type="button" className="btn" disabled={page <= 1} onClick={() => fetchAll(page - 1)}>
              Anterior
            </button>
            <span style={{ alignSelf: 'center', fontSize: '0.85rem', color: '#475569' }}>
              Página {page} de {lastPage}
            </span>
            <button type="button" className="btn" disabled={page >= lastPage} onClick={() => fetchAll(page + 1)}>
              Próxima
            </button>
          </div>
        )}
      </div>

      {selected && (
        <div className="modal-backdrop" onClick={() => setSelected(null)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
                <FileClock size={18} />
                <h3 style={{ margin: 0 }}>Evento de Auditoria #{selected.id}</h3>
              </div>
              <button type="button" className="btn" onClick={() => setSelected(null)}>
                <X size={16} />
              </button>
            </div>
            <p><strong>Usuário:</strong> {selected.user_name} ({selected.user_email || 'sem e-mail'})</p>
            <p><strong>Role:</strong> {selected.user_role || '—'}</p>
            <p><strong>Aplicação:</strong> {selected.app_source} | <strong>Ação:</strong> {selected.action}</p>
            <p><strong>IP:</strong> {selected.ip_address || '—'} | <strong>User-Agent:</strong> {selected.user_agent || '—'}</p>
            <p><strong>Descrição:</strong> {selected.description || '—'}</p>
            {selected.old_values && (
              <>
                <strong>Valores Anteriores:</strong>
                <pre className="code-block">{JSON.stringify(selected.old_values, null, 2)}</pre>
              </>
            )}
            {selected.new_values && (
              <>
                <strong>Novos Valores:</strong>
                <pre className="code-block">{JSON.stringify(selected.new_values, null, 2)}</pre>
              </>
            )}
          </div>
        </div>
      )}
    </Layout>
  )
}
