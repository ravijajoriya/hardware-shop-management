<script setup>
import { computed, onMounted, ref } from 'vue'
import { useCategoryStore } from '@/stores/categories'
import { treeRows } from '@/utils/categoryTree'
import CategoryFormDialog from '@/components/CategoryFormDialog.vue'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import InfoTip from '@/components/InfoTip.vue'

const store = useCategoryStore()

// The categories in tree order, with what a phone needs to draw the tree and the product counts.
const rows = computed(() => treeRows(store.tree))

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

  <p v-else-if="store.tree.length" class="legend phone-only">
    Under each name: its slug <InfoTip term="Slug" /> and how many products it holds, with its
    subcategories.
  </p>

  <div v-if="!loading && !loadError && store.tree.length" class="table-wrap">
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
        <tr
          v-for="category in rows"
          :key="category.id"
          :class="{ 'group-start': category.groupStart, 'group-end': category.groupEnd }"
          :style="{ '--depth': category.depth }"
        >
          <td class="cell-title c-name">
            <span class="tree" aria-hidden="true">
              <span
                v-for="(on, level) in category.lines"
                :key="level"
                class="guide"
                :class="{ on }"
                :style="{ '--level': level + 1 }"
              />
              <span
                v-if="category.depth > 0"
                class="elbow"
                :class="{ last: category.last }"
                :style="{ '--level': category.depth }"
              />
              <span
                v-if="category.hasChildren"
                class="stem"
                :style="{ '--level': category.depth + 1 }"
              />
            </span>
            <span class="name">
              <span v-if="category.depth > 0" class="branch" aria-hidden="true">└</span>
              {{ category.name }}
            </span>
          </td>
          <td class="c-slug">
            <span class="cell-label">Slug <InfoTip term="Slug" /></span>
            <span class="mono">{{ category.slug }}</span
            ><span class="phone-only">{{
              ` · ${category.total} ${category.total === 1 ? 'product' : 'products'}`
            }}</span>
          </td>
          <td class="c-status" :class="{ 'is-active': category.is_active }" data-label="Status">
            <span class="badge" :class="{ 'badge-active': category.is_active }">
              {{ category.is_active ? 'Active' : 'Inactive' }}
            </span>
          </td>
          <td class="actions">
            <button
              type="button"
              class="btn action"
              :aria-label="`Edit ${category.name}`"
              title="Edit"
              @click="form = { category }"
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
              :aria-label="`Delete ${category.name}`"
              title="Delete"
              @click="askDelete(category)"
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
/* Subcategories are indented under their parent, a step for each level. */
.name {
  display: inline-block;
  padding-left: calc(var(--depth, 0) * 1.5rem);
  color: var(--color-heading);
}

.branch {
  margin-right: 0.25rem;
  opacity: 0.6;
}

/* Edit and Delete are words on a wide screen and icons on a phone. */
.action-icon {
  display: none;
}

/* Only on a phone: the note above the list, each row's product count and the tree's lines. */
.phone-only,
.tree {
  display: none;
}

/*
 * On a phone each top-level category is a card holding its subcategories, joined by tree lines.
 * A row has the name (and Inactive, when it is) with the Edit icon, then the slug and product
 * count with the Delete icon. Active is the usual case, so it is not repeated on every row.
 */
@media (max-width: 640px) {
  .legend {
    display: block;
    margin: -0.5rem 0 0.75rem;
    font-size: 0.85rem;
    opacity: 0.85;
  }

  .phone-only {
    display: inline;
  }

  /* The cards stand on their own instead of inside one framed list. */
  .table-wrap {
    overflow: visible;
    border: 0;
  }

  .data-table.stack tr {
    --step: 1.4rem;
    --tick: 1.7rem;

    position: relative;
    grid-template-columns: minmax(0, 1fr) auto auto;
    grid-template-areas:
      'name status edit'
      'meta meta delete';
    gap: 0.2rem 0.5rem;
    align-items: center;
    padding: 0.55rem 0.75rem 0.55rem calc(1rem + var(--depth, 0) * var(--step));
    background: var(--color-background-soft);
    border: 1px solid var(--color-border);
    border-block: 0;
  }

  .data-table.stack tr.group-start {
    margin-top: 0.6rem;
    border-top: 1px solid var(--color-border);
    border-radius: 8px 8px 0 0;
  }

  .data-table.stack tr.group-start:first-child {
    margin-top: 0;
  }

  .data-table.stack tr.group-end {
    border-bottom: 1px solid var(--color-border);
    border-radius: 0 0 8px 8px;
  }

  .data-table.stack tr.group-start.group-end {
    border-radius: 8px;
  }

  .data-table.stack td.c-name {
    grid-area: name;
  }

  .name {
    padding-left: 0;
  }

  .branch {
    display: none;
  }

  .data-table.stack td.c-status {
    grid-area: status;
  }

  .data-table.stack td.c-status.is-active {
    display: none;
  }

  .data-table.stack td.c-status::before {
    content: none;
  }

  .data-table.stack td.c-slug {
    grid-area: meta;
    font-size: 0.8rem;
    opacity: 0.8;
  }

  .data-table.stack td.c-slug .cell-label {
    display: none;
  }

  /* The tree's lines: one for each ancestor that has more below, the elbow joining the row to
     its parent (stopping here for the last child), and a stem down to its own subcategories. */
  .tree {
    display: block;
  }

  .guide,
  .elbow,
  .stem {
    position: absolute;
    left: calc(1rem + (var(--level) - 1) * var(--step) + 0.45rem);
    width: 0;
    border-left: 1px solid var(--color-border-hover);
  }

  .guide {
    top: 0;
    bottom: 0;
    visibility: hidden;
  }

  .guide.on {
    visibility: visible;
  }

  .elbow {
    top: 0;
    bottom: 0;
  }

  .elbow.last {
    bottom: auto;
    height: var(--tick);
  }

  .elbow::after {
    content: '';
    position: absolute;
    top: var(--tick);
    left: 0;
    width: calc(var(--step) - 0.75rem);
    border-top: 1px solid var(--color-border-hover);
  }

  /* Only below the slug line, so the stem never runs through the parent's own words. */
  .stem {
    bottom: 0;
    height: 0.55rem;
  }

  /* The cell steps aside so the two icons take their own places in the card's grid. */
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
</style>
