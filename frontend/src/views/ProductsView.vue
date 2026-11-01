<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useProductStore } from '@/stores/products'
import { formatMoney, formatQuantity } from '@/utils/format'
import { locationLabel } from '@/utils/location'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import InfoTip from '@/components/InfoTip.vue'

const store = useProductStore()
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
  page: route.query.page ?? '',
}))

const isFiltered = computed(() =>
  Boolean(filters.value.search || filters.value.category_id || filters.value.brand_id),
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
    <RouterLink class="btn btn-primary" :to="{ name: 'product-create' }">New product</RouterLink>
  </div>

  <section class="stats" aria-label="Stock worth">
    <div class="stat">
      <span class="stat-label">{{ isFiltered ? 'Matching products' : 'Products' }}</span>
      <span class="stat-value">{{ store.summary.products }}</span>
    </div>
    <div class="stat">
      <span class="stat-label">Stock worth at cost</span>
      <span class="stat-value">{{ formatMoney(store.summary.cost_value) }}</span>
    </div>
    <div class="stat">
      <span class="stat-label">Stock worth at selling price</span>
      <span class="stat-value">{{ formatMoney(store.summary.selling_value) }}</span>
      <span class="stat-note">
        {{ formatMoney(store.summary.selling_value - store.summary.cost_value) }} above cost
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
      <option value="">All categories</option>
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
      <option value="">All brands</option>
      <option v-for="brand in store.options.brands" :key="brand.id" :value="String(brand.id)">
        {{ brand.name }}
      </option>
    </select>
    <button v-if="isFiltered" type="button" class="btn" @click="clearFilters">Clear</button>
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
      <table class="data-table">
        <thead>
          <tr>
            <th scope="col">Product</th>
            <th scope="col">Category</th>
            <th scope="col">Location <InfoTip term="Location code" /></th>
            <th scope="col" class="num">Stock</th>
            <th scope="col" class="num">Stock worth</th>
            <th scope="col">Status</th>
            <th scope="col" class="actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="product in store.products" :key="product.id">
            <td class="product-cell">
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
            <td>{{ product.category.name }}</td>
            <td class="mono" :title="locationLabel(product.location)">
              {{ product.location.code }}
            </td>
            <td class="num">{{ formatQuantity(product.stock_quantity, product.unit) }}</td>
            <td class="num">
              <div>{{ formatMoney(product.stock_value.cost) }}</div>
              <div class="sells-for">sells for {{ formatMoney(product.stock_value.selling) }}</div>
            </td>
            <td>
              <span class="badge" :class="{ 'badge-active': product.is_active }">
                {{ product.is_active ? 'Active' : 'Inactive' }}
              </span>
            </td>
            <td class="actions">
              <RouterLink
                class="btn"
                :to="{ name: 'product-edit', params: { id: product.id } }"
                :aria-label="`Edit ${product.name}`"
              >
                Edit
              </RouterLink>
              <button
                type="button"
                class="btn"
                :aria-label="`Delete ${product.name}`"
                @click="askDelete(product)"
              >
                Delete
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
.stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(14rem, 1fr));
  gap: 0.75rem;
  margin-bottom: 1.5rem;
}

.stat {
  display: flex;
  flex-direction: column;
  gap: 0.15rem;
  padding: 1rem 1.25rem;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.stat-label {
  font-size: 0.85rem;
}

.stat-value {
  font-size: 1.4rem;
  font-weight: 600;
  color: var(--color-heading);
}

.stat-note {
  font-size: 0.85rem;
  opacity: 0.8;
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
</style>
