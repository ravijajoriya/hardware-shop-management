import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import ProductDetailView from '../ProductDetailView.vue'

const blank = { template: '<div />' }

const paint = {
  id: 5,
  name: 'Wall Paint',
  description: 'Weatherproof.',
  category: { id: 1, name: 'Paint' },
  brand: { id: 2, name: 'Asian Paints' },
  unit: { id: 3, name: 'Piece', short_name: 'pc', allow_decimal: false },
  location: { area: 'shop', floor: 0, rack: '3', shelf: 2, code: 'SHOP-GF-R3-S2' },
  hsn_code: '3209',
  gst_rate: 18,
  is_active: true,
  images: [
    { id: 1, url: 'http://localhost/front.jpg' },
    { id: 2, url: 'http://localhost/side.jpg' },
  ],
  stock_quantity: 14,
  low_stock: true,
  variants: [
    {
      id: 21,
      name: '1 L',
      sku: 'AP-1L',
      selling_price: 360,
      mrp: 399,
      stock_quantity: 10,
      min_stock: null,
      low_stock: false,
    },
    {
      id: 22,
      name: '4 L',
      sku: null,
      selling_price: 1350,
      mrp: null,
      stock_quantity: 4,
      min_stock: 5,
      low_stock: true,
    },
  ],
}

const earphone = {
  ...paint,
  id: 6,
  name: 'Earphone',
  brand: null,
  hsn_code: null,
  gst_rate: null,
  images: [],
  stock_quantity: 100,
  variants: [
    { id: 31, name: 'Earphone', sku: null, selling_price: 1500, mrp: 1999, stock_quantity: 100 },
  ],
}

// A delivery and a correction, newest first, as the stock history sends them.
const history = [
  {
    type: 'correction',
    date: '2026-11-03',
    variant_name: '1 L',
    unit: 'pc',
    change: -2,
    stock_before: 12,
    stock_after: 10,
    reason: { value: 'damaged', label: 'Damaged or lost' },
    note: 'Two tins dented',
    by: 'Ravi',
  },
  {
    type: 'stock_in',
    date: '2026-11-01',
    variant_name: '1 L',
    unit: 'pc',
    change: 6,
    purchase_price: 300,
    cost_after: 290,
    supplier_name: 'Sharma Traders',
    supplier_bill_number: 'ST-4512',
    stock_entry_id: 7,
  },
]

function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), { status })
}

// jsdom has no modal dialogs.
HTMLDialogElement.prototype.showModal ??= function () {
  this.setAttribute('open', '')
}

describe('ProductDetailView', () => {
  let fetchMock

  beforeEach(() => {
    localStorage.setItem('admin_token', 'abc123')
    fetchMock = vi.fn()
    vi.stubGlobal('fetch', fetchMock)
  })

  afterEach(() => {
    vi.unstubAllGlobals()
    localStorage.clear()
  })

  // The product (or `status` with an error), its stock history, and a stock correction.
  async function open(product, { status = 200 } = {}) {
    fetchMock.mockImplementation(async (url, options) => {
      if (url.endsWith('/stock-history')) {
        return jsonResponse(200, { data: history })
      }

      if (url.endsWith('/stock-corrections')) {
        return jsonResponse(201, {
          data: {
            ...JSON.parse(options.body),
            product_name: 'Wall Paint',
            variant_name: '1 L',
            unit: 'pc',
            stock_before: 10,
            stock_after: 7,
          },
        })
      }

      return status === 200
        ? jsonResponse(200, { data: product })
        : jsonResponse(status, { message: 'No query results for model.' })
    })

    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', name: 'dashboard', component: blank },
        { path: '/products', name: 'products', component: blank },
        { path: '/products/:id', name: 'product-show', component: blank },
        { path: '/products/:id/edit', name: 'product-edit', component: blank },
        { path: '/stock-in/new', name: 'stock-entry-create', component: blank },
        { path: '/stock-in/:id', name: 'stock-entry-show', component: blank },
      ],
    })

    const wrapper = mount(ProductDetailView, {
      props: { id: '5' },
      global: { plugins: [createPinia(), router] },
    })
    await flushPromises()

    return wrapper
  }

  it('shows the product with its photos, prices, stock and place', async () => {
    const wrapper = await open(paint)

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/products\/5$/)
    expect(wrapper.get('h1').text()).toBe('Wall Paint')
    expect(wrapper.get('.subtitle').text()).toContain('Asian Paints · Paint')
    expect(wrapper.findAll('.thumb')).toHaveLength(2)
    expect(wrapper.get('.amount').text()).toBe('₹360 – ₹1,350')
    expect(wrapper.get('.stock').text()).toBe('14 pc in stock Low stock')
    expect(wrapper.get('.place .code').text()).toBe('SHOP-GF-R3-S2')
    expect(wrapper.get('.place .words').text()).toBe('Shop · Ground floor · Rack 3 · Shelf 2')
    expect(wrapper.text()).toContain('Weatherproof.')

    // What each cell shows in the table, without the name it shows above itself on a phone.
    const cellText = (td) => {
      const copy = td.element.cloneNode(true)
      copy.querySelector('.cell-label')?.remove()
      return copy.textContent.replace(/\s+/g, ' ').trim()
    }
    const rows = wrapper
      .findAll('.sizes-table tbody tr')
      .map((row) => row.findAll('td').map(cellText))
    expect(rows).toEqual([
      ['1 L', 'AP-1L', '₹360', '₹399', '10 pc', '—', 'Correct stock'],
      ['4 L', '—', '₹1,350', '—', '4 pc below the minimum', '5 pc', 'Correct stock'],
    ])

    expect(wrapper.get('a.btn-primary').attributes('href')).toBe('/products/5/edit')
  })

  it('shows one price per unit, with its MRP, when there is only one size', async () => {
    const wrapper = await open(earphone)

    expect(wrapper.get('.amount').text()).toBe('₹1,500')
    expect(wrapper.get('.price .note').text()).toBe('per pc')
    expect(wrapper.get('.mrp').text()).toContain('₹1,999')
    expect(wrapper.get('.main').text()).toBe('No photos yet')
    // No Size column: SKU, selling price, MRP, stock, minimum stock and the actions.
    expect(wrapper.findAll('.sizes-table thead th')).toHaveLength(6)
    expect(wrapper.text()).toContain('No brand')
    expect(wrapper.text()).toContain('Not set')
  })

  it('says so when the product has been deleted', async () => {
    const wrapper = await open(null, { status: 404 })

    const alert = wrapper.get('[role="alert"]')
    expect(alert.text()).toContain('This product no longer exists.')
    // Trying again cannot help, so it points to the product list instead.
    expect(alert.get('a').attributes('href')).toBe('/products')
    expect(alert.find('button').exists()).toBe(false)
    // There is nothing to show history for.
    expect(fetchMock.mock.calls.some(([url]) => url.endsWith('/stock-history'))).toBe(false)
  })

  it('shows what came in and every correction, newest first', async () => {
    const wrapper = await open(paint)

    const rows = wrapper
      .findAll('.history-table tbody tr')
      .map((row) => row.findAll('td').map((td) => td.text()))
    expect(rows).toEqual([
      [
        '3 Nov 2026',
        '1 L',
        '−2 pc',
        'Stock corrected (damaged or lost) from 12 pc to 10 pc. “Two tins dented” By Ravi.',
      ],
      [
        '1 Nov 2026',
        '1 L',
        '+6 pc',
        'Stock in from Sharma Traders (bill ST-4512) at ₹300 each. Average cost became ₹290.',
      ],
    ])
    expect(wrapper.get('.history-table a').attributes('href')).toBe('/stock-in/7')
  })

  it('corrects a size’s stock and shows the new count', async () => {
    const wrapper = await open(paint)

    const correctFirstSize = wrapper
      .findAll('.sizes-table tbody button')
      .find((button) => button.text() === 'Correct stock')
    await correctFirstSize.trigger('click')
    const dialog = wrapper.get('dialog')
    expect(dialog.text()).toContain('The count shows 10 pc.')

    await dialog.get('#correct-quantity').setValue('7')
    expect(dialog.get('.with-unit + .hint').text()).toBe('−3 pc from the count shown.')
    await dialog.get('#correct-reason').setValue('damaged')
    await dialog.get('form').trigger('submit')
    await flushPromises()

    const [url, options] = fetchMock.mock.calls.find(([url]) => url.endsWith('/stock-corrections'))
    expect(url).toMatch(/\/admin\/product-variants\/21\/stock-corrections$/)
    expect(JSON.parse(options.body)).toEqual({ quantity: 7, reason: 'damaged', note: null })

    expect(wrapper.find('dialog').exists()).toBe(false)
    expect(wrapper.get('.corrected').text()).toBe('Stock of Wall Paint · 1 L is now 7 pc (−3 pc).')
    // The product and its history are loaded again to show the change.
    expect(fetchMock.mock.calls.filter(([url]) => url.endsWith('/admin/products/5'))).toHaveLength(
      2,
    )
    expect(fetchMock.mock.calls.filter(([url]) => url.endsWith('/stock-history'))).toHaveLength(2)
  })
})
