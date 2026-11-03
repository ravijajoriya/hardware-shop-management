import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import BillsView from '../BillsView.vue'
import { periodRange } from '@/utils/reports'

const blank = { template: '<div />' }

const bill = {
  id: 9,
  bill_number: 'INV-2026-0009',
  created_at: '2026-11-03T06:30:00Z',
  customer: { id: 4, name: 'Ramesh Kumar', mobile: '9876543210' },
  items_count: 2,
  total: 880,
  paid_amount: 500,
  due_amount: 380,
  status: { value: 'partly_paid', label: 'Partly paid' },
}

const meta = { current_page: 1, last_page: 1, from: 1, to: 1, total: 1 }

describe('BillsView', () => {
  let fetchMock
  let totals

  beforeEach(() => {
    localStorage.setItem('admin_token', 'abc123')
    totals = { bills: 5, cancelled: 1, billed: 1580, returned: 350, due: 930 }
    fetchMock = vi.fn(
      async () => new Response(JSON.stringify({ data: [bill], meta, totals }), { status: 200 }),
    )
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
        { path: '/bills', name: 'bills', component: BillsView },
        { path: '/bills/new', name: 'bill-create', component: blank },
        { path: '/bills/:id', name: 'bill-show', component: blank },
      ],
    })
    router.push({ name: 'bills', query })
    await router.isReady()

    const wrapper = mount(BillsView, { global: { plugins: [createPinia(), router] } })
    await flushPromises()

    return { wrapper, router }
  }

  const lastUrl = () => fetchMock.mock.calls.at(-1)[0]
  const text = (wrapper, selector) => wrapper.get(selector).text().replace(/\s+/g, ' ')

  it('adds up all the matching bills, leaving cancelled ones out', async () => {
    const { wrapper } = await open()

    expect(text(wrapper, '.totals')).toBe(
      '5 bills · ₹1,580 billed after ₹350 returned · ₹930 due · 1 cancelled, not added in',
    )
  })

  it('shows only the count when every matching bill is cancelled', async () => {
    totals = { bills: 1, cancelled: 1, billed: 0, returned: 0, due: 0 }
    const { wrapper } = await open({ status: 'cancelled' })

    expect(text(wrapper, '.totals')).toBe('1 bill · 1 cancelled, not added in')
  })

  it('loads the filters and order from the address', async () => {
    const { wrapper } = await open({
      from: '2026-10-03',
      to: '2026-10-09',
      product: 'nails',
      method: 'upi',
      overdue: '30',
      changes: 'edited',
      sort: 'due',
    })

    expect(lastUrl()).toMatch(
      /\/admin\/bills\?product=nails&from=2026-10-03&to=2026-10-09&method=upi&overdue=30&changes=edited&sort=due$/,
    )
    expect(wrapper.get('#bills-date').element.value).toBe('custom')
    expect(wrapper.get('#bills-from').element.value).toBe('2026-10-03')
    expect(wrapper.get('#bills-to').element.value).toBe('2026-10-09')
    expect(wrapper.get('#bills-method').element.value).toBe('upi')
    expect(wrapper.get('#bills-overdue').element.value).toBe('30')
    expect(wrapper.get('#bills-changes').element.value).toBe('edited')
    expect(wrapper.get('#bills-sort').element.value).toBe('due')
    expect(wrapper.find('input[aria-label="Bills with this product"]').element.value).toBe('nails')
  })

  it('picks a ready-made period, or days chosen by hand', async () => {
    const { wrapper, router } = await open()
    expect(wrapper.find('#bills-from').exists()).toBe(false)

    await wrapper.get('#bills-date').setValue('this_month')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual(periodRange('this_month'))
    expect(wrapper.get('#bills-date').element.value).toBe('this_month')
    expect(wrapper.find('#bills-from').exists()).toBe(true)

    // Choosing dates by hand keeps the days until they are changed.
    await wrapper.get('#bills-date').setValue('custom')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual(periodRange('this_month'))
    expect(wrapper.get('#bills-date').element.value).toBe('custom')

    const from = wrapper.get('#bills-from')
    from.element.value = '2026-10-01'
    await from.trigger('change')
    await flushPromises()
    expect(router.currentRoute.value.query.from).toBe('2026-10-01')

    await wrapper.get('#bills-date').setValue('')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({})
    expect(wrapper.find('#bills-from').exists()).toBe(false)
  })

  it('starts again from the first page when a filter or the order changes', async () => {
    const { wrapper, router } = await open({ page: '3', status: 'unpaid' })

    await wrapper.get('#bills-method').setValue('cash')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({ status: 'unpaid', method: 'cash' })

    await router.replace({
      name: 'bills',
      query: { ...router.currentRoute.value.query, page: '2' },
    })
    await wrapper.get('#bills-sort').setValue('total')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({
      status: 'unpaid',
      method: 'cash',
      sort: 'total',
    })
  })

  it('looks for a product once typing stops, and clears every filter at once', async () => {
    const { wrapper, router } = await open({ search: 'ramesh', overdue: '60', sort: 'oldest' })

    await wrapper.get('input[aria-label="Bills with this product"]').setValue(' hammer ')
    await new Promise((resolve) => setTimeout(resolve, 350))
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({
      search: 'ramesh',
      overdue: '60',
      sort: 'oldest',
      product: 'hammer',
    })

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Clear filters')
      .trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).toEqual({})
    expect(wrapper.get('input[aria-label="Search bills"]').element.value).toBe('')
    expect(wrapper.get('input[aria-label="Bills with this product"]').element.value).toBe('')
    expect(wrapper.findAll('button').some((b) => b.text() === 'Clear filters')).toBe(false)
  })

  it('shows the filters in use as tags, each removed on its own', async () => {
    const { wrapper, router } = await open({
      search: 'ramesh',
      ...periodRange('this_month'),
      status: 'unpaid',
      method: 'upi',
      overdue: '30',
      changes: 'returned',
      product: 'nails',
      sort: 'due',
    })

    const tags = () => wrapper.findAll('.chips .chip').map((chip) => chip.text().replace(' ✕', ''))
    expect(tags()).toEqual([
      'This month',
      'Unpaid',
      'Paid by UPI',
      'Due over 30 days',
      'Items returned',
      'Product: nails',
    ])
    // The search shows in its own box, so it is not counted.
    expect(wrapper.get('.filter-count').text()).toBe('6')

    await wrapper.get('.chip[aria-label="Remove filter: This month"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).not.toHaveProperty('from')
    expect(router.currentRoute.value.query).not.toHaveProperty('to')
    expect(router.currentRoute.value.query.status).toBe('unpaid')

    await wrapper.get('.chip[aria-label="Remove filter: Paid by UPI"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.query).not.toHaveProperty('method')
    expect(tags()).toEqual(['Unpaid', 'Due over 30 days', 'Items returned', 'Product: nails'])
  })

  it('names days chosen by hand in their tag', async () => {
    const { wrapper } = await open({ from: '2026-10-03', to: '2026-10-09' })

    expect(wrapper.get('.chips .chip').text()).toBe('3 Oct 2026 – 9 Oct 2026 ✕')
  })

  it('clears what the panel sets but keeps the search and the order', async () => {
    const { wrapper, router } = await open({
      search: 'ramesh',
      status: 'unpaid',
      overdue: '60',
      product: 'nails',
      sort: 'oldest',
    })

    expect(wrapper.get('.sheet-foot .btn-primary').text()).toBe('Show 1 bill')

    await wrapper
      .findAll('.sheet-foot button')
      .find((b) => b.text() === 'Clear all')
      .trigger('click')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ search: 'ramesh', sort: 'oldest' })
    expect(wrapper.find('.chips').exists()).toBe(false)
  })
})
