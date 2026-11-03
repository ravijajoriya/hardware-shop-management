import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import ReportsView from '../ReportsView.vue'
import { periodRange } from '@/utils/reports'

const blank = { template: '<div />' }

const report = {
  from: '2026-11-01',
  to: '2026-11-02',
  summary: {
    bills: 3,
    sales: 1230,
    returns: 350,
    net_sales: 880,
    cost: 695,
    profit: 185,
    margin: 21,
    cost_estimated: true,
    received: { cash: 700, upi: 350, bank_transfer: 100, total: 1150 },
    refunded: { cash: 350, upi: 0, bank_transfer: 0, total: 350 },
    net_received: 800,
  },
  days: [
    {
      date: '2026-11-01',
      bills: 2,
      sales: 880,
      returns: 0,
      net_sales: 880,
      cost: 695,
      profit: 185,
      margin: 21,
      received: 700,
      refunded: 0,
    },
    {
      date: '2026-11-02',
      bills: 1,
      sales: 350,
      returns: 350,
      net_sales: 0,
      cost: 0,
      profit: 0,
      margin: null,
      received: 450,
      refunded: 350,
    },
  ],
  products: [
    {
      product_id: 5,
      name: 'Claw Hammer',
      variant_name: null,
      unit: 'pc',
      quantity: 2,
      amount: 700,
      profit: 140,
    },
  ],
  customers: [{ id: 4, name: 'Ramesh Kumar', bills: 2, amount: 700, due: 0 }],
}

describe('ReportsView', () => {
  let fetchMock

  beforeEach(() => {
    localStorage.setItem('admin_token', 'abc123')
    fetchMock = vi.fn(async () => new Response(JSON.stringify({ data: report }), { status: 200 }))
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
  })

  async function open(query = {}) {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/reports', name: 'reports', component: ReportsView },
        { path: '/products/:id', name: 'product-show', component: blank },
        { path: '/customers/:id', name: 'customer-show', component: blank },
      ],
    })
    router.push({ name: 'reports', query })
    await router.isReady()

    const wrapper = mount(ReportsView, { global: { plugins: [createPinia(), router] } })
    await flushPromises()

    return { wrapper, router }
  }

  it('starts with this month', async () => {
    await open()

    const { from, to } = periodRange('this_month')
    expect(fetchMock.mock.calls[0][0]).toMatch(
      new RegExp(`/admin/reports/sales\\?from=${from}&to=${to}$`),
    )
  })

  it('shows the totals, each day and the top products and customers', async () => {
    const { wrapper } = await open({ from: '2026-11-01', to: '2026-11-02' })

    const stats = wrapper.findAll('.stat').map((stat) => stat.text())
    expect(stats[0]).toContain('₹880')
    expect(stats[0]).toContain('3 bills, ₹1,230 sold')
    expect(stats[0]).toContain('₹350 returned')
    expect(stats[1]).toContain('₹185')
    expect(stats[1]).toContain('21%')
    expect(stats[2]).toContain('₹800')
    // What stayed with the shop: ₹700 cash in, ₹350 cash back.
    expect(stats[2]).toContain('Cash ₹350 · Online ₹450')
    expect(stats[2]).toContain('₹1,150 in, ₹350 given back')
    expect(wrapper.text()).toContain('Treat it as a close estimate.')

    const days = wrapper
      .findAll('section[aria-labelledby="days-title"] tbody tr')
      .map((row) => row.text())
    expect(days[1]).toContain('−₹350')
    expect(wrapper.get('section[aria-labelledby="products-title"] a').attributes('href')).toBe(
      '/products/5',
    )
    expect(wrapper.get('section[aria-labelledby="customers-title"] a').attributes('href')).toBe(
      '/customers/4',
    )
  })

  it('changes the period through the address', async () => {
    const { wrapper, router } = await open({ from: '2026-11-01', to: '2026-11-02' })

    await wrapper
      .findAll('.presets button')
      .find((b) => b.text() === 'Last month')
      .trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual(periodRange('last_month'))
    expect(fetchMock).toHaveBeenCalledTimes(2)
  })
})
