import { ref } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'

export const useDashboardStore = defineStore('dashboard', () => {
  const auth = useAuthStore()

  // Today's bills, sales and money collected (by the shop's clock), and all that is still due.
  const today = ref(null)

  async function fetchToday() {
    const { data } = await auth.request('/admin/dashboard/summary')

    today.value = data
  }

  return { today, fetchToday }
})
