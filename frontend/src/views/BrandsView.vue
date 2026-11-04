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
    <table class="data-table stack">
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
          <td class="name cell-title b-name">{{ brand.name }}</td>
          <td class="b-slug">
            <span class="cell-label">Slug <InfoTip term="Slug" /></span>
            <span class="mono slug">{{ brand.slug }}</span>
            <span class="phone-only">
              {{ brand.products_count ?? 0 }}
              {{ brand.products_count === 1 ? 'Item' : 'Items' }}
            </span>
          </td>
          <td class="b-status" data-label="Status">
            <span class="badge" :class="{ 'badge-active': brand.is_active }">
              {{ brand.is_active ? 'Active' : 'Inactive' }}
            </span>
          </td>
          <td class="actions">
            <button
              type="button"
              class="btn action"
              :aria-label="`Edit ${brand.name}`"
              title="Edit"
              @click="form = { brand }"
            >
              <svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3z" />
                <path d="M13.5 8.5l2 2" />
              </svg>
              <span class="action-text">Edit</span>
            </button>
            <button
              type="button"
              class="btn action action-delete"
              :aria-label="`Delete ${brand.name}`"
              title="Delete"
              @click="askDelete(brand)"
            >
              <svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M4 7h16M10 11v6M14 11v6M9 7V4h6v3" />
                <path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12" />
              </svg>
              <span class="action-text">Delete</span>
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

/* Only on a phone: each row's product count and the action icons. */
.phone-only,
.action-icon {
  display: none;
}

/*
 * On a phone each brand is one line: the name, how many products it has, its status, and Edit
 * and Delete side by side. A long name wraps under itself. The slug is left to wider screens.
 */
@media (max-width: 640px) {
  .phone-only {
    display: inline;
  }

  .data-table.stack tr {
    grid-template-columns: minmax(0, 1fr) auto auto auto auto;
    grid-template-areas: 'name meta status edit delete';
    gap: 0.15rem 0.5rem;
    align-items: center;
    padding: 0.45rem 0.75rem 0.45rem 1rem;
  }

  .data-table.stack td.b-name {
    grid-area: name;
  }

  .data-table.stack td.b-status {
    grid-area: status;
  }

  .data-table.stack td.b-status::before {
    content: none;
  }

  .data-table.stack td.b-status .badge {
    padding: 0 0.5rem;
    font-size: 0.75rem;
  }

  .data-table.stack td.b-slug {
    grid-area: meta;
    font-size: 0.8rem;
    opacity: 0.8;
  }

  .data-table.stack td.b-slug .cell-label,
  .data-table.stack td.b-slug .slug {
    display: none;
  }

  /* The cell steps aside so the two icons take their own places in the row's grid. */
  .data-table.stack td.actions {
    display: contents;
  }

  .data-table .actions .action {
    grid-area: edit;
    justify-content: center;
    align-items: center;
    width: 2.25rem;
    height: 2.25rem;
    margin: 0;
    padding: 0;
  }

  .data-table .actions .action-delete {
    grid-area: delete;
    color: var(--color-danger);
  }

  .action-icon {
    display: block;
    width: 1.15rem;
    height: 1.15rem;
    fill: none;
    stroke: currentColor;
    stroke-width: 1.8;
    stroke-linecap: round;
    stroke-linejoin: round;
  }

  .action-text {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
  }
}

/* On the smallest phones one line is too tight, so the count and status go under the name,
   sharing a line of text, with the icons still on the right. */
@media (max-width: 350px) {
  .data-table.stack tr {
    grid-template-columns: auto auto minmax(0, 1fr) auto auto;
    grid-template-areas:
      'name name name edit delete'
      'meta status . edit delete';
    padding-block: 0.6rem;
  }

  .data-table.stack td.b-slug,
  .data-table.stack td.b-status {
    align-self: baseline;
  }
}
</style>
