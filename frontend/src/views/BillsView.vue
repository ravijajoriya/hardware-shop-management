<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useBillStore } from '@/stores/bills'
import { formatDateTime, formatMoney } from '@/utils/format'
import { BILL_STATUSES, formatMobile, statusClass } from '@/utils/billing'
import InfoTip from '@/components/InfoTip.vue'

const store = useBillStore()
const route = useRoute()
const router = useRouter()

const loaded = ref(false)
const loading = ref(false)
const loadError = ref('')

// Filters and the page live in the URL, so they survive a reload and a trip to a bill and back.
const filters = computed(() => ({
  search: route.query.search ?? '',
  status: route.query.status ?? '',
  page: route.query.page ?? '',
}))

const isFiltered = computed(() => Boolean(filters.value.search || filters.value.status))

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
    if (route.name === 'bills') {
      load()
    }
  },
  { immediate: true },
)
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">Bills</h1>
    <RouterLink class="btn btn-primary" :to="{ name: 'bill-create' }">New bill</RouterLink>
  </div>

  <div class="filters">
    <input
      v-model="search"
      class="input search"
      type="search"
      placeholder="Search by bill number, customer name or mobile"
      aria-label="Search bills"
    />
    <select
      class="input"
      :value="filters.status"
      aria-label="Filter by payment"
      @change="filter({ status: $event.target.value })"
    >
      <option value="">All bills</option>
      <option v-for="status in BILL_STATUSES" :key="status.value" :value="status.value">
        {{ status.label }}
      </option>
    </select>
    <button v-if="isFiltered" type="button" class="btn" @click="clearFilters">Clear</button>
  </div>

  <p v-if="!loaded">Loading bills…</p>

  <div v-else-if="loadError" class="alert-error load-error" role="alert">
    <span>{{ loadError }}</span>
    <button type="button" class="btn" @click="load">Try again</button>
  </div>

  <p v-else-if="store.bills.length === 0">
    {{ isFiltered ? 'No bills match these filters.' : 'No bills yet. Make the first one.' }}
  </p>

  <template v-else>
    <div class="table-wrap" :class="{ busy: loading }">
      <table class="data-table">
        <thead>
          <tr>
            <th scope="col">Bill number <InfoTip term="Bill number" /></th>
            <th scope="col">Date</th>
            <th scope="col">Customer</th>
            <th scope="col" class="num">Items</th>
            <th scope="col" class="num">Total</th>
            <th scope="col" class="num">Paid</th>
            <th scope="col" class="num">Due <InfoTip term="Due" /></th>
            <th scope="col">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="bill in store.bills" :key="bill.id">
            <td>
              <RouterLink class="mono" :to="{ name: 'bill-show', params: { id: bill.id } }">
                {{ bill.bill_number }}
              </RouterLink>
            </td>
            <td>{{ formatDateTime(bill.created_at) }}</td>
            <td class="customer-cell">
              <div class="name">{{ bill.customer.name }}</div>
              <div class="details">{{ formatMobile(bill.customer.mobile) }}</div>
            </td>
            <td class="num">{{ bill.items_count }}</td>
            <td class="num">{{ formatMoney(bill.total) }}</td>
            <td class="num">{{ formatMoney(bill.paid_amount) }}</td>
            <td class="num" :class="{ owing: bill.due_amount > 0 }">
              {{ formatMoney(bill.due_amount) }}
            </td>
            <td>
              <span class="badge" :class="statusClass(bill.status)">{{ bill.status.label }}</span>
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
</template>

<style scoped>
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
  flex: 2 1 16rem;
}

.busy {
  opacity: 0.6;
}

.data-table td.customer-cell {
  min-width: 12rem;
  white-space: normal;
}

.name {
  color: var(--color-heading);
}

.details {
  font-size: 0.85rem;
  opacity: 0.8;
}

.num {
  text-align: right;
}

.owing {
  font-weight: 600;
  color: var(--color-danger);
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
