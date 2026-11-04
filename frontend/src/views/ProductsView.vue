<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useProductStore } from '@/stores/products'
import { formatMoney, formatQuantity } from '@/utils/format'
import { locationLabel } from '@/utils/location'
import { useMediaQuery } from '@/composables/useMediaQuery'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import InfoTip from '@/components/InfoTip.vue'

const store = useProductStore()

// On a phone the two lists and the low stock box share one line, so their words are shorter.
const isPhone = useMediaQuery('(max-width: 640px)')
const route = useRoute()
const router = useRouter()

const loaded = ref(false)
const loading = ref(false)
const loadError = ref('')

const deleting = ref(null)
const deleteBusy = ref(false)
const deleteError = ref('')

// Filters and the page live in the URL, so they survive a reload and a trip to the form and back.
const filters = computed(() => ({
  search: route.query.search ?? '',
  category_id: route.query.category_id ?? '',
  brand_id: route.query.brand_id ?? '',
  low_stock: route.query.low_stock === '1' ? '1' : '',
  page: route.query.page ?? '',
}))

const isFiltered = computed(() =>
  Boolean(
    filters.value.search ||
    filters.value.category_id ||
    filters.value.brand_id ||
    filters.value.low_stock,
  ),
)

function setQuery(changes) {
  const query = { ...route.query, ...changes }

  for (const key of Object.keys(query)) {
    if (query[key] === '' || query[key] === null || query[key] === undefined) {
      delete query[key]
    }
  }

  router.replace({ query })
}

// Changing a filter starts again from the first page.
function filter(changes) {
  setQuery({ ...changes, page: '' })
}

function goToPage(page) {
  setQuery({ page: page > 1 ? String(page) : '' })
}

const search = ref(filters.value.search)
let searchTimer

watch(search, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    if (value.trim() !== filters.value.search) {
      filter({ search: value.trim() })
    }
  }, 300)
})

function clearFilters() {
  search.value = ''
  router.replace({ query: {} })
}

async function load() {
  loading.value = true
  loadError.value = ''

  try {
    await store.fetchPage(filters.value)
    store.listQuery = { ...route.query }
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
    loaded.value = true
  }
}

watch(
  () => route.query,
  () => {
    // The query also changes while leaving for another page; only reload while still here.
    if (route.name === 'products') {
      load()
    }
  },
  { immediate: true },
)

onMounted(() => {
  // The filters still work as a search without these choices, so a failure here is not shown.
  store.fetchOptions().catch(() => {})
})

function variantSummary(product) {
  if (product.variants.length > 1) {
    return `${product.variants.length} variants`
  }

  return product.variants[0]?.name ?? ''
}

function askDelete(product) {
  deleteError.value = ''
  deleting.value = product
}

async function confirmDelete() {
  deleteBusy.value = true
  deleteError.value = ''

  try {
    const wasLastOnPage = store.products.length === 1

    await store.remove(deleting.value.id)
    deleting.value = null

    if (wasLastOnPage && store.meta.current_page > 1) {
      goToPage(store.meta.current_page - 1)
    } else {
      await load()
    }
  } catch (e) {
    deleteError.value = e.message
  } finally {
    deleteBusy.value = false
  }
}
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">Products</h1>
    <div class="header-actions">
      <RouterLink class="btn" :to="{ name: 'product-import' }">Import from Excel</RouterLink>
      <RouterLink class="btn btn-primary" :to="{ name: 'product-create' }">New product</RouterLink>
    </div>
  </div>

  <section class="stats" aria-label="Stock Value">
    <div class="stat">
      <span class="stat-label">{{ isFiltered ? 'Matching products' : 'Total Products' }}</span>
      <span class="stat-value">{{ store.summary.products }}</span>
    </div>
    <div class="stat" :class="{ 'stat-low': store.summary.low_stock > 0 }">
      <span class="stat-label">Low on stock <InfoTip term="Minimum stock" /></span>
      <span class="stat-value">{{ store.summary.low_stock }}</span>
      <button
        v-if="store.summary.low_stock > 0 && filters.low_stock !== '1'"
        type="button"
        class="show-low"
        @click="filter({ low_stock: '1' })"
      >
        Show {{ store.summary.low_stock === 1 ? 'it' : 'them' }}
      </button>
      <span v-else-if="store.summary.low_stock === 0" class="stat-note">
        Nothing is below its minimum stock
      </span>
    </div>
    <div class="stat">
      <span class="stat-label">Stock Value At Cost</span>
      <span class="stat-value">{{ formatMoney(store.summary.cost_value) }}</span>
    </div>
    <div class="stat">
      <span class="stat-label">Stock Value At Sale</span>
      <span class="stat-value">{{ formatMoney(store.summary.selling_value) }}</span>
      <span class="stat-note">
        {{ formatMoney(store.summary.selling_value - store.summary.cost_value) }} Margin
        <InfoTip term="Margin" />
      </span>
    </div>
  </section>

  <div class="filters">
    <input
      v-model="search"
      class="input search"
      type="search"
      placeholder="Search by product name or item code"
      aria-label="Search products"
    />
    <select
      class="input"
      :value="filters.category_id"
      aria-label="Filter by category"
      @change="filter({ category_id: $event.target.value })"
    >
      <option value="">{{ isPhone ? 'All Category' : 'All Categories' }}</option>
      <option
        v-for="category in store.options.categories"
        :key="category.id"
        :value="String(category.id)"
      >
        {{ '— '.repeat(category.depth) }}{{ category.name }}
      </option>
    </select>
    <select
      class="input"
      :value="filters.brand_id"
      aria-label="Filter by brand"
      @change="filter({ brand_id: $event.target.value })"
    >
      <option value="">{{ isPhone ? 'All Brand' : 'All Brands' }}</option>
      <option v-for="brand in store.options.brands" :key="brand.id" :value="String(brand.id)">
        {{ brand.name }}
      </option>
    </select>
    <label class="checkbox">
      <input
        type="checkbox"
        :checked="filters.low_stock === '1'"
        @change="filter({ low_stock: $event.target.checked ? '1' : '' })"
      />
      <span class="low-label">{{ isPhone ? 'Low Stock' : 'Only low stock' }}</span>
    </label>
    <button v-if="isFiltered" type="button" class="btn clear" @click="clearFilters">Clear</button>
  </div>

  <p v-if="!loaded">Loading products…</p>

  <div v-else-if="loadError" class="alert-error load-error" role="alert">
    <span>{{ loadError }}</span>
    <button type="button" class="btn" @click="load">Try again</button>
  </div>

  <p v-else-if="store.products.length === 0">
    {{
      isFiltered
        ? 'No products match these filters.'
        : 'No products yet. Create the first one to get started.'
    }}
  </p>

  <template v-else>
    <div class="table-wrap" :class="{ busy: loading }">
      <table class="data-table stack">
        <thead>
          <tr>
            <th scope="col">Product</th>
            <th scope="col">Category</th>
            <th scope="col">Location <InfoTip term="Location code" /></th>
            <th scope="col" class="num">Stock</th>
            <th scope="col" class="num">Stock Value</th>
            <th scope="col">Status</th>
            <th scope="col" class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="product in store.products" :key="product.id">
            <td class="product-cell cell-title">
              <div class="product">
                <img v-if="product.image_url" :src="product.image_url" alt="" class="thumb" />
                <span v-else class="thumb" aria-hidden="true"></span>
                <div>
                  <div class="name">{{ product.name }}</div>
                  <div class="details">
                    {{ [product.brand?.name, variantSummary(product)].filter(Boolean).join(' · ') }}
                  </div>
                </div>
              </div>
            </td>
            <td class="p-cat" data-label="Category">{{ product.category.name }}</td>
            <td class="mono p-loc" :title="locationLabel(product.location)">
              <span class="cell-label">Location <InfoTip term="Location code" /></span>
              {{ product.location.code }}
            </td>
            <td class="num p-stock" data-label="Stock">
              {{ formatQuantity(product.stock_quantity, product.unit) }}
              <div v-if="product.low_stock" class="low">Low stock</div>
            </td>
            <td class="num p-worth" data-label="Stock Value">
              <div>{{ formatMoney(product.stock_value.cost) }}</div>
              <div class="sells-for">sells for {{ formatMoney(product.stock_value.selling) }}</div>
            </td>
            <!-- On a phone the selling worth is its own value in the card (see the styles). -->
            <td class="num sells-cell p-sells" data-label="Sells for">
              {{ formatMoney(product.stock_value.selling) }}
            </td>
            <td class="p-status" data-label="Status">
              <span class="badge" :class="{ 'badge-active': product.is_active }">
                {{ product.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="actions">
              <RouterLink
                class="btn action"
                :to="{ name: 'product-edit', params: { id: product.id } }"
                :aria-label="`Edit ${product.name}`"
                title="Edit"
              >
                <svg class="action-icon" viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M4 20h4L18.5 9.5a2.1 2.1 0 0 0-3-3L5 17v3z" />
                  <path d="M13.5 8.5l2 2" />
                </svg>
                <span class="action-text">Edit</span>
              </RouterLink>
              <button
                type="button"
                class="btn action action-delete"
                :aria-label="`Delete ${product.name}`"
                title="Delete"
                @click="askDelete(product)"
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

    <nav class="pagination" aria-label="Pages">
      <span>Showing {{ store.meta.from }}–{{ store.meta.to }} of {{ store.meta.total }}</span>
      <div class="pages">
        <button
          type="button"
          class="btn"
          :disabled="store.meta.current_page <= 1 || loading"
          @click="goToPage(store.meta.current_page - 1)"
        >
          Previous
        </button>
        <button
          type="button"
          class="btn"
          :disabled="store.meta.current_page >= store.meta.last_page || loading"
          @click="goToPage(store.meta.current_page + 1)"
        >
          Next
        </button>
      </div>
    </nav>
  </template>

  <ConfirmDialog
    v-if="deleting"
    title="Delete product"
    :message="`Delete “${deleting.name}” with its variants, stock and images? This cannot be undone.`"
    confirm-label="Delete"
    :busy="deleteBusy"
    :error="deleteError"
    @confirm="confirmDelete"
    @close="deleting = null"
  />
</template>

<style scoped>
.header-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 0.5rem;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.filters .input {
  flex: 1 1 11rem;
  width: auto;
}

.filters .search {
  flex: 2 1 14rem;
}

.stat-low .stat-value,
.low {
  color: var(--color-danger);
}

.low {
  font-size: 0.8rem;
  font-weight: 600;
}

.show-low {
  display: inline-flex;
  align-items: center;
  align-self: flex-start;
  min-height: 2rem;
  padding: 0;
  font: inherit;
  font-size: 0.85rem;
  color: hsla(160, 100%, 37%, 1);
  background: none;
  border: 0;
  cursor: pointer;
}

.busy {
  opacity: 0.6;
}

.product {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.thumb {
  flex: none;
  width: 40px;
  height: 40px;
  object-fit: cover;
  background: var(--color-background-mute);
  border: 1px solid var(--color-border);
  border-radius: 6px;
}

.name {
  color: var(--color-heading);
}

/* Long product names wrap, so the other columns keep their room. */
.data-table td.product-cell {
  min-width: 16rem;
  white-space: normal;
}

.sells-for {
  font-size: 0.85rem;
  opacity: 0.75;
}

.data-table td.sells-cell {
  display: none;
}

/* Edit and Delete are words on a wide screen and icons on a phone. */
.action-icon {
  display: none;
}

.details {
  font-size: 0.85rem;
  opacity: 0.8;
}

.num {
  text-align: right;
}

.pagination {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-top: 1rem;
}

.pages {
  display: flex;
  gap: 0.5rem;
}

/* A little narrower on smaller laptops and landscape tablets, so every column fits. */
@media (max-width: 1100px) {
  .data-table td.product-cell {
    min-width: 13rem;
  }
}

/* As cards on tablets and phones (see .stack), the cells share the width. */
@media (max-width: 1000px) {
  .data-table td.product-cell {
    min-width: 0;
  }
}

/* On a phone the cards sit two to a line: Products and Stock worth at cost, then Stock worth
   at selling price and Low on stock. */
@media (max-width: 640px) {
  /* The search on its own line (with Clear beside it when filters are on), then Category,
     Brand and the low stock box together on the next. */
  .filters {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
    align-items: center;
  }

  .filters .search {
    grid-column: 1 / -1;
  }

  .filters:has(> .clear) .search {
    grid-column: 1 / 3;
  }

  /* Clear shares a column with the narrow low stock box, so it stays as narrow. */
  .filters > .clear {
    grid-row: 1;
    grid-column: 3;
    padding-inline: 0.5rem;
  }

  .filters select.input {
    width: 100%;
    min-width: 0;
    padding-inline: 0.6rem;
  }

  .filters .checkbox {
    gap: 0.35rem;
  }

  /* "Low" above "Stock", so the box takes little room and the two lists get the rest. */
  .filters .low-label {
    display: inline-block;
    width: min-content;
    font-size: 0.8rem;
    line-height: 1.15;
  }

  .stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.6rem;
  }

  .stat {
    padding: 0.8rem 0.85rem;
  }

  /* Large stock worths, such as ₹1,23,45,678, wrap instead of running out of the card. */
  .stat-value {
    font-size: 1.15rem;
    overflow-wrap: anywhere;
  }

  /* Each product card shows its details three to a line: Category, Location and Stock with the
     Edit icon at its end, then Stock worth, Sells for and Status with the Delete icon. */
  .data-table.stack tr {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto auto;
    grid-template-areas:
      'title title title title'
      'cat loc stock edit'
      'worth sells status delete';
    column-gap: 0.6rem;
  }

  .data-table.stack td.product-cell {
    grid-area: title;
  }

  .data-table.stack td.p-cat {
    grid-area: cat;
  }

  .data-table.stack td.p-loc {
    grid-area: loc;
  }

  .data-table.stack td.p-stock {
    grid-area: stock;
  }

  .data-table.stack td.p-worth {
    grid-area: worth;
  }

  .data-table.stack td.p-sells {
    grid-area: sells;
  }

  .data-table.stack td.p-status {
    grid-area: status;
  }

  .data-table.stack .sells-for {
    display: none;
  }

  .data-table.stack td.sells-cell {
    display: block;
  }

  /* Edit and Delete as icons, each at the end of a line of details: the cell steps aside so
     the two icons take their own places in the card's grid. */
  .data-table.stack td.actions {
    display: contents;
  }

  .data-table .actions .action {
    grid-area: edit;
    align-self: center;
    justify-content: center;
    align-items: center;
    width: 2.25rem;
    height: 2.25rem;
    margin: 0;
    padding: 0;
  }

  .data-table .actions .action-delete {
    grid-area: delete;
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

  .action-delete {
    color: var(--color-danger);
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

/* On the smallest phones the filter line and the card's four columns need tighter spacing and
   slightly smaller words, with a little more room for "Location" and its "i". */
@media (max-width: 350px) {
  .filters {
    gap: 0.4rem;
  }

  .filters select.input {
    padding-inline: 0.4rem;
  }

  .filters .checkbox {
    gap: 0.3rem;
  }

  .data-table.stack tr {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1.15fr) auto auto;
    column-gap: 0.4rem;
    padding-inline: 0.75rem;
  }

  .data-table.stack :is(td.p-cat, td.p-loc, td.p-stock, td.p-worth, td.p-sells, td.p-status) {
    font-size: 0.85rem;
  }

  .data-table.stack td[data-label]::before,
  .data-table.stack .cell-label {
    font-size: 0.72rem;
  }
}
</style>
