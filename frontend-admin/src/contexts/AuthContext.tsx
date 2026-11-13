import React, { createContext, useContext, useEffect, useState } from 'react'
import { api } from '../services/api'

export interface AdminUser {
  id: number
  portal_user_id: number | null
  name: string
  email: string
  cpf: string | null
  roles: string[]
}

interface AuthContextValue {
  user: AdminUser | null
  token: string | null
  loading: boolean
  isMaster: boolean
  login: (login: string, password: string) => Promise<void>
  logout: () => Promise<void>
}

const AuthContext = createContext<AuthContextValue | undefined>(undefined)

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [token, setToken] = useState<string | null>(() => localStorage.getItem('ge_admin_token'))
  const [user, setUser] = useState<AdminUser | null>(() => {
    const raw = localStorage.getItem('ge_admin_user')
    return raw ? (JSON.parse(raw) as AdminUser) : null
  })
  const [loading, setLoading] = useState<boolean>(Boolean(token && !user))

  useEffect(() => {
    if (token && !user) {
      api
        .get('/auth/me')
        .then((res) => {
          const loaded = res.data.data as AdminUser
          setUser(loaded)
          localStorage.setItem('ge_admin_user', JSON.stringify(loaded))
        })
        .catch(() => {
          localStorage.removeItem('ge_admin_token')
          localStorage.removeItem('ge_admin_user')
          setToken(null)
          setUser(null)
        })
        .finally(() => setLoading(false))
    } else {
      setLoading(false)
    }
  }, [token, user])

  const login = async (loginInput: string, password: string) => {
    const deviceId =
      localStorage.getItem('ge_device_id') ||
      (() => {
        const id = `ge-web-${Math.random().toString(36).slice(2, 10)}`
        localStorage.setItem('ge_device_id', id)
        return id
      })()

    const res = await api.post('/auth/login', {
      login: loginInput,
      password,
      device_id: deviceId,
    })

    const nextToken = res.data.data.token as string
    const nextUser = res.data.data.user as AdminUser

    localStorage.setItem('ge_admin_token', nextToken)
    localStorage.setItem('ge_admin_user', JSON.stringify(nextUser))
    setToken(nextToken)
    setUser(nextUser)
  }

  const logout = async () => {
    try {
      await api.post('/auth/logout')
    } catch {
      // ignora erro de rede ao sair
    } finally {
      localStorage.removeItem('ge_admin_token')
      localStorage.removeItem('ge_admin_user')
      setToken(null)
      setUser(null)
    }
  }

  const isMaster = Boolean(
    user?.roles?.some((r) => r.trim().toLowerCase() === 'master'),
  )

  return (
    <AuthContext.Provider value={{ user, token, loading, isMaster, login, logout }}>
      {children}
    </AuthContext.Provider>
  )
}

export function useAuth(): AuthContextValue {
  const ctx = useContext(AuthContext)
  if (!ctx) {
    throw new Error('useAuth deve ser usado dentro de AuthProvider')
  }
  return ctx
}
