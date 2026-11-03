import { ref } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { toQueryString } from '@/utils/query'

export const useActivityStore = defineStore('activity', () => {
  const auth = useAuthStore()

  // The current page of the log, newest first, and its pagination details.
  const entries = ref([])
  const meta = ref({ current_page: 1, last_page: 1, from: null, to: null, total: 0 })

  // What the log can be filtered by.
  const options = ref({ admins: [], sections: [] })

  async function fetchPage(filters = {}) {
    const response = await auth.request(`/admin/activity-logs${toQueryString(filters)}`)

    entries.value = response.data
    meta.value = response.meta
  }

  async function fetchOptions() {
    const { data } = await auth.request('/admin/activity-logs/options')

    options.value = data
  }

  return { entries, meta, options, fetchPage, fetchOptions }
})
