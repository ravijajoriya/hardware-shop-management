<script setup>
import { onMounted, ref } from 'vue'
import { useCategoryStore } from '@/stores/categories'
import CategoryFormDialog from '@/components/CategoryFormDialog.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'

const store = useCategoryStore()

const loading = ref(true)
const loadError = ref('')

// null while the form is closed; otherwise { category }, where category is null for a new one.
const form = ref(null)

const deleting = ref(null)
const deleteBusy = ref(false)
const deleteError = ref('')

async function load() {
  loading.value = true
  loadError.value = ''

  try {
    await store.fetchAll()
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
}

function askDelete(category) {
  deleteError.value = ''
  deleting.value = category
}

async function confirmDelete() {
  deleteBusy.value = true
  deleteError.value = ''

  try {
    await store.remove(deleting.value.id)
    deleting.value = null
  } catch (e) {
    deleteError.value = e.message
  } finally {
    deleteBusy.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">Categories</h1>
    <button type="button" class="btn btn-primary" @click="form = { category: null }">
      New category
    </button>
  </div>

  <p v-if="loading">Loading categories…</p>

  <div v-else-if="loadError" class="alert-error load-error" role="alert">
    <span>{{ loadError }}</span>
    <button type="button" class="btn" @click="load">Try again</button>
  </div>

  <p v-else-if="store.tree.length === 0">No categories yet. Create the first one to get started.</p>

  <div v-else class="table-wrap">
    <table>
      <thead>
        <tr>
          <th scope="col">Name</th>
          <th scope="col">Slug</th>
          <th scope="col">Status</th>
          <th scope="col" class="actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="category in store.tree" :key="category.id">
          <td>
            <span class="name" :style="{ paddingLeft: `${category.depth * 1.5}rem` }">
              <span v-if="category.depth > 0" class="branch" aria-hidden="true">└</span>
              {{ category.name }}
            </span>
          </td>
          <td class="slug">{{ category.slug }}</td>
          <td>
            <span class="badge" :class="{ 'badge-active': category.is_active }">
              {{ category.is_active ? 'Active' : 'Inactive' }}
            </span>
          </td>
          <td class="actions">
            <button
              type="button"
              class="btn"
              :aria-label="`Edit ${category.name}`"
              @click="form = { category }"
            >
              Edit
            </button>
            <button
              type="button"
              class="btn"
              :aria-label="`Delete ${category.name}`"
              @click="askDelete(category)"
            >
              Delete
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <CategoryFormDialog v-if="form" :category="form.category" @close="form = null" />

  <ConfirmDialog
    v-if="deleting"
    title="Delete category"
    :message="`Delete “${deleting.name}”? This cannot be undone.`"
    confirm-label="Delete"
    :busy="deleteBusy"
    :error="deleteError"
    @confirm="confirmDelete"
    @close="deleting = null"
  />
</template>

<style scoped>
.page-header {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.load-error {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
}

.table-wrap {
  overflow-x: auto;
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

table {
  width: 100%;
  border-collapse: collapse;
}

th,
td {
  padding: 0.65rem 1rem;
  text-align: left;
  white-space: nowrap;
}

th {
  font-weight: 600;
  color: var(--color-heading);
  background: var(--color-background-soft);
}

tbody tr {
  border-top: 1px solid var(--color-border);
}

.name {
  display: inline-block;
  color: var(--color-heading);
}

.branch {
  margin-right: 0.25rem;
  opacity: 0.6;
}

.slug {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 0.9em;
}

.badge {
  display: inline-block;
  padding: 0.1rem 0.6rem;
  font-size: 0.85rem;
  border: 1px solid var(--color-border-hover);
  border-radius: 999px;
}

.badge-active {
  color: var(--color-success);
  border-color: currentColor;
}

.actions {
  text-align: right;
}

.actions .btn + .btn {
  margin-left: 0.5rem;
}
</style>
