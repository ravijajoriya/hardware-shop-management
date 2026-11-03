import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useActivityStore } from '../activity'

function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), { status })
}

describe('activity store', () => {
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

  it('loads a page of the log with only the filters that are set', async () => {
    const entry = { id: 1, description: 'Logged in' }
    const meta = { current_page: 1, last_page: 1, from: 1, to: 1, total: 1 }
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [entry], meta }))
    const store = useActivityStore()

    await store.fetchPage({
      search: '',
      user_id: '2',
      section: 'bills',
      from: '2026-11-01',
      to: '',
    })

    expect(fetchMock.mock.calls[0][0]).toMatch(
      /\/admin\/activity-logs\?user_id=2&section=bills&from=2026-11-01$/,
    )
    expect(store.entries).toEqual([entry])
    expect(store.meta).toEqual(meta)
  })

  it('loads the admins and sections to filter by', async () => {
    const options = {
      admins: [{ id: 1, name: 'Ravi' }],
      sections: [{ value: 'bills', label: 'Bills' }],
    }
    fetchMock.mockResolvedValue(jsonResponse(200, { data: options }))
    const store = useActivityStore()

    await store.fetchOptions()

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/activity-logs\/options$/)
    expect(store.options).toEqual(options)
  })
})
