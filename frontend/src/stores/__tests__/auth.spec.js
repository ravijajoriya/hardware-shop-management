import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useAuthStore } from '../auth'

const admin = { id: 1, name: 'Admin', email: 'admin@example.com' }

function jsonResponse(status, body) {
  return new Response(body === null ? null : JSON.stringify(body), { status })
}

describe('auth store', () => {
  let fetchMock

  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('logs in and keeps the token', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { token: 'abc123', user: admin }))
    const auth = useAuthStore()

    await auth.login('admin@example.com', 'password')

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/login$/)
    expect(options.method).toBe('POST')
    expect(JSON.parse(options.body)).toEqual({ email: 'admin@example.com', password: 'password' })

    expect(auth.isAuthenticated).toBe(true)
    expect(auth.user).toEqual(admin)
    expect(localStorage.getItem('admin_token')).toBe('abc123')
  })

  it('reports the server message when login is rejected', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, { message: 'These credentials do not match our records.' }),
    )
    const auth = useAuthStore()

    await expect(auth.login('admin@example.com', 'wrong')).rejects.toThrow(
      'These credentials do not match our records.',
    )

    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('admin_token')).toBeNull()
  })

  it('logs out with the bearer token and clears the session', async () => {
    localStorage.setItem('admin_token', 'abc123')
    fetchMock.mockResolvedValue(jsonResponse(204, null))
    const auth = useAuthStore()

    await auth.logout()

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/logout$/)
    expect(options.method).toBe('POST')
    expect(options.headers.Authorization).toBe('Bearer abc123')

    expect(auth.isAuthenticated).toBe(false)
    expect(auth.user).toBeNull()
    expect(localStorage.getItem('admin_token')).toBeNull()
  })

  it('clears the session even when the logout request fails', async () => {
    localStorage.setItem('admin_token', 'abc123')
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
    const auth = useAuthStore()

    await auth.logout()

    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('admin_token')).toBeNull()
  })

  it('restores the admin for a stored token', async () => {
    localStorage.setItem('admin_token', 'abc123')
    fetchMock.mockResolvedValue(jsonResponse(200, admin))
    const auth = useAuthStore()

    await auth.restoreSession()

    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer abc123')
    expect(auth.user).toEqual(admin)
    expect(auth.isAuthenticated).toBe(true)
  })

  it('drops a stored token the server rejects', async () => {
    localStorage.setItem('admin_token', 'expired')
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
    const auth = useAuthStore()

    await auth.restoreSession()

    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('admin_token')).toBeNull()
  })

  it('keeps the stored token when the server is unreachable', async () => {
    localStorage.setItem('admin_token', 'abc123')
    fetchMock.mockRejectedValue(new TypeError('Failed to fetch'))
    const auth = useAuthStore()

    await auth.restoreSession()

    expect(auth.isAuthenticated).toBe(true)
    expect(localStorage.getItem('admin_token')).toBe('abc123')
  })
})
