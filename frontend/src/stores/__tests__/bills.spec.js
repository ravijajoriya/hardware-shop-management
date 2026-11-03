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
    const totals = { bills: 1, cancelled: 0, billed: 880, returned: 0, due: 0 }
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [bill], meta, totals }))
    const store = useBillStore()

    await store.fetchPage({
      search: 'ramesh',
      status: '',
      from: '2026-11-01',
      overdue: '',
      page: '',
    })

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/bills\?search=ramesh&from=2026-11-01$/)
    expect(options.headers.Authorization).toBe('Bearer abc123')
    expect(store.bills).toEqual([bill])
    expect(store.meta).toEqual(meta)
    expect(store.totals).toEqual(totals)
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

  it('edits, cancels and takes returns on a bill', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: bill, shop }))
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: bill, shop }))
    fetchMock.mockResolvedValueOnce(
      jsonResponse(200, { data: bill, shop, return_number: 'RET-2026-0001' }),
    )
    const store = useBillStore()

    expect(await store.update(9, { items: [] })).toEqual({ bill, shop })
    expect(await store.cancel(9, { reason: 'Made twice' })).toEqual({ bill, shop })
    expect(await store.createReturn(9, { items: [] })).toEqual({
      bill,
      shop,
      returnNumber: 'RET-2026-0001',
    })

    const calls = fetchMock.mock.calls.map(([url, options]) => [
      url.replace(/^.*\/api/, ''),
      options.method,
    ])
    expect(calls).toEqual([
      ['/admin/bills/9', 'PUT'],
      ['/admin/bills/9/cancel', 'POST'],
      ['/admin/bills/9/returns', 'POST'],
    ])
    expect(JSON.parse(fetchMock.mock.calls[1][1].body)).toEqual({ reason: 'Made twice' })
  })

  it('takes a payment towards one bill', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: { ...bill, due_amount: 0 }, shop }))
    const store = useBillStore()
    const payment = { method: 'cash', amount: 380, reference: null }

    expect(await store.receivePayment(9, payment)).toEqual({
      bill: { ...bill, due_amount: 0 },
      shop,
    })

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/bills\/9\/payments$/)
    expect(options.method).toBe('POST')
    expect(JSON.parse(options.body)).toEqual(payment)
  })

  it('fetches the bill as a PDF file named after its number', async () => {
    fetchMock.mockResolvedValue(
      new Response('%PDF-1.7', { status: 200, headers: { 'Content-Type': 'application/pdf' } }),
    )
    const store = useBillStore()

    const file = await store.fetchPdf(bill)

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/bills\/9\/pdf$/)
    expect(fetchMock.mock.calls[0][1].headers.Authorization).toBe('Bearer abc123')
    expect(file).toBeInstanceOf(File)
    expect(file.name).toBe('INV-2026-0009.pdf')
    expect(file.type).toBe('application/pdf')
  })

  it('reports a PDF the server could not make', async () => {
    fetchMock.mockResolvedValue(jsonResponse(500, { message: 'Server Error' }))
    const store = useBillStore()

    await expect(store.fetchPdf(bill)).rejects.toMatchObject({
      status: 500,
      message: 'Server Error',
    })
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
