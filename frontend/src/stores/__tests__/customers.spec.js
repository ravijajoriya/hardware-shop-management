import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useCustomerStore } from '../customers'

const customer = { id: 4, name: 'Ramesh Kumar', mobile: '9876543210', total_due: 600 }
const shop = { name: 'Sharma Hardware', address: null, phone: null }

function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), { status })
}

describe('customer store', () => {
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

  it('loads a page of customers, only those with dues when asked', async () => {
    const meta = { current_page: 1, last_page: 1, from: 1, to: 1, total: 1 }
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [customer], meta }))
    const store = useCustomerStore()

    await store.fetchPage({ search: '', with_due: '1', page: '' })

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/customers\?with_due=1$/)
    expect(store.customers).toEqual([customer])
    expect(store.meta).toEqual(meta)
  })

  it('looks a customer up by mobile number', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: customer }))
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: null }))
    const store = useCustomerStore()

    expect(await store.lookup('9876543210')).toEqual(customer)
    expect(await store.lookup('9123456789')).toBeNull()

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/customers\/lookup\?mobile=9876543210$/)
  })

  it('loads a customer with the shop details', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: customer, shop }))
    const store = useCustomerStore()

    expect(await store.fetchOne(4)).toEqual({ customer, shop })
    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/customers\/4$/)
  })

  it('takes a payment towards dues and says which bills it cleared', async () => {
    const appliedTo = [{ bill_number: 'INV-2026-0001', amount: 400 }]
    fetchMock.mockResolvedValue(jsonResponse(200, { data: customer, applied_to: appliedTo, shop }))
    const store = useCustomerStore()
    const payment = { method: 'upi', amount: 400, reference: 'UPI778899' }

    expect(await store.receivePayment(4, payment)).toEqual({ customer, appliedTo, shop })

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/customers\/4\/payments$/)
    expect(options.method).toBe('POST')
    expect(JSON.parse(options.body)).toEqual(payment)
  })
})
