import { ref } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'

export const useProductStore = defineStore('products', () => {
  const auth = useAuthStore()

  // The current page of products, its pagination details and the stock worth of every match.
  const products = ref([])
  const meta = ref({ current_page: 1, last_page: 1, from: null, to: null, total: 0 })
  const summary = ref({ products: 0, cost_value: 0, selling_value: 0 })

  // The list's last filters, so the form can return to the same page.
  const listQuery = ref({})

  // What the product form can choose from.
  const options = ref({ categories: [], brands: [], units: [] })

  async function fetchPage(filters = {}) {
    const query = new URLSearchParams()

    for (const [key, value] of Object.entries(filters)) {
      if (value !== '' && value !== null && value !== undefined) {
        query.set(key, value)
      }
    }

    const search = query.toString()
    const response = await auth.request(`/admin/products${search ? `?${search}` : ''}`)

    products.value = response.data
    meta.value = response.meta
    summary.value = response.summary
  }

  async function fetchOptions() {
    const { data } = await auth.request('/admin/products/options')

    options.value = data
  }

  // A quick search that leaves the products list as it is, for "Where is it?".
  async function find(term, limit = 8) {
    const query = new URLSearchParams({ search: term, per_page: limit })
    const { data } = await auth.request(`/admin/products?${query}`)

    return data
  }

  async function fetchOne(id) {
    const { data } = await auth.request(`/admin/products/${id}`)

    return data
  }

  async function create(payload) {
    const { data } = await auth.request('/admin/products', { method: 'POST', body: payload })

    return data
  }

  async function update(id, payload) {
    const { data } = await auth.request(`/admin/products/${id}`, { method: 'PUT', body: payload })

    return data
  }

  async function remove(id) {
    await auth.request(`/admin/products/${id}`, { method: 'DELETE' })
  }

  // The image calls below each return all of the product's images, in order.

  async function uploadImages(id, files) {
    const body = new FormData()

    for (const file of files) {
      body.append('images[]', file)
    }

    const { data } = await auth.request(`/admin/products/${id}/images`, { method: 'POST', body })

    return data
  }

  async function deleteImage(id, imageId) {
    const { data } = await auth.request(`/admin/products/${id}/images/${imageId}`, {
      method: 'DELETE',
    })

    return data
  }

  async function reorderImages(id, imageIds) {
    const { data } = await auth.request(`/admin/products/${id}/images/order`, {
      method: 'PUT',
      body: { ids: imageIds },
    })

    return data
  }

  return {
    products,
    meta,
    summary,
    listQuery,
    options,
    fetchPage,
    fetchOptions,
    find,
    fetchOne,
    create,
    update,
    remove,
    uploadImages,
    deleteImage,
    reorderImages,
  }
})
