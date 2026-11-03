import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useReportStore } from '../reports'

describe('report store', () => {
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

  it('loads the sales report for a period', async () => {
    const report = { from: '2026-11-01', to: '2026-11-30', summary: { net_sales: 880 } }
    fetchMock.mockResolvedValue(new Response(JSON.stringify({ data: report }), { status: 200 }))
    const store = useReportStore()

    await store.fetchSales('2026-11-01', '2026-11-30')

    expect(fetchMock.mock.calls[0][0]).toMatch(
      /\/admin\/reports\/sales\?from=2026-11-01&to=2026-11-30$/,
    )
    expect(store.sales).toEqual(report)
  })
})
