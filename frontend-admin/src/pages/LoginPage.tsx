import React, { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Lock, Server, ShieldCheck } from 'lucide-react'
import { useAuth } from '../contexts/AuthContext'

export const LoginPage: React.FC = () => {
  const { login } = useAuth()
  const navigate = useNavigate()
  const [loginInput, setLoginInput] = useState('')
  const [password, setPassword] = useState('')
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    setError(null)
    setSubmitting(true)
    try {
      await login(loginInput, password)
      navigate('/')
    } catch (err: any) {
      const msg =
        err?.response?.data?.message ||
        'Não foi possível autenticar. Verifique suas credenciais do Portal Semed.'
      setError(msg)
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="login-page">
      <div className="login-card">
        <div style={{ display: 'flex', alignItems: 'center', gap: '0.75rem', marginBottom: '1rem' }}>
          <div className="metric-icon blue">
            <Server size={22} />
          </div>
          <div>
            <h1 style={{ margin: 0, fontSize: '1.25rem', fontWeight: 800 }}>Gestão Escolar SEMED</h1>
            <p style={{ margin: 0, fontSize: '0.8rem', color: '#64748b' }}>
              Painel Administrativo &amp; Observabilidade (`/admin`)
            </p>
          </div>
        </div>

        <div
          style={{
            background: '#eff6ff',
            border: '1px solid #bfdbfe',
            color: '#1e3a8a',
            borderRadius: 8,
            padding: '0.6rem 0.75rem',
            fontSize: '0.78rem',
            display: 'flex',
            alignItems: 'center',
            gap: '0.5rem',
            marginBottom: '1.25rem',
          }}
        >
          <ShieldCheck size={16} style={{ flexShrink: 0 }} />
          <span>
            Autenticação via <strong>Portal Semed</strong> (perfis: Master, Administrador do Sistema ou Gestor do Sistema).
          </span>
        </div>

        {error && <div className="alert-error">{error}</div>}

        <form onSubmit={handleSubmit}>
          <div className="login-field">
            <label htmlFor="login">E-mail ou CPF</label>
            <input
              id="login"
              type="text"
              className="form-control"
              placeholder="usuario@vilavelha.es.gov.br ou CPF"
              value={loginInput}
              onChange={(e) => setLoginInput(e.target.value)}
              required
            />
          </div>

          <div className="login-field">
            <label htmlFor="password">Senha</label>
            <input
              id="password"
              type="password"
              className="form-control"
              placeholder="••••••••"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </div>

          <button
            type="submit"
            className="btn btn-primary"
            style={{ width: '100%', justifyContent: 'center', padding: '0.65rem' }}
            disabled={submitting}
          >
            <Lock size={16} />
            <span>{submitting ? 'Autenticando...' : 'Entrar no Painel'}</span>
          </button>
        </form>
      </div>
    </div>
  )
}
