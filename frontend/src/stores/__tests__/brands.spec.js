import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useAuthStore } from '../auth'
import { useBrandStore } from '../brands'

function brand(id, name) {
  return { id, name, slug: name.toLowerCase(), description: null, is_active: true }
}

function jsonResponse(status, body) {
  return new Response(body === null ? null : JSON.stringify(body), { status })
}

describe('brand store', () => {
  let fetchMock

  beforeEach(() => {
    localStorage.clear()
    localStorage.setItem('admin_token', 'abc123')
    setActivePinia(createPinia())
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
  })

  it('loads brands with the admin token', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [brand(1, 'Bosch')] }))
    const store = useBrandStore()

    await store.fetchAll()

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/brands$/)
    expect(options.headers.Authorization).toBe('Bearer abc123')
    expect(store.brands).toEqual([brand(1, 'Bosch')])
  })

  it('keeps brands sorted by name after changes', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(200, { data: [brand(1, 'Bosch'), brand(2, 'Stanley')] }),
    )
    fetchMock.mockResolvedValueOnce(jsonResponse(201, { data: brand(3, 'Asian Paints') }))
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: brand(2, 'Anchor') }))
    const store = useBrandStore()

    await store.fetchAll()
    await store.create({ name: 'Asian Paints' })
    await store.update(2, { name: 'Anchor' })

    expect(store.sorted.map((b) => b.name)).toEqual(['Anchor', 'Asian Paints', 'Bosch'])
  })

  it('creates a brand', async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { data: brand(7, 'Havells') }))
    const store = useBrandStore()

    await store.create({ name: 'Havells', slug: null })

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/brands$/)
    expect(options.method).toBe('POST')
    expect(JSON.parse(options.body)).toEqual({ name: 'Havells', slug: null })
    expect(store.brands).toEqual([brand(7, 'Havells')])
  })

  it('exposes field errors when the server rejects the input', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, {
        message: 'A brand with this name already exists.',
        errors: { name: ['A brand with this name already exists.'] },
      }),
    )
    const store = useBrandStore()

    await expect(store.create({ name: 'Bosch' })).rejects.toMatchObject({
      status: 422,
      errors: { name: ['A brand with this name already exists.'] },
    })

    expect(store.brands).toEqual([])
  })

  it('updates a brand in place', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(200, { data: [brand(1, 'Bosch'), brand(2, 'Stanley')] }),
    )
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: brand(2, 'Stanley Tools') }))
    const store = useBrandStore()
    await store.fetchAll()

    await store.update(2, { name: 'Stanley Tools' })

    const [url, options] = fetchMock.mock.calls[1]
    expect(url).toMatch(/\/admin\/brands\/2$/)
    expect(options.method).toBe('PUT')
    expect(store.brands).toEqual([brand(1, 'Bosch'), brand(2, 'Stanley Tools')])
  })

  it('removes a deleted brand', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(200, { data: [brand(1, 'Bosch'), brand(2, 'Stanley')] }),
    )
    fetchMock.mockResolvedValueOnce(jsonResponse(204, null))
    const store = useBrandStore()
    await store.fetchAll()

    await store.remove(2)

    const [url, options] = fetchMock.mock.calls[1]
    expect(url).toMatch(/\/admin\/brands\/2$/)
    expect(options.method).toBe('DELETE')
    expect(store.brands).toEqual([brand(1, 'Bosch')])
  })

  it('ends the session when the token is no longer accepted', async () => {
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
    const store = useBrandStore()
    const auth = useAuthStore()

    await expect(store.fetchAll()).rejects.toMatchObject({ status: 401 })

    expect(auth.isAuthenticated).toBe(false)
  })
})
