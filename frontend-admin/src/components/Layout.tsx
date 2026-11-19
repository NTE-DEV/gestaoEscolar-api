import React from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { Activity, BookOpen, FileClock, LogOut, Server, Users } from 'lucide-react'
import { useAuth } from '../contexts/AuthContext'
import { resolveSwaggerUrl } from '../services/api'

export const Layout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, logout } = useAuth()
  const navigate = useNavigate()

  const handleLogout = async () => {
    await logout()
    navigate('/login')
  }

  return (
    <div>
      <header className="topbar">
        <div className="topbar-left">
          <div className="brand">
            <span className="brand-badge">
              <Server size={20} />
            </span>
            <span>Gestão Escolar SEMED</span>
          </div>

          <nav className="nav-links">
            <NavLink to="/" end className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}>
              <Activity size={16} />
              <span>Monitoramento</span>
            </NavLink>
            <NavLink to="/groups" className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}>
              <Users size={16} />
              <span>Gestão de Grupos</span>
            </NavLink>
            <NavLink to="/audit-logs" className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}>
              <FileClock size={16} />
              <span>Auditoria de Logs</span>
            </NavLink>
            <a
              href={resolveSwaggerUrl()}
              target="_blank"
              rel="noopener noreferrer"
              className="nav-link"
              title="Abrir documentação interativa Swagger (OpenAPI 3.0)"
            >
              <BookOpen size={16} />
              <span>Documentação API</span>
            </a>
          </nav>
        </div>

        <div className="topbar-right">
          <div className="user-pill">
            <span className="user-pill-name">{user?.name || 'Administrador'}</span>
            <span className="user-pill-role">{user?.roles?.join(' • ') || 'Acesso Restrito'}</span>
          </div>
          <button type="button" className="btn-logout" onClick={handleLogout}>
            <LogOut size={15} />
            <span>Sair</span>
          </button>
        </div>
      </header>

      <main className="main-container">{children}</main>
    </div>
  )
}
