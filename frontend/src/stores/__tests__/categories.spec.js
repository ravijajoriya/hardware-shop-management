import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useAuthStore } from '../auth'
import { useCategoryStore } from '../categories'

function category(id, name, parentId = null) {
  return {
    id,
    parent_id: parentId,
    name,
    slug: name.toLowerCase(),
    description: null,
    is_active: true,
  }
}

function jsonResponse(status, body) {
  return new Response(body === null ? null : JSON.stringify(body), { status })
}

describe('category store', () => {
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

  it('loads categories with the admin token', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [category(1, 'Tools')] }))
    const store = useCategoryStore()

    await store.fetchAll()

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/categories$/)
    expect(options.headers.Authorization).toBe('Bearer abc123')
    expect(store.categories).toEqual([category(1, 'Tools')])
  })

  it('orders categories as a tree, sorted by name at each level', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, {
        data: [
          category(1, 'Tools'),
          category(2, 'Hammers', 1),
          category(3, 'Adhesives'),
          category(4, 'Drills', 1),
          category(5, 'Cordless', 4),
        ],
      }),
    )
    const store = useCategoryStore()

    await store.fetchAll()

    expect(store.tree.map(({ name, depth }) => [name, depth])).toEqual([
      ['Adhesives', 0],
      ['Tools', 0],
      ['Drills', 1],
      ['Cordless', 2],
      ['Hammers', 1],
    ])
  })

  it('finds a category together with everything beneath it', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(200, {
        data: [
          category(1, 'Tools'),
          category(2, 'Hammers', 1),
          category(3, 'Adhesives'),
          category(4, 'Drills', 1),
          category(5, 'Cordless', 4),
        ],
      }),
    )
    const store = useCategoryStore()

    await store.fetchAll()

    expect([...store.branchIds(1)].sort()).toEqual([1, 2, 4, 5])
    expect([...store.branchIds(4)].sort()).toEqual([4, 5])
    expect([...store.branchIds(3)]).toEqual([3])
  })

  it('creates a category', async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { data: category(7, 'Paint') }))
    const store = useCategoryStore()

    await store.create({ name: 'Paint', parent_id: null })

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/categories$/)
    expect(options.method).toBe('POST')
    expect(JSON.parse(options.body)).toEqual({ name: 'Paint', parent_id: null })
    expect(store.categories).toEqual([category(7, 'Paint')])
  })

  it('exposes field errors when the server rejects the input', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, {
        message: 'The slug has already been taken.',
        errors: { slug: ['The slug has already been taken.'] },
      }),
    )
    const store = useCategoryStore()

    await expect(store.create({ name: 'Paint', slug: 'paint' })).rejects.toMatchObject({
      status: 422,
      errors: { slug: ['The slug has already been taken.'] },
    })

    expect(store.categories).toEqual([])
  })

  it('updates a category in place', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(200, { data: [category(1, 'Tools'), category(2, 'Paint')] }),
    )
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: category(2, 'Paints') }))
    const store = useCategoryStore()
    await store.fetchAll()

    await store.update(2, { name: 'Paints' })

    const [url, options] = fetchMock.mock.calls[1]
    expect(url).toMatch(/\/admin\/categories\/2$/)
    expect(options.method).toBe('PUT')
    expect(store.categories).toEqual([category(1, 'Tools'), category(2, 'Paints')])
  })

  it('removes a deleted category', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(200, { data: [category(1, 'Tools'), category(2, 'Paint')] }),
    )
    fetchMock.mockResolvedValueOnce(jsonResponse(204, null))
    const store = useCategoryStore()
    await store.fetchAll()

    await store.remove(2)

    const [url, options] = fetchMock.mock.calls[1]
    expect(url).toMatch(/\/admin\/categories\/2$/)
    expect(options.method).toBe('DELETE')
    expect(store.categories).toEqual([category(1, 'Tools')])
  })

  it('ends the session when the token is no longer accepted', async () => {
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
    const store = useCategoryStore()
    const auth = useAuthStore()

    await expect(store.fetchAll()).rejects.toMatchObject({ status: 401 })

    expect(auth.isAuthenticated).toBe(false)
    expect(localStorage.getItem('admin_token')).toBeNull()
  })
})
