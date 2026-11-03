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
  customer: {
    id: 4,
    name: 'Ramesh Kumar',
    mobile: '9876543210',
    address: '12, Station Road, Jaipur',
  },
  total: 880,
  paid_amount: 500,
  due_amount: 380,
  returned_amount: 0,
  refunded_amount: 0,
  net_total: 880,
  edited: null,
  cancelled: null,
  can: { edit_items: true, edit: true, return: true, cancel: true },
  returns: [],
  refunds: [],
  status: { value: 'partly_paid', label: 'Partly paid' },
  items: [
    {
      id: 1,
      product_name: 'Claw Hammer',
      variant_name: null,
      unit: 'pc',
      quantity: 2,
      returned_quantity: 0,
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
// The bill after the rest of it, ₹380, is paid by UPI.
const paidBill = {
  ...bill,
  paid_amount: 880,
  due_amount: 0,
  status: { value: 'paid', label: 'Paid' },
  payments: [
    ...bill.payments,
    {
      id: 2,
      method: { value: 'upi', label: 'UPI' },
      amount: 380,
      reference: 'UPI778899',
      received_at: '2026-11-05T09:00:00Z',
    },
  ],
}

// jsdom has no modal dialogs.
HTMLDialogElement.prototype.showModal ??= function () {
  this.setAttribute('open', '')
}

// The bill after one of its two hammers came back: ₹440 off the ₹380 due, ₹60 given back.
const returnedBill = {
  ...bill,
  returned_amount: 440,
  refunded_amount: 60,
  net_total: 440,
  due_amount: 0,
  status: { value: 'paid', label: 'Paid' },
  items: [{ ...bill.items[0], returned_quantity: 1 }],
  returns: [
    {
      id: 1,
      return_number: 'RET-2026-0001',
      amount: 440,
      due_reduced: 380,
      refund_amount: 60,
      note: 'Handle loose',
      returned_at: '2026-11-05T09:00:00Z',
      items: [
        {
          id: 1,
          product_name: 'Claw Hammer',
          variant_name: null,
          unit: 'pc',
          quantity: 1,
          price: 440,
          line_total: 440,
        },
      ],
      refund: { id: 1, method: { value: 'cash', label: 'Cash' }, amount: 60, reference: null },
    },
  ],
  refunds: [
    {
      id: 1,
      bill_return_id: 1,
      reason: { value: 'return', label: 'Items returned' },
      method: { value: 'cash', label: 'Cash' },
      amount: 60,
      reference: null,
      refunded_at: '2026-11-05T09:00:00Z',
    },
  ],
}

// The bill cancelled, with the ₹500 paid given back by UPI.
const cancelledBill = {
  ...bill,
  refunded_amount: 500,
  due_amount: 0,
  status: { value: 'cancelled', label: 'Cancelled' },
  cancelled: { at: '2026-11-05T09:00:00Z', by: 'Ravi', reason: 'Made twice' },
  can: { edit_items: false, edit: false, return: false, cancel: false },
  refunds: [
    {
      id: 2,
      bill_return_id: null,
      reason: { value: 'cancel', label: 'Bill cancelled' },
      method: { value: 'upi', label: 'UPI' },
      amount: 500,
      reference: null,
      refunded_at: '2026-11-05T09:00:00Z',
    },
  ],
}

function serve({ pdfStatus = 200, shown = bill } = {}) {
  return async (url) => {
    if (url.endsWith('/returns')) {
      return new Response(
        JSON.stringify({ data: returnedBill, shop, return_number: 'RET-2026-0001' }),
        { status: 200 },
      )
    }

    if (url.endsWith('/cancel')) {
      return new Response(JSON.stringify({ data: cancelledBill, shop }), { status: 200 })
    }

    if (url.endsWith('/payments')) {
      return new Response(JSON.stringify({ data: paidBill, shop }), { status: 200 })
    }

    if (!url.endsWith('/pdf')) {
      return new Response(JSON.stringify({ data: shown, shop }), { status: 200 })
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
        { path: '/bills/:id/edit', name: 'bill-edit', component: blank },
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

  it('shows who the bill is for, with their address', async () => {
    const wrapper = await open()

    expect(wrapper.get('.bill-to').text()).toContain(
      'Ramesh Kumar98765 4321012, Station Road, Jaipur',
    )
  })

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
    expect(text).toBe('Bill INV-2026-0009 from Sharma Hardware: ₹880, ₹380 due. Thank you!')
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

  it('takes a payment towards this bill and offers a receipt', async () => {
    const wrapper = await open()

    await button(wrapper, 'Receive payment').trigger('click')
    const dialog = wrapper.get('dialog')
    expect(dialog.get('h2').text()).toBe('Receive payment for INV-2026-0009')
    expect(dialog.get('.owed').text()).toContain('Due on this bill: ₹380')
    expect(dialog.get('#payment-amount').element.value).toBe('380')

    await dialog.get('input[value="upi"]').setValue()
    await dialog.get('#payment-reference').setValue('UPI778899')
    await dialog.get('form').trigger('submit')
    await flushPromises()

    const [url, options] = fetchMock.mock.calls.find(([url]) => url.endsWith('/payments'))
    expect(url).toMatch(/\/admin\/bills\/9\/payments$/)
    expect(options.method).toBe('POST')
    expect(JSON.parse(options.body)).toEqual({ method: 'upi', amount: 380, reference: 'UPI778899' })

    expect(wrapper.find('dialog').exists()).toBe(false)
    const note = wrapper.get('.received')
    expect(note.text()).toContain('Received ₹380 by UPI. This bill is now paid in full.')
    const receipt = decodeURIComponent(note.get('a').attributes('href').split('text=')[1])
    expect(receipt).toContain('for bill INV-2026-0009')
    expect(receipt).toContain('This bill is now paid in full.')

    // Nothing is due any more, and the PDF is made again to show the payment.
    expect(button(wrapper, 'Receive payment')).toBeUndefined()
    expect(wrapper.get('.sheet').text()).toContain('UPI · UPI778899')
    expect(fetchMock.mock.calls.filter(([url]) => url.endsWith('/pdf'))).toHaveLength(2)
  })

  it('takes items back, showing how the money is settled first', async () => {
    const wrapper = await open()

    expect(wrapper.get('.bill-tools a').attributes('href')).toBe('/bills/9/edit')
    await button(wrapper, 'Return items').trigger('click')
    const dialog = wrapper.get('dialog')

    await dialog.get('input[aria-label="How many Claw Hammer came back"]').setValue('1')
    expect(dialog.get('.settle').text()).toContain('Worth₹440')
    expect(dialog.get('.settle').text()).toContain('₹380')
    expect(dialog.get('.settle').text()).toContain('To give back₹60')
    await dialog.get('#return-note').setValue('Handle loose')
    await dialog.get('form').trigger('submit')
    await flushPromises()

    const [, options] = fetchMock.mock.calls.find(([url]) => url.endsWith('/returns'))
    expect(JSON.parse(options.body)).toEqual({
      items: [{ bill_item_id: 1, quantity: 1 }],
      note: 'Handle loose',
      refund: { method: 'cash', reference: null },
    })

    expect(wrapper.find('dialog').exists()).toBe(false)
    expect(wrapper.text()).toContain(
      'Return RET-2026-0001 is saved and the items are back in stock. ₹380 came off the due; ₹60 given back by Cash.',
    )
    expect(wrapper.get('.returns').text()).toContain('RET-2026-0001')
    expect(wrapper.get('.sums').text()).toContain('Returned−₹440')
    expect(wrapper.get('.payments').text()).toContain('Given back · Cash')
  })

  it('cancels the bill, giving back what was paid', async () => {
    const wrapper = await open()

    await button(wrapper, 'Cancel bill').trigger('click')
    const dialog = wrapper.get('dialog')
    expect(dialog.text()).toContain('₹500 was paid on this bill.')
    await dialog.get('#cancel-reason').setValue('Made twice')
    await dialog.get('input[value="upi"]').setValue()
    await dialog.get('form').trigger('submit')
    await flushPromises()

    const [, options] = fetchMock.mock.calls.find(([url]) => url.endsWith('/cancel'))
    expect(JSON.parse(options.body)).toEqual({
      reason: 'Made twice',
      refund: { method: 'upi', reference: null },
    })

    expect(wrapper.text()).toContain(
      'The bill is cancelled and its items are back in stock. ₹500 given back by UPI.',
    )
    expect(wrapper.get('.stamp').text()).toBe('Cancelled')
    expect(wrapper.find('.bill-tools').exists()).toBe(false)
    expect(button(wrapper, 'Receive payment')).toBeUndefined()
  })

  it('offers no payment on a bill that is paid in full', async () => {
    const wrapper = await open({ shown: paidBill })

    expect(wrapper.get('.sheet').text()).toContain('Paid')
    expect(button(wrapper, 'Receive payment')).toBeUndefined()
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
