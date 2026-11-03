import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import ActivityLogView from '../ActivityLogView.vue'

const blank = { template: '<div />' }

const priceChange = {
  id: 2,
  action: { value: 'product.updated', label: 'Product changed' },
  section: { value: 'products', label: 'Products' },
  description: 'Changed product Claw Hammer: Selling price ₹350 → ₹380',
  subject: { type: 'product', id: 5, label: 'Claw Hammer', exists: true },
  user: { id: 1, name: 'Ravi' },
  changes: [{ field: 'Selling price', old: '₹350', new: '₹380' }],
  details: [],
  ip_address: '192.168.1.20',
  user_agent:
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36',
  created_at: '2026-11-03T05:30:00Z',
}

const failedLogin = {
  id: 1,
  action: { value: 'auth.login_failed', label: 'Failed login' },
  section: { value: 'logins', label: 'Logins' },
  description: 'Failed login for ravi@example.com',
  subject: null,
  user: null,
  changes: [],
  details: [
    { label: 'Email tried', value: 'ravi@example.com' },
    { label: 'Why it failed', value: 'Wrong password' },
  ],
  ip_address: '203.0.113.7',
  user_agent: null,
  created_at: '2026-11-03T04:00:00Z',
}

const options = {
  admins: [{ id: 1, name: 'Ravi' }],
  sections: [
    { value: 'logins', label: 'Logins' },
    { value: 'products', label: 'Products' },
  ],
}

function jsonResponse(body) {
  return new Response(JSON.stringify(body), { status: 200 })
}

describe('ActivityLogView', () => {
  let fetchMock

  beforeEach(() => {
    localStorage.setItem('admin_token', 'abc123')
    fetchMock = vi.fn(async (url) =>
      url.includes('/options')
        ? jsonResponse({ data: options })
        : jsonResponse({
            data: [priceChange, failedLogin],
            meta: { current_page: 1, last_page: 1, from: 1, to: 2, total: 2 },
          }),
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
        { path: '/activity', name: 'activity', component: ActivityLogView },
        { path: '/products/:id', name: 'product-show', component: blank },
      ],
    })
    router.push({ name: 'activity', query })
    await router.isReady()

    const wrapper = mount(ActivityLogView, { global: { plugins: [createPinia(), router] } })
    await flushPromises()

    return { wrapper, router }
  }

  const rows = (wrapper) => wrapper.findAll('table.log > tbody > tr')

  it('lists what admins did, newest first, with a link to what it was about', async () => {
    const { wrapper } = await open()

    const [first, second] = rows(wrapper)
    expect(first.text()).toContain('Ravi')
    expect(first.text()).toContain('Product changed')
    expect(first.text()).toContain('Changed product Claw Hammer: Selling price ₹350 → ₹380')
    expect(first.get('a').attributes('href')).toBe('/products/5')
    expect(first.get('a').text()).toBe('Open Claw Hammer')

    // A failed login has no one behind it, and stands out.
    expect(second.classes()).toContain('failed')
    expect(second.get('.badge').classes()).toContain('badge-danger')
    expect(second.findAll('td')[1].text()).toBe('—')
    expect(second.find('a').exists()).toBe(false)
  })

  it('shows the changes, the details and where an entry came from', async () => {
    const { wrapper } = await open()

    await rows(wrapper)[0].get('button[aria-expanded]').trigger('click')
    const changes = rows(wrapper)[1]
    expect(changes.findAll('.changes tbody tr').map((row) => row.text())).toEqual([
      'Selling price₹350₹380',
    ])
    expect(changes.get('.from').text()).toContain('From Chrome on Mac')
    expect(changes.get('.from').text()).toContain('192.168.1.20')

    await wrapper.findAll('button[aria-expanded]')[1].trigger('click')
    const details = wrapper.findAll('.details-row')[1]
    expect(details.get('.detail-list').text()).toContain('Why it failedWrong password')
    expect(details.get('.from').text()).toContain('From an unknown device')
  })

  it('filters through the address, starting again from the first page', async () => {
    const { wrapper, router } = await open({ page: '3' })

    await wrapper.get('select[aria-label="Filter by section"]').setValue('logins')
    await flushPromises()

    expect(router.currentRoute.value.query).toEqual({ section: 'logins' })
    expect(fetchMock.mock.calls.at(-1)[0]).toMatch(/\/admin\/activity-logs\?section=logins$/)
    expect(
      wrapper.findAll('select[aria-label="Filter by admin"] option').map((o) => o.text()),
    ).toEqual(['All admins', 'Ravi'])
  })
})
