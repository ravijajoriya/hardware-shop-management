import { ref } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { toQueryString } from '@/utils/query'

export const useCustomerStore = defineStore('customers', () => {
  const auth = useAuthStore()

  // The current page of customers, by name, with what each was billed and still owes.
  const customers = ref([])
  const meta = ref({ current_page: 1, last_page: 1, from: null, to: null, total: 0 })

  // The list's last filters, so a customer can link back to the same page.
  const listQuery = ref({})

  async function fetchPage(filters = {}) {
    const response = await auth.request(`/admin/customers${toQueryString(filters)}`)

    customers.value = response.data
    meta.value = response.meta
  }

  // The customer with this mobile number, or null when the number is new to the shop.
  async function lookup(mobile) {
    const { data } = await auth.request(`/admin/customers/lookup${toQueryString({ mobile })}`)

    return data
  }

  // A customer with their bills and payments, and the shop details for WhatsApp messages.
  async function fetchOne(id) {
    const { data, shop } = await auth.request(`/admin/customers/${id}`)

    return { customer: data, shop }
  }

  // Take a payment towards what the customer owes. `appliedTo` lists the bills it cleared,
  // oldest first, with how much went to each.
  async function receivePayment(id, payload) {
    const response = await auth.request(`/admin/customers/${id}/payments`, {
      method: 'POST',
      body: payload,
    })

    return { customer: response.data, appliedTo: response.applied_to, shop: response.shop }
  }

  return { customers, meta, listQuery, fetchPage, lookup, fetchOne, receivePayment }
})
