import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import BillDetailView from '../BillDetailView.vue'

const blank = { template: '<div />' }

const bill = {
  id: 9,
  bill_number: 'INV-2026-0009',
  share_url: 'http://localhost:8000/bill/Ab3dEf6hIj9kLm2nOp5qRs8tUv1wXy4zAb7cDe0f',
  customer: { id: 4, name: 'Ramesh Kumar', mobile: '9876543210' },
  total: 880,
  paid_amount: 500,
  due_amount: 380,
  status: { value: 'partly_paid', label: 'Partly paid' },
  items: [
    {
      id: 1,
      product_name: 'Claw Hammer',
      variant_name: null,
      unit: 'pc',
      quantity: 2,
      price: 440,
      list_price: 440,
      line_total: 880,
    },
  ],
  payments: [
    {
      id: 1,
      method: { value: 'cash', label: 'Cash' },
      amount: 500,
      reference: null,
      received_at: '2026-11-02T10:15:00Z',
    },
  ],
  created_at: '2026-11-02T10:15:00Z',
}
const shop = { name: 'Sharma Hardware', address: 'MG Road, Jaipur', phone: null }

// The bill, and its PDF unless `pdfStatus` says the server failed to make it.
function serve({ pdfStatus = 200 } = {}) {
  return async (url) => {
    if (!url.endsWith('/pdf')) {
      return new Response(JSON.stringify({ data: bill, shop }), { status: 200 })
    }

    return pdfStatus === 200
      ? new Response('%PDF-1.7', { status: 200, headers: { 'Content-Type': 'application/pdf' } })
      : new Response(JSON.stringify({ message: 'Server Error' }), { status: pdfStatus })
  }
}

function allowSharing(share) {
  Object.defineProperty(navigator, 'share', { value: share, configurable: true })
  Object.defineProperty(navigator, 'canShare', { value: () => true, configurable: true })
}

describe('BillDetailView: sending the bill', () => {
  let fetchMock
  let downloads

  beforeEach(() => {
    localStorage.setItem('admin_token', 'abc123')
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)

    URL.createObjectURL = vi.fn(() => 'blob:bill')
    URL.revokeObjectURL = vi.fn()
    downloads = []
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
      downloads.push(this.download)
    })
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    delete navigator.share
    delete navigator.canShare
    localStorage.clear()
  })

  async function open(options) {
    fetchMock.mockImplementation(serve(options))

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: blank },
        { path: '/bills', name: 'bills', component: blank },
        { path: '/bills/new', name: 'bill-create', component: blank },
        { path: '/customers/:id', name: 'customer-show', component: blank },
      ],
    })

    const wrapper = mount(BillDetailView, {
      props: { id: '9' },
      global: { plugins: [createPinia(), router] },
    })
    await flushPromises()

    return wrapper
  }

  const button = (wrapper, label) => wrapper.findAll('button').find((b) => b.text() === label)
  const sendLink = (wrapper) => wrapper.findAll('a').find((a) => a.text() === 'Send on WhatsApp')

  it('makes the PDF as soon as the bill shows', async () => {
    const wrapper = await open()

    expect(fetchMock.mock.calls.map(([url]) => url.replace(/^.*\/api/, ''))).toEqual([
      '/admin/bills/9',
      '/admin/bills/9/pdf',
    ])
    expect(button(wrapper, 'Download PDF').element.disabled).toBe(false)
  })

  it('opens the customer’s chat with the bill written out and a link to its PDF', async () => {
    const wrapper = await open()

    const link = sendLink(wrapper)
    const href = link.attributes('href')
    expect(href).toMatch(/^https:\/\/wa\.me\/919876543210\?text=/)
    expect(decodeURIComponent(href.split('text=')[1])).toContain(
      `PDF of your bill: ${bill.share_url}`,
    )
    expect(link.attributes('target')).toBe('_blank')
  })

  it('saves the PDF with Download PDF', async () => {
    const wrapper = await open()

    await button(wrapper, 'Download PDF').trigger('click')

    expect(downloads).toEqual(['INV-2026-0009.pdf'])
  })

  it('shares the PDF file itself where the device can share files', async () => {
    const share = vi.fn().mockResolvedValue(undefined)
    allowSharing(share)
    const wrapper = await open()

    await button(wrapper, 'Share PDF').trigger('click')
    await flushPromises()

    const [{ files, text }] = share.mock.calls[0]
    expect(files).toHaveLength(1)
    expect(files[0].name).toBe('INV-2026-0009.pdf')
    expect(files[0].type).toBe('application/pdf')
    expect(text).toBe('Bill INV-2026-0009 from Sharma Hardware: ₹880.00, ₹380.00 due. Thank you!')
  })

  it('offers no Share PDF where the device cannot share files', async () => {
    const wrapper = await open()

    expect(button(wrapper, 'Share PDF')).toBeUndefined()
  })

  it('says nothing when the share menu is closed without sending', async () => {
    allowSharing(vi.fn().mockRejectedValue(new DOMException('Share canceled', 'AbortError')))
    const wrapper = await open()

    await button(wrapper, 'Share PDF').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
  })

  it('still sends on WhatsApp when the PDF could not be fetched', async () => {
    allowSharing(vi.fn())
    const wrapper = await open({ pdfStatus: 500 })

    expect(wrapper.get('[role="alert"]').text()).toContain(
      'Could not get the PDF for download or sharing: Server Error',
    )
    expect(button(wrapper, 'Download PDF').element.disabled).toBe(true)
    expect(button(wrapper, 'Share PDF')).toBeUndefined()
    expect(sendLink(wrapper).attributes('href')).toContain('wa.me/919876543210')
  })
})
