import axios from 'axios'

export function resolveApiBaseUrl(): string {
  if (import.meta.env.VITE_API_BASE_URL) {
    return import.meta.env.VITE_API_BASE_URL
  }
  return window.location.pathname.startsWith('/dev/admin') ? '/GE/dev/v1' : '/GE/v1'
}

export function resolveRouterBasename(): string {
  if (window.location.pathname.startsWith('/dev/admin')) {
    return '/dev/admin'
  }
  if (window.location.pathname.startsWith('/admin')) {
    return '/admin'
  }
  return '/'
}

export const api = axios.create({
  baseURL: resolveApiBaseUrl(),
  headers: {
    Accept: 'application/json',
    'Content-Type': 'application/json',
  },
})

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('ge_admin_token')
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  return config
})

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error?.response?.status === 401) {
      localStorage.removeItem('ge_admin_token')
      localStorage.removeItem('ge_admin_user')
      const base = resolveRouterBasename()
      const loginPath = base === '/' ? '/login' : `${base}/login`
      if (!window.location.pathname.endsWith('/login')) {
        window.location.href = loginPath
      }
    }
    return Promise.reject(error)
  },
)
