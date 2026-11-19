import React, { useCallback, useEffect, useState } from 'react'
import {
  ArcElement,
  BarElement,
  CategoryScale,
  Chart as ChartJS,
  Filler,
  Legend,
  LineElement,
  LinearScale,
  PointElement,
  Tooltip,
} from 'chart.js'
import { Doughnut, Line } from 'react-chartjs-2'
import {
  BookOpen,
  Box,
  Cpu,
  ExternalLink,
  Globe,
  HardDrive,
  MemoryStick,
  RefreshCw,
  RotateCcw,
  Terminal,
  X,
} from 'lucide-react'
import { Layout } from '../components/Layout'
import { useAuth } from '../contexts/AuthContext'
import { api, resolveSwaggerUrl } from '../services/api'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Tooltip,
  Legend,
  Filler,
)

interface SystemSnapshot {
  cpu: number
  memory: number
  memory_details: { total: number; used: number; free: number }
  load_average: number[]
  uptime: string | null
  updated_at: string
}

interface HistoryDay {
  date: string
  label: string
  cpu_peak: number
  cpu_avg: number
  memory_peak: number
  memory_avg: number
  has_data: boolean
}

interface SystemHistoryData {
  window_days: number
  has_data: boolean
  summary: {
    cpu_peak_max: number
    cpu_peak_avg: number
    memory_peak_max: number
    memory_peak_avg: number
  }
  recommendation: {
    cpu_action: string
    memory_action: string
    message: string
  }
  history: HistoryDay[]
}

interface DockerContainer {
  id: string
  full_id: string
  name: string
  environment: string
  nginx_route: string | null
  state: string
  status: string
  uptime: string
  image: string
  command: string
  created_at: string | null
  ports: Array<{ host: string; host_port: number | null; container_port: number; protocol: string }>
  networks: string[]
}

interface RequestsStats {
  total_requests: number
  total_errors: number
  average_response_time_ms: number
  active_users: number
  requests_per_minute: number
  top_routes: Array<{ route: string; total: number }>
}

interface DailyPoint {
  date: string
  label: string
  total: number
  errors: number
}

interface StorageData {
  capacity_readable: string
  used_readable: string
  usage_percent: number
  images: number
  audio: number
  pdf: number
  doc: number
  excel: number
  others: number
}

interface TopIpRow {
  ip_address: string
  total: number
  errors: number
  last_seen: string
}

function formatBytes(bytes: number): string {
  if (!bytes) return '0 B'
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(1024))
  return `${(bytes / 1024 ** i).toFixed(2)} ${units[i]}`
}

export const DashboardPage: React.FC = () => {
  const { isMaster } = useAuth()
  const [loading, setLoading] = useState(true)
  const [system, setSystem] = useState<SystemSnapshot | null>(null)
  const [history, setHistory] = useState<SystemHistoryData | null>(null)
  const [containers, setContainers] = useState<DockerContainer[]>([])
  const [dockerNotice, setDockerNotice] = useState<string>('')
  const [envFilter, setEnvFilter] = useState<'all' | 'production' | 'homolog' | 'infra'>('all')
  const [selectedContainer, setSelectedContainer] = useState<DockerContainer | null>(null)
  const [containerLogs, setContainerLogs] = useState<string[]>([])
  const [loadingLogs, setLoadingLogs] = useState(false)

  const [reqStats, setReqStats] = useState<RequestsStats | null>(null)
  const [reqDaily, setReqDaily] = useState<DailyPoint[]>([])
  const [storage, setStorage] = useState<StorageData | null>(null)

  const [showTopIps, setShowTopIps] = useState(false)
  const [topIps, setTopIps] = useState<TopIpRow[]>([])

  const loadAll = useCallback(async () => {
    setLoading(true)
    try {
      const [sysRes, histRes, dockerRes, statsRes, dailyRes, storRes] = await Promise.all([
        api.get('/monitoring/system'),
        api.get('/monitoring/system-history?days=20'),
        api.get('/monitoring/docker'),
        api.get('/monitoring/requests/stats'),
        api.get('/monitoring/requests/daily?days=20'),
        api.get('/monitoring/storage'),
      ])
      setSystem(sysRes.data.data)
      setHistory(histRes.data.data)
      setContainers(dockerRes.data.data || [])
      setDockerNotice(dockerRes.data.message || '')
      setReqStats(statsRes.data.data)
      setReqDaily(dailyRes.data.data?.days || [])
      setStorage(storRes.data.data)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    loadAll()
  }, [loadAll])

  const openContainerModal = (c: DockerContainer) => {
    setSelectedContainer(c)
    setContainerLogs([])
  }

  const fetchContainerLogs = async (id: string) => {
    setLoadingLogs(true)
    try {
      const res = await api.get(`/monitoring/docker/${id}/logs?tail=100`)
      setContainerLogs(res.data.data || [])
    } finally {
      setLoadingLogs(false)
    }
  }

  const openTopIpsModal = async () => {
    setShowTopIps(true)
    const res = await api.get('/monitoring/requests/top-ips?limit=20')
    setTopIps(res.data.data || [])
  }

  const handleResetMetrics = async () => {
    if (!window.confirm('Deseja realmente zerar os contadores de requisições?')) return
    await api.post('/monitoring/requests/reset')
    await loadAll()
  }

  const filteredContainers = containers.filter((c) => envFilter === 'all' || c.environment === envFilter)
  const runningCount = containers.filter((c) => c.state === 'running').length
  const exitedCount = containers.length - runningCount

  const labels20d = (history?.history || []).map((h) => h.label)

  const cpuChartData = {
    labels: labels20d,
    datasets: [
      {
        label: 'Pico Máx CPU (%)',
        data: (history?.history || []).map((h) => h.cpu_peak),
        borderColor: '#0891b2',
        backgroundColor: 'rgba(8, 145, 178, 0.12)',
        fill: true,
        tension: 0.3,
      },
      {
        label: 'Média Diária (%)',
        data: (history?.history || []).map((h) => h.cpu_avg),
        borderColor: '#64748b',
        borderDash: [4, 4],
        tension: 0.3,
      },
    ],
  }

  const memChartData = {
    labels: labels20d,
    datasets: [
      {
        label: 'Pico Máx RAM (%)',
        data: (history?.history || []).map((h) => h.memory_peak),
        borderColor: '#ea580c',
        backgroundColor: 'rgba(234, 88, 12, 0.12)',
        fill: true,
        tension: 0.3,
      },
      {
        label: 'Média Diária (%)',
        data: (history?.history || []).map((h) => h.memory_avg),
        borderColor: '#64748b',
        borderDash: [4, 4],
        tension: 0.3,
      },
    ],
  }

  const dailyRequestsChartData = {
    labels: reqDaily.map((d) => d.label),
    datasets: [
      {
        label: 'Requisições',
        data: reqDaily.map((d) => d.total),
        borderColor: '#1d4ed8',
        backgroundColor: 'rgba(29, 78, 216, 0.1)',
        fill: true,
        tension: 0.3,
      },
      {
        label: 'Erros (4xx/5xx)',
        data: reqDaily.map((d) => d.errors),
        borderColor: '#dc2626',
        backgroundColor: 'rgba(220, 38, 38, 0.1)',
        fill: true,
        tension: 0.3,
      },
    ],
  }

  const storageChartData = {
    labels: ['Imagens', 'Áudio', 'PDFs', 'Docs', 'Excel', 'Outros'],
    datasets: [
      {
        data: [
          storage?.images || 0,
          storage?.audio || 0,
          storage?.pdf || 0,
          storage?.doc || 0,
          storage?.excel || 0,
          storage?.others || 0,
        ],
        backgroundColor: ['#3b82f6', '#10b981', '#f59e0b', '#6366f1', '#14b8a6', '#94a3b8'],
      },
    ],
  }

  const percentChartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    scales: {
      y: { min: 0, max: 100 },
    },
  }

  return (
    <Layout>
      <div className="page-header">
        <div>
          <h1>Dashboard de Monitoramento</h1>
          <p>Observabilidade do servidor 172.23.55.66 e serviços do ecossistema SEMED.</p>
        </div>
        <div className="header-actions">
          <a
            href={resolveSwaggerUrl()}
            target="_blank"
            rel="noopener noreferrer"
            className="btn btn-cyan"
            title="Abrir documentação interativa da API (Swagger / OpenAPI 3.0)"
          >
            <BookOpen size={16} />
            <span>Documentação API</span>
            <ExternalLink size={13} style={{ opacity: 0.85 }} />
          </a>
          <button type="button" className="btn btn-primary" onClick={loadAll} disabled={loading}>
            <RefreshCw size={16} />
            <span>{loading ? 'Atualizando...' : 'Atualizar'}</span>
          </button>
        </div>
      </div>

      {/* 1. CPU & Memória RAM em Tempo Real */}
      {system && (
        <div className="grid-2" style={{ marginBottom: '1.5rem' }}>
          <div className="metric-card">
            <div className="metric-icon cyan">
              <Cpu size={22} />
            </div>
            <div style={{ flex: 1 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.85rem', color: '#475569' }}>
                <strong>Uso de CPU</strong>
                <span>{system.updated_at}</span>
              </div>
              <div className="metric-value">{system.cpu}%</div>
              <div className="progress-bar">
                <div className="progress-fill" style={{ width: `${Math.min(100, system.cpu)}%`, background: '#0891b2' }} />
              </div>
              <div style={{ marginTop: '0.4rem', fontSize: '0.76rem', color: '#64748b' }}>
                Load Average: {system.load_average.join(' / ')} {system.uptime ? `• Uptime: ${system.uptime}` : ''}
              </div>
            </div>
          </div>

          <div className="metric-card">
            <div className="metric-icon orange">
              <MemoryStick size={22} />
            </div>
            <div style={{ flex: 1 }}>
              <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: '0.85rem', color: '#475569' }}>
                <strong>Memória RAM</strong>
                <span>
                  {formatBytes(system.memory_details.used)} / {formatBytes(system.memory_details.total)}
                </span>
              </div>
              <div className="metric-value">{system.memory}%</div>
              <div className="progress-bar">
                <div className="progress-fill" style={{ width: `${Math.min(100, system.memory)}%`, background: '#ea580c' }} />
              </div>
              <div style={{ marginTop: '0.4rem', fontSize: '0.76rem', color: '#64748b' }}>
                Livre: {formatBytes(system.memory_details.free)}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* 2. Picos de Uso (20 Dias) — CPU e Memória */}
      <div className="section-card">
        <div className="section-title-row">
          <div>
            <h2>Picos de Uso de Recursos (Últimos 20 Dias)</h2>
            <span style={{ fontSize: '0.8rem', color: '#64748b' }}>
              {history?.recommendation?.message || 'Monitoramento contínuo (amostras a cada 5 minutos)'}
            </span>
          </div>
          {history && (
            <div style={{ display: 'flex', gap: '0.5rem', flexWrap: 'wrap' }}>
              <span className={`badge ${history.recommendation.cpu_action === 'scale_up' ? 'badge-red' : 'badge-green'}`}>
                CPU Máx: {history.summary.cpu_peak_max}%
              </span>
              <span className={`badge ${history.recommendation.memory_action === 'scale_up' ? 'badge-red' : 'badge-green'}`}>
                RAM Máx: {history.summary.memory_peak_max}%
              </span>
            </div>
          )}
        </div>

        <div className="grid-2">
          <div>
            <h4 style={{ margin: '0 0 0.5rem', fontSize: '0.92rem' }}>
              CPU — Pico vs Média Diária (Média Picos: {history?.summary?.cpu_peak_avg ?? 0}%)
            </h4>
            <div className="chart-box">
              <Line data={cpuChartData} options={percentChartOptions} />
            </div>
          </div>
          <div>
            <h4 style={{ margin: '0 0 0.5rem', fontSize: '0.92rem' }}>
              Memória RAM — Pico vs Média Diária (Média Picos: {history?.summary?.memory_peak_avg ?? 0}%)
            </h4>
            <div className="chart-box">
              <Line data={memChartData} options={percentChartOptions} />
            </div>
          </div>
        </div>
      </div>

      {/* 3. Containers do Ecossistema */}
      <div className="section-card">
        <div className="section-title-row">
          <div style={{ display: 'flex', alignItems: 'center', gap: '0.6rem' }}>
            <Box size={20} color="#1d4ed8" />
            <div>
              <h2>Containers do Ecossistema</h2>
              <span style={{ fontSize: '0.8rem', color: '#64748b' }}>
                Serviços monitorados a partir do proxy reverso (nginx/default.conf)
              </span>
            </div>
          </div>

          <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem', flexWrap: 'wrap' }}>
            <span className="badge badge-green">{runningCount}/{containers.length} Ativo(s)</span>
            {exitedCount > 0 && <span className="badge badge-red">{exitedCount} Parado(s)</span>}
            <select
              className="form-control"
              style={{ width: 'auto' }}
              value={envFilter}
              onChange={(e) => setEnvFilter(e.target.value as any)}
            >
              <option value="all">Todos os Ambientes</option>
              <option value="production">Produção</option>
              <option value="homolog">Homologação</option>
              <option value="infra">Infraestrutura</option>
            </select>
          </div>
        </div>

        {filteredContainers.length > 0 ? (
          <div className="docker-grid">
            {filteredContainers.map((c) => (
              <div
                key={c.full_id || c.name}
                className={`docker-card ${c.state}`}
                onClick={() => openContainerModal(c)}
              >
                <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <span className={`badge ${c.state === 'running' ? 'badge-green' : 'badge-red'}`}>
                    {c.state === 'running' ? 'Ativo' : 'Parado'}
                  </span>
                  <span className="badge badge-slate">{c.environment}</span>
                </div>
                <div className="docker-name">{c.name}</div>
                <div className="docker-meta">
                  {c.nginx_route ? `Rota: ${c.nginx_route}` : c.image}
                  <br />
                  {c.uptime || c.status}
                </div>
              </div>
            ))}
          </div>
        ) : (
          <p style={{ color: '#64748b', margin: 0 }}>{dockerNotice || 'Nenhum container encontrado.'}</p>
        )}
      </div>

      {/* 4. Métricas Principais de Requisições */}
      <div className="section-card">
        <div className="section-title-row">
          <div>
            <h2>Métricas Principais de Requisições</h2>
            <span style={{ fontSize: '0.8rem', color: '#64748b' }}>
              Volume de requisições, erros 4xx/5xx e tempos de resposta
            </span>
          </div>
          <div className="header-actions">
            <button type="button" className="btn" onClick={openTopIpsModal}>
              <Globe size={15} />
              <span>Top IPs</span>
            </button>
            {isMaster && (
              <button type="button" className="btn btn-danger" onClick={handleResetMetrics}>
                <RotateCcw size={15} />
                <span>Resetar Métricas</span>
              </button>
            )}
          </div>
        </div>

        <div className="grid-5" style={{ marginBottom: '1.25rem' }}>
          <div className="kpi-box">
            <div className="kpi-label">Total de Requisições</div>
            <div className="kpi-val">{reqStats?.total_requests ?? 0}</div>
          </div>
          <div className="kpi-box error">
            <div className="kpi-label">Erros (4xx/5xx)</div>
            <div className="kpi-val" style={{ color: 'var(--brand-red)' }}>{reqStats?.total_errors ?? 0}</div>
          </div>
          <div className="kpi-box">
            <div className="kpi-label">Tempo Médio</div>
            <div className="kpi-val">{reqStats?.average_response_time_ms ?? 0} ms</div>
          </div>
          <div className="kpi-box">
            <div className="kpi-label">Usuários Conectados</div>
            <div className="kpi-val">{reqStats?.active_users ?? 0}</div>
          </div>
          <div className="kpi-box">
            <div className="kpi-label">Req. por Minuto</div>
            <div className="kpi-val">{reqStats?.requests_per_minute ?? 0}</div>
          </div>
        </div>

        <div className="grid-2">
          <div>
            <h4 style={{ margin: '0 0 0.5rem', fontSize: '0.92rem' }}>Acessos e Erros por Dia (20 dias)</h4>
            <div className="chart-box">
              <Line data={dailyRequestsChartData} options={{ responsive: true, maintainAspectRatio: false }} />
            </div>
          </div>

          <div>
            <h4 style={{ margin: '0 0 0.5rem', fontSize: '0.92rem' }}>Top Rotas Mais Chamadas</h4>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>Rota</th>
                    <th>Requisições</th>
                  </tr>
                </thead>
                <tbody>
                  {(reqStats?.top_routes || []).length === 0 ? (
                    <tr>
                      <td colSpan={2} style={{ color: '#64748b' }}>Sem requisições registradas.</td>
                    </tr>
                  ) : (
                    reqStats?.top_routes.map((r) => (
                      <tr key={r.route}>
                        <td style={{ fontFamily: 'monospace', fontSize: '0.8rem' }}>{r.route}</td>
                        <td><strong>{r.total}</strong></td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      {/* 5. Armazenamento */}
      {storage && (
        <div className="section-card">
          <div className="section-title-row">
            <div style={{ display: 'flex', alignItems: 'center', gap: '0.5rem' }}>
              <HardDrive size={20} color="#1d4ed8" />
              <h2>Armazenamento</h2>
            </div>
            <span className="badge badge-blue">
              {storage.used_readable} / {storage.capacity_readable} ({storage.usage_percent}%)
            </span>
          </div>

          <div className="grid-2">
            <div>
              <div className="progress-bar" style={{ height: '12px', marginBottom: '1rem' }}>
                <div className="progress-fill" style={{ width: `${Math.min(100, storage.usage_percent)}%`, background: '#1d4ed8' }} />
              </div>
              <table className="data-table">
                <tbody>
                  <tr><td>Imagens</td><td><strong>{storage.images}</strong></td></tr>
                  <tr><td>Áudio</td><td><strong>{storage.audio}</strong></td></tr>
                  <tr><td>PDFs</td><td><strong>{storage.pdf}</strong></td></tr>
                  <tr><td>Documentos</td><td><strong>{storage.doc}</strong></td></tr>
                  <tr><td>Planilhas Excel</td><td><strong>{storage.excel}</strong></td></tr>
                  <tr><td>Outros</td><td><strong>{storage.others}</strong></td></tr>
                </tbody>
              </table>
            </div>

            <div>
              <h4 style={{ margin: '0 0 0.5rem', fontSize: '0.92rem' }}>Proporção de Arquivos por Tipo</h4>
              <div className="chart-box">
                <Doughnut data={storageChartData} options={{ responsive: true, maintainAspectRatio: false }} />
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Modal: Detalhes do Container */}
      {selectedContainer && (
        <div className="modal-backdrop" onClick={() => setSelectedContainer(null)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3 style={{ margin: 0 }}>{selectedContainer.name}</h3>
              <button type="button" className="btn" onClick={() => setSelectedContainer(null)}>
                <X size={16} />
              </button>
            </div>
            <p><strong>ID:</strong> <code>{selectedContainer.id}</code> | <strong>Estado:</strong> {selectedContainer.state}</p>
            <p><strong>Imagem:</strong> <code>{selectedContainer.image}</code></p>
            <p><strong>Rota Nginx:</strong> <code>{selectedContainer.nginx_route || 'Interna'}</code></p>
            <p><strong>Redes:</strong> {selectedContainer.networks.join(', ') || '—'}</p>
            <p><strong>Comando SSH Logs:</strong> <code>docker logs -f --tail 100 {selectedContainer.name}</code></p>

            {isMaster && (
              <div style={{ marginTop: '1rem' }}>
                <button
                  type="button"
                  className="btn btn-primary"
                  onClick={() => fetchContainerLogs(selectedContainer.id)}
                  disabled={loadingLogs}
                >
                  <Terminal size={15} />
                  <span>{loadingLogs ? 'Carregando logs...' : 'Ver Últimas 100 Linhas de Log'}</span>
                </button>
                {containerLogs.length > 0 && (
                  <pre className="code-block" style={{ marginTop: '0.75rem' }}>
                    {containerLogs.join('\n')}
                  </pre>
                )}
              </div>
            )}
          </div>
        </div>
      )}

      {/* Modal: Top IPs */}
      {showTopIps && (
        <div className="modal-backdrop" onClick={() => setShowTopIps(false)}>
          <div className="modal-card" onClick={(e) => e.stopPropagation()}>
            <div className="modal-header">
              <h3 style={{ margin: 0 }}>Top IPs Acessados</h3>
              <button type="button" className="btn" onClick={() => setShowTopIps(false)}>
                <X size={16} />
              </button>
            </div>
            <div className="table-wrap">
              <table className="data-table">
                <thead>
                  <tr>
                    <th>IP</th>
                    <th>Requisições</th>
                    <th>Erros</th>
                    <th>Último Acesso</th>
                  </tr>
                </thead>
                <tbody>
                  {topIps.length === 0 ? (
                    <tr><td colSpan={4}>Sem registros.</td></tr>
                  ) : (
                    topIps.map((row) => (
                      <tr key={row.ip_address}>
                        <td style={{ fontFamily: 'monospace' }}>{row.ip_address}</td>
                        <td>{row.total}</td>
                        <td>{row.errors}</td>
                        <td>{new Date(row.last_seen).toLocaleString('pt-BR')}</td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      )}
    </Layout>
  )
}
