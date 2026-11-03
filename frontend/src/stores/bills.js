import { ref } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { toQueryString } from '@/utils/query'

export const useBillStore = defineStore('bills', () => {
  const auth = useAuthStore()

  // The current page of bills, its pagination details, and what all the matching bills add up to.
  const bills = ref([])
  const meta = ref({ current_page: 1, last_page: 1, from: null, to: null, total: 0 })
  const totals = ref(null)

  // The list's last filters, so a bill can link back to the same page.
  const listQuery = ref({})

  async function fetchPage(filters = {}) {
    const response = await auth.request(`/admin/bills${toQueryString(filters)}`)

    bills.value = response.data
    meta.value = response.meta
    totals.value = response.totals
  }

  // A bill with its items and payments, and the shop details printed above it.
  async function fetchOne(id) {
    const { data, shop } = await auth.request(`/admin/bills/${id}`)

    return { bill: data, shop }
  }

  async function create(payload) {
    const { data } = await auth.request('/admin/bills', { method: 'POST', body: payload })

    return data
  }

  // Save an edited bill; it comes back as it now stands.
  async function update(id, payload) {
    const { data, shop } = await auth.request(`/admin/bills/${id}`, {
      method: 'PUT',
      body: payload,
    })

    return { bill: data, shop }
  }

  // Cancel a bill: its stock comes back and anything paid is given back.
  async function cancel(id, payload) {
    const { data, shop } = await auth.request(`/admin/bills/${id}/cancel`, {
      method: 'POST',
      body: payload,
    })

    return { bill: data, shop }
  }

  // Take items back from a bill; the bill comes back with the return on it.
  async function createReturn(id, payload) {
    const response = await auth.request(`/admin/bills/${id}/returns`, {
      method: 'POST',
      body: payload,
    })

    return { bill: response.data, shop: response.shop, returnNumber: response.return_number }
  }

  // Take a payment towards this bill only, and get the bill back with it.
  async function receivePayment(id, payload) {
    const { data, shop } = await auth.request(`/admin/bills/${id}/payments`, {
      method: 'POST',
      body: payload,
    })

    return { bill: data, shop }
  }

  // The bill as a PDF file, ready to share or save.
  async function fetchPdf(bill) {
    const blob = await auth.request(`/admin/bills/${bill.id}/pdf`, { responseType: 'blob' })

    return new File([blob], `${bill.bill_number}.pdf`, { type: 'application/pdf' })
  }

  return {
    bills,
    meta,
    totals,
    listQuery,
    fetchPage,
    fetchOne,
    create,
    update,
    cancel,
    createReturn,
    receivePayment,
    fetchPdf,
  }
})
