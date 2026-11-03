import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useStockStore } from '../stock'

const entry = { id: 4, supplier_name: 'Sharma Traders', total: 6200 }

function jsonResponse(status, body) {
  return new Response(body === null ? null : JSON.stringify(body), { status })
}

describe('stock store', () => {
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

  const call = (index = 0) => {
    const [url, options] = fetchMock.mock.calls[index]

    return { url, method: options.method, body: options.body && JSON.parse(options.body) }
  }

  it('loads a page of deliveries with only the filters that are set', async () => {
    const meta = { current_page: 1, last_page: 1, from: 1, to: 1, total: 1 }
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [entry], meta }))
    const store = useStockStore()

    await store.fetchPage({ search: 'sharma', page: '' })

    expect(call().url).toMatch(/\/admin\/stock-entries\?search=sharma$/)
    expect(store.entries).toEqual([entry])
    expect(store.meta).toEqual(meta)
  })

  it('logs, shows and deletes a delivery', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(201, { data: entry }))
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: entry }))
    fetchMock.mockResolvedValueOnce(jsonResponse(204, null))
    const store = useStockStore()
    const payload = { received_on: '2026-11-03', items: [] }

    expect(await store.create(payload)).toEqual(entry)
    expect(await store.fetchOne(4)).toEqual(entry)
    await store.remove(4)

    expect(call(0)).toMatchObject({ method: 'POST', body: payload })
    expect(call(0).url).toMatch(/\/admin\/stock-entries$/)
    expect(call(1).url).toMatch(/\/admin\/stock-entries\/4$/)
    expect(call(2)).toMatchObject({ method: 'DELETE' })
    expect(call(2).url).toMatch(/\/admin\/stock-entries\/4$/)
  })

  it('suggests supplier names used before', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: ['Gupta & Sons', 'Sharma Traders'] }))
    const store = useStockStore()

    expect(await store.supplierNames()).toEqual(['Gupta & Sons', 'Sharma Traders'])
    expect(call().url).toMatch(/\/admin\/stock-entries\/suppliers$/)
  })

  it('corrects a size’s stock and loads a product’s stock history', async () => {
    const correction = { id: 1, stock_before: 10, stock_after: 8 }
    fetchMock.mockResolvedValueOnce(jsonResponse(201, { data: correction }))
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: [{ type: 'correction' }] }))
    const store = useStockStore()
    const payload = { quantity: 8, reason: 'damaged', note: null }

    expect(await store.correct(50, payload)).toEqual(correction)
    expect(await store.history(5)).toEqual([{ type: 'correction' }])

    expect(call(0)).toMatchObject({ method: 'POST', body: payload })
    expect(call(0).url).toMatch(/\/admin\/product-variants\/50\/stock-corrections$/)
    expect(call(1).url).toMatch(/\/admin\/products\/5\/stock-history$/)
  })
})
