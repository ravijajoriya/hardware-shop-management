<script setup>
import { onMounted, ref } from 'vue'
import { useBrandStore } from '@/stores/brands'
import BrandFormDialog from '@/components/BrandFormDialog.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import InfoTip from '@/components/InfoTip.vue'

const store = useBrandStore()

const loading = ref(true)
const loadError = ref('')

// null while the form is closed; otherwise { brand }, where brand is null for a new one.
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

function askDelete(brand) {
  deleteError.value = ''
  deleting.value = brand
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
    <h1 class="page-title">Brands</h1>
    <button type="button" class="btn btn-primary" @click="form = { brand: null }">New brand</button>
  </div>

  <p v-if="loading">Loading brands…</p>

  <div v-else-if="loadError" class="alert-error load-error" role="alert">
    <span>{{ loadError }}</span>
    <button type="button" class="btn" @click="load">Try again</button>
  </div>

  <p v-else-if="store.sorted.length === 0">No brands yet. Create the first one to get started.</p>

  <div v-else class="table-wrap">
    <table class="data-table">
      <thead>
        <tr>
          <th scope="col">Name</th>
          <th scope="col">Slug <InfoTip term="Slug" /></th>
          <th scope="col">Status</th>
          <th scope="col" class="actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="brand in store.sorted" :key="brand.id">
          <td class="name">{{ brand.name }}</td>
          <td class="mono">{{ brand.slug }}</td>
          <td>
            <span class="badge" :class="{ 'badge-active': brand.is_active }">
              {{ brand.is_active ? 'Active' : 'Inactive' }}
            </span>
          </td>
          <td class="actions">
            <button
              type="button"
              class="btn"
              :aria-label="`Edit ${brand.name}`"
              @click="form = { brand }"
            >
              Edit
            </button>
            <button
              type="button"
              class="btn"
              :aria-label="`Delete ${brand.name}`"
              @click="askDelete(brand)"
            >
              Delete
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <BrandFormDialog v-if="form" :brand="form.brand" @close="form = null" />

  <ConfirmDialog
    v-if="deleting"
    title="Delete brand"
    :message="`Delete “${deleting.name}”? This cannot be undone.`"
    confirm-label="Delete"
    :busy="deleteBusy"
    :error="deleteError"
    @confirm="confirmDelete"
    @close="deleting = null"
  />
</template>

<style scoped>
.name {
  color: var(--color-heading);
}
</style>
