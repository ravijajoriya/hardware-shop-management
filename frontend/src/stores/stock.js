import { ref } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { toQueryString } from '@/utils/query'

export const useStockStore = defineStore('stock', () => {
  const auth = useAuthStore()

  // The current page of deliveries, newest first, and its pagination details.
  const entries = ref([])
  const meta = ref({ current_page: 1, last_page: 1, from: null, to: null, total: 0 })

  // The list's last filters, so a delivery can link back to the same page.
  const listQuery = ref({})

  async function fetchPage(filters = {}) {
    const response = await auth.request(`/admin/stock-entries${toQueryString(filters)}`)

    entries.value = response.data
    meta.value = response.meta
  }

  async function fetchOne(id) {
    const { data } = await auth.request(`/admin/stock-entries/${id}`)

    return data
  }

  async function create(payload) {
    const { data } = await auth.request('/admin/stock-entries', { method: 'POST', body: payload })

    return data
  }

  // Deletes a delivery entered by mistake; the server takes its stock back out.
  async function remove(id) {
    await auth.request(`/admin/stock-entries/${id}`, { method: 'DELETE' })
  }

  // Supplier names used before, to suggest while typing.
  async function supplierNames() {
    const { data } = await auth.request('/admin/stock-entries/suppliers')

    return data
  }

  // Sets a size's stock to the count it really is, with the reason.
  async function correct(variantId, payload) {
    const { data } = await auth.request(`/admin/product-variants/${variantId}/stock-corrections`, {
      method: 'POST',
      body: payload,
    })

    return data
  }

  // Deliveries and corrections for one product, newest first.
  async function history(productId) {
    const { data } = await auth.request(`/admin/products/${productId}/stock-history`)

    return data
  }

  return {
    entries,
    meta,
    listQuery,
    fetchPage,
    fetchOne,
    create,
    remove,
    supplierNames,
    correct,
    history,
  }
})
