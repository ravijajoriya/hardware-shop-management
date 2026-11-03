<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useStockStore } from '@/stores/stock'
import { formatDate, formatMoney } from '@/utils/format'
import InfoTip from '@/components/InfoTip.vue'

const stock = useStockStore()
const route = useRoute()
const router = useRouter()

const loaded = ref(false)
const loading = ref(false)
const loadError = ref('')

// The search and the page live in the URL, so they survive a reload and a trip to a delivery.
const filters = computed(() => ({
  search: route.query.search ?? '',
  page: route.query.page ?? '',
}))

function setQuery(changes) {
  const query = { ...route.query, ...changes }

  for (const key of Object.keys(query)) {
    if (query[key] === '' || query[key] === null || query[key] === undefined) {
      delete query[key]
    }
  }

  router.replace({ query })
}

function goToPage(page) {
  setQuery({ page: page > 1 ? String(page) : '' })
}

const search = ref(filters.value.search)
let searchTimer

// A new search starts again from the first page.
watch(search, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    if (value.trim() !== filters.value.search) {
      setQuery({ search: value.trim(), page: '' })
    }
  }, 300)
})

async function load() {
  loading.value = true
  loadError.value = ''

  try {
    await stock.fetchPage(filters.value)
    stock.listQuery = { ...route.query }
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
    if (route.name === 'stock-entries') {
      load()
    }
  },
  { immediate: true },
)
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">Stock in</h1>
    <RouterLink class="btn btn-primary" :to="{ name: 'stock-entry-create' }">
      New stock entry
    </RouterLink>
  </div>

  <p class="intro">
    Every delivery of stock, with what came in and what it cost. Saving one adds it to stock.
  </p>

  <div class="filters">
    <input
      v-model="search"
      class="input"
      type="search"
      placeholder="Search by supplier, their bill number or a product"
      aria-label="Search deliveries"
    />
  </div>

  <p v-if="!loaded">Loading deliveries…</p>

  <div v-else-if="loadError" class="alert-error load-error" role="alert">
    <span>{{ loadError }}</span>
    <button type="button" class="btn" @click="load">Try again</button>
  </div>

  <p v-else-if="stock.entries.length === 0">
    {{
      filters.search
        ? 'No deliveries match this search.'
        : 'No deliveries yet. Make a stock entry when goods come in.'
    }}
  </p>

  <template v-else>
    <div class="table-wrap" :class="{ busy: loading }">
      <table class="data-table stack">
        <thead>
          <tr>
            <th scope="col">Date received</th>
            <th scope="col">Supplier</th>
            <th scope="col">Bill or challan <InfoTip term="Challan" /></th>
            <th scope="col" class="num">Items</th>
            <th scope="col" class="num">Total cost</th>
            <th scope="col">Entered by</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="entry in stock.entries" :key="entry.id">
            <td class="cell-title">
              <RouterLink :to="{ name: 'stock-entry-show', params: { id: entry.id } }">
                {{ formatDate(`${entry.received_on}T00:00:00`) }}
              </RouterLink>
            </td>
            <td class="supplier-cell" data-label="Supplier">{{ entry.supplier_name ?? '—' }}</td>
            <td class="mono">
              <span class="cell-label">Bill or challan <InfoTip term="Challan" /></span>
              {{ entry.supplier_bill_number ?? '—' }}
            </td>
            <td class="num" data-label="Items">{{ entry.items_count }}</td>
            <td class="num" data-label="Total cost">{{ formatMoney(entry.total) }}</td>
            <td data-label="Entered by">{{ entry.created_by ?? '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <nav class="pagination" aria-label="Pages">
      <span>Showing {{ stock.meta.from }}–{{ stock.meta.to }} of {{ stock.meta.total }}</span>
      <div class="pages">
        <button
          type="button"
          class="btn"
          :disabled="stock.meta.current_page <= 1 || loading"
          @click="goToPage(stock.meta.current_page - 1)"
        >
          Previous
        </button>
        <button
          type="button"
          class="btn"
          :disabled="stock.meta.current_page >= stock.meta.last_page || loading"
          @click="goToPage(stock.meta.current_page + 1)"
        >
          Next
        </button>
      </div>
    </nav>
  </template>
</template>

<style scoped>
.intro {
  margin: -0.75rem 0 1rem;
  font-size: 0.9rem;
  opacity: 0.85;
}

.filters {
  margin-bottom: 1rem;
}

.busy {
  opacity: 0.6;
}

.data-table td.supplier-cell {
  min-width: 10rem;
  white-space: normal;
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

/* As cards on tablets and phones (see .stack), the cells share the width. */
@media (max-width: 1000px) {
  .data-table td.supplier-cell {
    min-width: 0;
  }
}
</style>
