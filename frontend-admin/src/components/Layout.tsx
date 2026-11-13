import React from 'react'
import { NavLink, useNavigate } from 'react-router-dom'
import { Activity, FileClock, LogOut, Server } from 'lucide-react'
import { useAuth } from '../contexts/AuthContext'

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
            <NavLink to="/audit-logs" className={({ isActive }) => `nav-link ${isActive ? 'active' : ''}`}>
              <FileClock size={16} />
              <span>Auditoria de Logs</span>
            </NavLink>
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
