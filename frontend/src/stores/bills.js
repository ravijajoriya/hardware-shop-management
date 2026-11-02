import { ref } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { toQueryString } from '@/utils/query'

export const useBillStore = defineStore('bills', () => {
  const auth = useAuthStore()

  // The current page of bills, newest first, and its pagination details.
  const bills = ref([])
  const meta = ref({ current_page: 1, last_page: 1, from: null, to: null, total: 0 })

  // The list's last filters, so a bill can link back to the same page.
  const listQuery = ref({})

  async function fetchPage(filters = {}) {
    const response = await auth.request(`/admin/bills${toQueryString(filters)}`)

    bills.value = response.data
    meta.value = response.meta
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

  // The bill as a PDF file, ready to share or save.
  async function fetchPdf(bill) {
    const blob = await auth.request(`/admin/bills/${bill.id}/pdf`, { responseType: 'blob' })

    return new File([blob], `${bill.bill_number}.pdf`, { type: 'application/pdf' })
  }

  return { bills, meta, listQuery, fetchPage, fetchOne, create, fetchPdf }
})
