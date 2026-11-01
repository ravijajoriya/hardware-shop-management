import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'

import { useAuthStore } from '../auth'
import { useProductStore } from '../products'

const product = { id: 5, name: 'Claw Hammer', variants: [] }

function jsonResponse(status, body) {
  return new Response(body === null ? null : JSON.stringify(body), { status })
}

describe('product store', () => {
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

  it('loads a page with only the filters that are set', async () => {
    const meta = { current_page: 2, last_page: 3, from: 16, to: 30, total: 42 }
    const summary = { products: 42, cost_value: 7400, selling_value: 9000 }
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [product], meta, summary }))
    const store = useProductStore()

    await store.fetchPage({ search: 'ham', category_id: '', brand_id: '4', page: '2' })

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/products\?search=ham&brand_id=4&page=2$/)
    expect(options.headers.Authorization).toBe('Bearer abc123')
    expect(store.products).toEqual([product])
    expect(store.meta).toEqual(meta)
    expect(store.summary).toEqual(summary)
  })

  it('loads the form options', async () => {
    const options = { categories: [{ id: 1, name: 'Tools', depth: 0 }], brands: [], units: [] }
    fetchMock.mockResolvedValue(jsonResponse(200, { data: options }))
    const store = useProductStore()

    await store.fetchOptions()

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/products\/options$/)
    expect(store.options).toEqual(options)
  })

  it('finds products without touching the list', async () => {
    fetchMock.mockResolvedValue(jsonResponse(200, { data: [product], meta: {}, summary: {} }))
    const store = useProductStore()

    expect(await store.find('SHOP-GF-R3')).toEqual([product])

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/products\?search=SHOP-GF-R3&per_page=8$/)
    expect(store.products).toEqual([])
  })

  it('creates and updates products as JSON', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(201, { data: product }))
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: { ...product, name: 'Hammer' } }))
    const store = useProductStore()

    expect(await store.create({ name: 'Claw Hammer' })).toEqual(product)
    expect(await store.update(5, { name: 'Hammer' })).toMatchObject({ name: 'Hammer' })

    const [createUrl, createOptions] = fetchMock.mock.calls[0]
    expect(createUrl).toMatch(/\/admin\/products$/)
    expect(createOptions.method).toBe('POST')
    expect(createOptions.headers['Content-Type']).toBe('application/json')

    const [updateUrl, updateOptions] = fetchMock.mock.calls[1]
    expect(updateUrl).toMatch(/\/admin\/products\/5$/)
    expect(updateOptions.method).toBe('PUT')
  })

  it('uploads images as multipart form data', async () => {
    const images = [
      { id: 1, url: 'front.jpg' },
      { id: 2, url: 'side.jpg' },
    ]
    fetchMock.mockResolvedValue(jsonResponse(201, { data: images }))
    const store = useProductStore()
    const files = [
      new File(['a'], 'front.jpg', { type: 'image/jpeg' }),
      new File(['b'], 'side.jpg', { type: 'image/jpeg' }),
    ]

    expect(await store.uploadImages(5, files)).toEqual(images)

    const [url, options] = fetchMock.mock.calls[0]
    expect(url).toMatch(/\/admin\/products\/5\/images$/)
    expect(options.method).toBe('POST')
    expect(options.body).toBeInstanceOf(FormData)
    expect(options.body.getAll('images[]')).toHaveLength(2)
    // The browser must set the multipart boundary itself.
    expect(options.headers).not.toHaveProperty('Content-Type')
  })

  it('reorders and deletes images', async () => {
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: [{ id: 2 }, { id: 1 }] }))
    fetchMock.mockResolvedValueOnce(jsonResponse(200, { data: [{ id: 2 }] }))
    const store = useProductStore()

    expect(await store.reorderImages(5, [2, 1])).toEqual([{ id: 2 }, { id: 1 }])
    expect(await store.deleteImage(5, 1)).toEqual([{ id: 2 }])

    const [orderUrl, orderOptions] = fetchMock.mock.calls[0]
    expect(orderUrl).toMatch(/\/admin\/products\/5\/images\/order$/)
    expect(orderOptions.method).toBe('PUT')
    expect(JSON.parse(orderOptions.body)).toEqual({ ids: [2, 1] })

    const [deleteUrl, deleteOptions] = fetchMock.mock.calls[1]
    expect(deleteUrl).toMatch(/\/admin\/products\/5\/images\/1$/)
    expect(deleteOptions.method).toBe('DELETE')
  })

  it('deletes a product', async () => {
    fetchMock.mockResolvedValue(jsonResponse(204, null))
    const store = useProductStore()

    await store.remove(5)

    expect(fetchMock.mock.calls[0][0]).toMatch(/\/admin\/products\/5$/)
    expect(fetchMock.mock.calls[0][1].method).toBe('DELETE')
  })

  it('exposes field errors when the server rejects the product', async () => {
    fetchMock.mockResolvedValue(
      jsonResponse(422, {
        message: 'The MRP cannot be lower than the selling price.',
        errors: { 'variants.0.mrp': ['The MRP cannot be lower than the selling price.'] },
      }),
    )
    const store = useProductStore()

    await expect(store.create({})).rejects.toMatchObject({
      status: 422,
      errors: { 'variants.0.mrp': ['The MRP cannot be lower than the selling price.'] },
    })
  })

  it('ends the session when the token is no longer accepted', async () => {
    fetchMock.mockResolvedValue(jsonResponse(401, { message: 'Unauthenticated.' }))
    const store = useProductStore()

    await expect(store.fetchPage()).rejects.toMatchObject({ status: 401 })

    expect(useAuthStore().isAuthenticated).toBe(false)
  })
})
