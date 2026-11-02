import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useDashboardStore } from '../dashboard'

describe('dashboard store', () => {
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

  it("loads today's figures", async () => {
    const today = {
      date: '2026-11-02',
      bills: 2,
      sales: 1200,
      collected: { cash: 200, upi: 300, bank_transfer: 500, total: 1000 },
      due: { total: 500, customers: 2 },
    }
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: today }), { status: 200 }))
    const store = useDashboardStore()

    await store.fetchToday()

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/dashboard\/summary$/)
    expect(store.today).toEqual(today)
  })
})
