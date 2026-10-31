import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'

export const useCategoryStore = defineStore('categories', () => {
  const auth = useAuthStore()
  const categories = ref([])

  // Every category in tree order: each parent is followed by its subcategories, with their depth.
  const tree = computed(() => {
    const byParent = new Map()

    for (const category of categories.value) {
      const siblings = byParent.get(category.parent_id) ?? []
      siblings.push(category)
      byParent.set(category.parent_id, siblings)
    }

    const ordered = []

    function visit(parentId, depth) {
      const children = byParent.get(parentId) ?? []

      for (const category of children.toSorted((a, b) => a.name.localeCompare(b.name))) {
        ordered.push({ ...category, depth })
        visit(category.id, depth + 1)
      }
    }

    visit(null, 0)

    return ordered
  })

  // The category itself plus everything beneath it: none of these can become its parent.
  function branchIds(id) {
    const start = tree.value.findIndex((category) => category.id === id)
    const ids = new Set()

    if (start === -1) {
      return ids
    }

    ids.add(id)

    for (let i = start + 1; i < tree.value.length; i++) {
      if (tree.value[i].depth <= tree.value[start].depth) {
        break
      }

      ids.add(tree.value[i].id)
    }

    return ids
  }

  async function fetchAll() {
    const { data } = await auth.request('/admin/categories')

    categories.value = data
  }

  async function create(payload) {
    const { data } = await auth.request('/admin/categories', { method: 'POST', body: payload })

    categories.value.push(data)
  }

  async function update(id, payload) {
    const { data } = await auth.request(`/admin/categories/${id}`, { method: 'PUT', body: payload })

    categories.value = categories.value.map((category) => (category.id === id ? data : category))
  }

  async function remove(id) {
    await auth.request(`/admin/categories/${id}`, { method: 'DELETE' })

    categories.value = categories.value.filter((category) => category.id !== id)
  }

  return { categories, tree, branchIds, fetchAll, create, update, remove }
})
