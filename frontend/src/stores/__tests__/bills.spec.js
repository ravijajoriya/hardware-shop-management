import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useAuthStore } from '../auth'
import { useBillStore } from '../bills'

const bill = { id: 9, bill_number: 'INV-2026-0009', total: 880 }
const shop = { name: 'Sharma Hardware', address: 'MG Road, Jaipur', phone: null }

function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), { status })
}

describe('bill store', () => {
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

  it('loads a page with only the filters that are set', async () => {
    const meta = { current_page: 1, last_page: 1, from: 1, to: 1, total: 1 }
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [bill], meta }))
    const store = useBillStore()

    await store.fetchPage({ search: 'ramesh', status: '', page: '' })

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/bills\?search=ramesh$/)
    expect(options.headers.Authorization).toBe('Bearer abc123')
    expect(store.bills).toEqual([bill])
    expect(store.meta).toEqual(meta)
  })

  it('loads a bill with the shop details to print on it', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: bill, shop }))
    const store = useBillStore()

    expect(await store.fetchOne(9)).toEqual({ bill, shop })
    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/bills\/9$/)
  })

  it('makes a bill', async () => {
    fetchMock.mockResolvedValue(jsonResponse(201, { data: bill }))
    const store = useBillStore()
    const payload = { customer: { name: 'Ramesh', mobile: '9876543210' }, items: [], payments: [] }

    expect(await store.create(payload)).toEqual(bill)

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/bills$/)
    expect(options.method).toBe('POST')
    expect(JSON.parse(options.body)).toEqual(payload)
  })

  it('passes on the messages for a bill the server rejects', async () => {
    const errors = { 'items.0.quantity': ['Piece (pc) is sold in whole numbers.'] }
    fetchMock.mockResolvedValue(
      jsonResponse(422, { message: errors['items.0.quantity'][0], errors }),
    )
    const store = useBillStore()

    await expect(store.create({})).rejects.toMatchObject({ status: 422, errors })
  })

  it('ends the session when the token is no longer accepted', async () => {
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
    const store = useBillStore()

    await expect(store.fetchPage()).rejects.toMatchObject({ status: 401 })

    expect(useAuthStore().isAuthenticated).toBe(false)
  })
})
