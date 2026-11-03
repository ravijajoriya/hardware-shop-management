import { ref } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { toQueryString } from '@/utils/query'

export const useReportStore = defineStore('reports', () => {
  const auth = useAuthStore()

  // The last sales report loaded: totals, each day, and the top products and customers.
  const sales = ref(null)

  async function fetchSales(from, to) {
    const { data } = await auth.request(`/admin/reports/sales${toQueryString({ from, to })}`)

    sales.value = data
  }

  return { sales, fetchSales }
})
