import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'

export const useBrandStore = defineStore('brands', () => {
  const auth = useAuthStore()
  const brands = ref([])

  // The API lists brands by name; keep that order after local changes too.
  const sorted = computed(() => brands.value.toSorted((a, b) => a.name.localeCompare(b.name)))

  async function fetchAll() {
    const { data } = await auth.request('/admin/brands')

    brands.value = data
  }

  async function create(payload) {
    const { data } = await auth.request('/admin/brands', { method: 'POST', body: payload })

    brands.value.push(data)
  }

  async function update(id, payload) {
    const { data } = await auth.request(`/admin/brands/${id}`, { method: 'PUT', body: payload })

    brands.value = brands.value.map((brand) => (brand.id === id ? data : brand))
  }

  async function remove(id) {
    await auth.request(`/admin/brands/${id}`, { method: 'DELETE' })

    brands.value = brands.value.filter((brand) => brand.id !== id)
  }

  return { brands, sorted, fetchAll, create, update, remove }
})
