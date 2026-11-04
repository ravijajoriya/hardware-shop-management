import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'

import ProductImportView from '../ProductImportView.vue'

const blank = { template: '<div />' }

const ready = {
  file: 'products.xlsx',
  rows: 4,
  ready: { products: 3, sizes: 4 },
  existing: [],
  problems: [],
  problem_rows: 0,
  left_out_rows: 0,
  new_categories: ['Paints', 'Paints > Exterior'],
  new_brands: ['Asian Paints'],
  ignored_columns: [],
}

const withProblems = {
  ...ready,
  ready: { products: 1, sizes: 1 },
  existing: [{ rows: [5], name: 'Claw Hammer' }],
  problems: [
    { row: 2, column: 'Selling price', message: 'Selling price is missing.' },
    { row: 3, column: 'Unit', message: 'Unit "dozenz" is not in the app. Use one of: kg, pc.' },
  ],
  problem_rows: 2,
  left_out_rows: 3,
  new_categories: [],
  new_brands: [],
  ignored_columns: ['Notes'],
}

function jsonResponse(status, body) {
  return new Response(JSON.stringify(body), { status })
}

describe('ProductImportView', () => {
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

  async function open() {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/products', name: 'products', component: blank },
        { path: '/products/import', name: 'product-import', component: ProductImportView },
      ],
    })
    router.push({ name: 'product-import' })
    await router.isReady()

    const wrapper = mount(ProductImportView, { global: { plugins: [createPinia(), router] } })
    await flushPromises()

    return wrapper
  }

  async function chooseFile(wrapper, name = 'products.xlsx') {
    const input = wrapper.get('input[type="file"]')
    Object.defineProperty(input.element, 'files', {
      value: [new File(['rows'], name)],
      configurable: true,
    })
    await input.trigger('change')
    await flushPromises()
  }

  const text = (wrapper, selector) => wrapper.get(selector).text().replace(/\s+/g, ' ')
  // Each figure card as its label, value and notes.
  const stats = (wrapper) =>
    wrapper.findAll('.stat').map((stat) => stat.findAll('span').map((part) => part.text()))

  it('checks a chosen file straight away and imports everything when it is clean', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: ready }))
    fetchMock.mockResolvedValueOnce(
      jsonResponse(201, {
        data: { products: 3, sizes: 4, categories: 2, brands: 1, existing: 0, left_out_rows: 0 },
      }),
    )
    const wrapper = await open()

    await chooseFile(wrapper)

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/products\/import\/check$/)
    expect(wrapper.get('.file-name').text()).toBe('products.xlsx')
    expect(stats(wrapper)[0]).toEqual(['Ready to add', '3 products', '4 sizes'])
    expect(text(wrapper, '.new-names')).toBe(
      'New categories to add: Paints, Paints > Exterior. New brands to add: Asian Paints.',
    )
    expect(wrapper.find('.problems').exists()).toBe(false)

    const button = wrapper.get('.actions .btn-primary')
    expect(button.text()).toBe('Import 3 products')
    await button.trigger('click')
    await flushPromises()

    const [url, options] = fetchMock.mock.calls[1]
    expect(url).toMatch(/\/admin\/products\/import$/)
    expect(options.body.get('skip_problems')).toBe('0')
    expect(text(wrapper, '.done')).toContain(
      'Added 3 products (4 sizes), with 2 new categories and 1 new brand.',
    )
    expect(wrapper.find('section[aria-labelledby="step-check"]').exists()).toBe(false)
  })

  it('lists the problems and can import just the ready products', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: withProblems }))
    fetchMock.mockResolvedValueOnce(
      jsonResponse(201, {
        data: { products: 1, sizes: 1, categories: 0, brands: 0, existing: 1, left_out_rows: 3 },
      }),
    )
    const wrapper = await open()

    await chooseFile(wrapper)

    expect(
      wrapper.findAll('.problems tbody tr').map((row) => row.findAll('td').map((td) => td.text())),
    ).toEqual([
      ['Row 2', 'Selling price', 'Selling price is missing.'],
      ['Row 3', 'Unit', 'Unit "dozenz" is not in the app. Use one of: kg, pc.'],
    ])
    expect(text(wrapper, '.existing')).toBe('Row 5 Claw Hammer')
    expect(text(wrapper, '.ignored')).toBe('These columns are not used: Notes.')
    expect(stats(wrapper)[2]).toEqual([
      'Rows with problems',
      '2',
      '3 rows left out with their product',
    ])

    const button = wrapper.get('.actions .btn-primary')
    expect(button.text()).toBe('Import the 1 ready product')
    await button.trigger('click')
    await flushPromises()

    expect(fetchMock.mock.calls[1][1].body.get('skip_problems')).toBe('1')
    expect(text(wrapper, '.done')).toContain(
      'Added 1 product, left out 1 already in the app and 3 rows with problems.',
    )
  })

  it('says when nothing can be added and shows a problem with the file itself', async () => {
    fetchMock.mockResolvedValueOnce(
      jsonResponse(200, { data: { ...withProblems, ready: { products: 0, sizes: 0 } } }),
    )
    fetchMock.mockResolvedValueOnce(
      jsonResponse(422, {
        message: 'These columns are missing: Unit.',
        errors: {
          file: ['These columns are missing: Unit. Download the template to see every column.'],
        },
      }),
    )
    const wrapper = await open()

    await chooseFile(wrapper)
    expect(wrapper.find('.actions .btn-primary').exists()).toBe(false)
    expect(text(wrapper, '.nothing')).toContain('Nothing in this file can be added yet.')

    await chooseFile(wrapper, 'other.xlsx')
    expect(wrapper.get('[role="alert"]').text()).toBe(
      'These columns are missing: Unit. Download the template to see every column.',
    )
    expect(wrapper.find('section[aria-labelledby="step-check"]').exists()).toBe(false)
  })

  it('downloads the template', async () => {
    fetchMock.mockResolvedValue(new Response(new Blob(['xlsx']), { status: 200 }))
    const createObjectURL = vi.fn(() => 'blob:template')
    vi.stubGlobal('URL', { ...URL, createObjectURL, revokeObjectURL: vi.fn() })
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const wrapper = await open()

    await wrapper
      .findAll('button')
      .find((b) => b.text() === 'Download template')
      .trigger('click')
    await flushPromises()

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/products\/import\/template$/)
    expect(createObjectURL.mock.calls[0][0].name).toBe('products-template.xlsx')
    expect(click).toHaveBeenCalled()
    click.mockRestore()
  })
})
