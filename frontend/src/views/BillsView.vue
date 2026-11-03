<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useBillStore } from '@/stores/bills'
import { formatDateTime, formatMoney } from '@/utils/format'
import {
  BILL_CHANGES,
  BILL_SORTS,
  BILL_STATUSES,
  OVERDUE_DAYS,
  PAYMENT_METHODS,
  formatMobile,
  statusClass,
} from '@/utils/billing'
import { PERIODS, periodOf, periodRange } from '@/utils/reports'
import FieldLabel from '@/components/FieldLabel.vue'
import InfoTip from '@/components/InfoTip.vue'

const store = useBillStore()
const route = useRoute()
const router = useRouter()

const loaded = ref(false)
const loading = ref(false)
const loadError = ref('')

// What the list can be narrowed by. Sorting only orders it.
const FILTERS = ['search', 'product', 'from', 'to', 'status', 'method', 'overdue', 'changes']

// Filters, the order and the page live in the URL, so they survive a reload and a trip to a
// bill and back.
const filters = computed(() =>
  Object.fromEntries(
    [...FILTERS, 'sort', 'page'].map((key) => [
      key,
      typeof route.query[key] === 'string' ? route.query[key] : '',
    ]),
  ),
)

const isFiltered = computed(() => FILTERS.some((key) => filters.value[key]))

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

// A typed filter waits for a pause in typing, and follows the URL when that changes
// some other way, such as the Bills link in the menu clearing every filter.
function typedFilter(key) {
  const text = ref(filters.value[key])
  let timer

  watch(text, (value) => {
    clearTimeout(timer)
    timer = setTimeout(() => {
      if (value.trim() !== filters.value[key]) {
        filter({ [key]: value.trim() })
      }
    }, 300)
  })

  watch(
    () => filters.value[key],
    (value) => {
      if (text.value.trim() !== value) {
        text.value = value
      }
    },
  )

  return text
}

const search = typedFilter('search')
const product = typedFilter('product')

// The date filter: a ready-made period, or days chosen by hand. "Choose dates…" stays picked
// while the days are filled in, even when they happen to match a ready-made period.
const choosingDates = ref(false)

const dateChoice = computed(() => {
  const { from, to } = filters.value

  if (choosingDates.value || (from === '') !== (to === '')) {
    return 'custom'
  }

  return from ? periodOf(from, to) : ''
})

function chooseDate(value) {
  choosingDates.value = value === 'custom'

  if (value !== 'custom') {
    filter(value ? periodRange(value) : { from: '', to: '' })
  }
}

function clearFilters() {
  choosingDates.value = false
  router.replace({ query: {} })
}

const totals = computed(() => store.totals)
const counted = computed(() => (totals.value ? totals.value.bills - totals.value.cancelled : 0))

async function load() {
  loading.value = true
  loadError.value = ''

  try {
    await store.fetchPage(filters.value)
    store.listQuery = { ...route.query }
  } catch (e) {
    loadError.value = Object.values(e.errors ?? {})[0]?.[0] ?? e.message
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
    <div class="typed">
      <input
        v-model="search"
        class="input search"
        type="search"
        placeholder="Search by bill number, customer name or mobile"
        aria-label="Search bills"
      />
      <input
        v-model="product"
        class="input"
        type="search"
        placeholder="Product name or item code"
        aria-label="Bills with this product"
      />
      <button v-if="isFiltered || filters.sort" type="button" class="btn" @click="clearFilters">
        Clear filters
      </button>
    </div>

    <div class="choices">
      <div class="choice-field">
        <label for="bills-date">Date</label>
        <select
          id="bills-date"
          class="input"
          :value="dateChoice"
          @change="chooseDate($event.target.value)"
        >
          <option value="">Any date</option>
          <option v-for="option in PERIODS" :key="option.value" :value="option.value">
            {{ option.label }}
          </option>
          <option value="custom">Choose dates…</option>
        </select>
      </div>
      <template v-if="dateChoice">
        <div class="choice-field">
          <label for="bills-from">From</label>
          <input
            id="bills-from"
            class="input"
            type="date"
            :value="filters.from"
            :max="filters.to || undefined"
            @change="filter({ from: $event.target.value })"
          />
        </div>
        <div class="choice-field">
          <label for="bills-to">To</label>
          <input
            id="bills-to"
            class="input"
            type="date"
            :value="filters.to"
            :min="filters.from || undefined"
            @change="filter({ to: $event.target.value })"
          />
        </div>
      </template>
      <div class="choice-field">
        <label for="bills-status">Status</label>
        <select
          id="bills-status"
          class="input"
          :value="filters.status"
          @change="filter({ status: $event.target.value })"
        >
          <option value="">Any</option>
          <option v-for="status in BILL_STATUSES" :key="status.value" :value="status.value">
            {{ status.label }}
          </option>
        </select>
      </div>
      <div class="choice-field">
        <label for="bills-method">Paid by</label>
        <select
          id="bills-method"
          class="input"
          :value="filters.method"
          @change="filter({ method: $event.target.value })"
        >
          <option value="">Any</option>
          <option v-for="method in PAYMENT_METHODS" :key="method.value" :value="method.value">
            {{ method.label }}
          </option>
        </select>
      </div>
      <div class="choice-field">
        <FieldLabel input-id="bills-overdue" term="Due">Old dues</FieldLabel>
        <select
          id="bills-overdue"
          class="input"
          :value="filters.overdue"
          @change="filter({ overdue: $event.target.value })"
        >
          <option value="">Any</option>
          <option v-for="days in OVERDUE_DAYS" :key="days" :value="String(days)">
            Due for over {{ days }} days
          </option>
        </select>
      </div>
      <div class="choice-field">
        <label for="bills-changes">Changes</label>
        <select
          id="bills-changes"
          class="input"
          :value="filters.changes"
          @change="filter({ changes: $event.target.value })"
        >
          <option value="">Any</option>
          <option v-for="change in BILL_CHANGES" :key="change.value" :value="change.value">
            {{ change.label }}
          </option>
        </select>
      </div>
    </div>
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
    <div class="list-head" :class="{ busy: loading }">
      <p v-if="totals" class="totals">
        <strong>{{ totals.bills }} {{ totals.bills === 1 ? 'bill' : 'bills' }}</strong>
        <template v-if="counted > 0">
          · {{ formatMoney(totals.billed) }} billed<template v-if="totals.returned > 0">
            after {{ formatMoney(totals.returned) }} returned</template
          >
          · <span :class="{ owing: totals.due > 0 }">{{ formatMoney(totals.due) }} due</span>
        </template>
        <template v-if="totals.cancelled > 0">
          · {{ totals.cancelled }} cancelled, not added in
        </template>
      </p>
      <div class="sort">
        <label for="bills-sort">Sort by</label>
        <select
          id="bills-sort"
          class="input"
          :value="filters.sort"
          @change="filter({ sort: $event.target.value })"
        >
          <option v-for="sort in BILL_SORTS" :key="sort.value" :value="sort.value">
            {{ sort.label }}
          </option>
        </select>
      </div>
    </div>

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
  flex-direction: column;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.typed {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.typed > .input {
  flex: 1 1 12rem;
  width: auto;
}

.typed > .search {
  flex: 2 1 16rem;
}

.choices {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(9.5rem, 100%), 1fr));
  gap: 0.5rem;
}

.choice-field {
  display: flex;
  flex-direction: column;
  font-size: 0.85rem;
}

.choice-field > label,
.choice-field :deep(.field-label) {
  min-height: 1.4rem;
  margin-bottom: 0.15rem;
}

.choice-field .input,
.sort .input {
  width: 100%;
  /* Dates and dropdowns the same height side by side. */
  height: 2.6rem;
  padding-block: 0;
  font-size: 1rem;
}

.list-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  margin-bottom: 0.75rem;
}

.sort {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  font-size: 0.85rem;
  white-space: nowrap;
}

.sort .input {
  width: 11rem;
}

.totals strong {
  color: var(--color-heading);
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
