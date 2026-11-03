<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useBillStore } from '@/stores/bills'
import { formatDate, formatDateTime, formatMoney } from '@/utils/format'
import {
  BILL_CHANGES,
  BILL_SORTS,
  BILL_STATUSES,
  OVERDUE_DAYS,
  PAYMENT_METHODS,
  formatMobile,
  methodLabel,
  statusClass,
} from '@/utils/billing'
import { PERIODS, periodOf, periodRange } from '@/utils/reports'
import { useMediaQuery } from '@/composables/useMediaQuery'
import { useScrollLock } from '@/composables/useScrollLock'
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

// On a phone the filters wait in a panel that slides up from the bottom, and the ones in use
// show as tags above the list, each with its own ✕.
const isPhone = useMediaQuery('(max-width: 640px)')
const filtersOpen = ref(false)
const sheetOpen = computed(() => filtersOpen.value && isPhone.value)

const filtersButton = ref(null)
const sheetClose = ref(null)

useScrollLock(sheetOpen)

watch(sheetOpen, async (open, wasOpen) => {
  await nextTick()

  if (open) {
    sheetClose.value?.focus()
  } else if (wasOpen) {
    filtersButton.value?.focus()
  }
})

function dayLabel(day) {
  return formatDate(`${day}T00:00:00`)
}

function dateLabel(from, to) {
  const period = PERIODS.find((option) => option.value === dateChoice.value)

  if (period) {
    return period.label
  }

  if (from && to) {
    return from === to ? dayLabel(from) : `${dayLabel(from)} – ${dayLabel(to)}`
  }

  return from ? `From ${dayLabel(from)}` : `Until ${dayLabel(to)}`
}

// The filters in use, apart from the search, each with what removing it changes.
const activeFilters = computed(() => {
  const { from, to, status, method, overdue, changes, product } = filters.value
  const labelOf = (list, value) => list.find((option) => option.value === value)?.label ?? value

  return [
    (from || to) && { key: 'date', label: dateLabel(from, to), clear: { from: '', to: '' } },
    status && { key: 'status', label: labelOf(BILL_STATUSES, status), clear: { status: '' } },
    method && { key: 'method', label: `Paid by ${methodLabel(method)}`, clear: { method: '' } },
    overdue && { key: 'overdue', label: `Due over ${overdue} days`, clear: { overdue: '' } },
    changes && { key: 'changes', label: labelOf(BILL_CHANGES, changes), clear: { changes: '' } },
    product && { key: 'product', label: `Product: ${product}`, clear: { product: '' } },
  ].filter(Boolean)
})

function removeFilter(chip) {
  if (chip.key === 'date') {
    choosingDates.value = false
  }

  filter(chip.clear)
}

// Clears what the panel sets, keeping the search and the order.
function clearPanel() {
  choosingDates.value = false
  filter({ product: '', from: '', to: '', status: '', method: '', overdue: '', changes: '' })
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
        class="input product-input"
        type="search"
        placeholder="Product name or item code"
        aria-label="Bills with this product"
      />
      <button
        v-if="isFiltered || filters.sort"
        type="button"
        class="btn clear-all"
        @click="clearFilters"
      >
        Clear filters
      </button>
    </div>

    <!-- On a phone: a Filters button opening the panel, the order, and the filters in use. -->
    <div class="phone-bar" :inert="sheetOpen || undefined">
      <button
        ref="filtersButton"
        type="button"
        class="btn filters-button"
        aria-controls="bill-filters"
        :aria-expanded="sheetOpen"
        @click="filtersOpen = true"
      >
        Filters
        <span v-if="activeFilters.length" class="filter-count">{{ activeFilters.length }}</span>
      </button>
      <div class="sort">
        <label for="bills-sort-phone">Sort</label>
        <select
          id="bills-sort-phone"
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
    <ul
      v-if="activeFilters.length"
      class="chips"
      aria-label="Filters in use"
      :inert="sheetOpen || undefined"
    >
      <li v-for="chip in activeFilters" :key="chip.key">
        <button
          type="button"
          class="chip"
          :aria-label="`Remove filter: ${chip.label}`"
          @click="removeFilter(chip)"
        >
          {{ chip.label }} <span aria-hidden="true">✕</span>
        </button>
      </li>
    </ul>

    <div
      id="bill-filters"
      class="choices"
      :class="{ open: sheetOpen }"
      :role="sheetOpen ? 'dialog' : undefined"
      :aria-modal="sheetOpen ? 'true' : undefined"
      :aria-labelledby="sheetOpen ? 'bill-filters-title' : undefined"
      @keydown.esc="filtersOpen = false"
    >
      <div class="sheet-head">
        <h2 id="bill-filters-title">Filters</h2>
        <button
          ref="sheetClose"
          type="button"
          class="btn sheet-close"
          aria-label="Close filters"
          @click="filtersOpen = false"
        >
          <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18" /></svg>
        </button>
      </div>
      <div class="choice-field sheet-product">
        <label for="bills-product-phone">Product</label>
        <input
          id="bills-product-phone"
          v-model="product"
          class="input"
          type="search"
          placeholder="Product name or item code"
        />
      </div>
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
      <div class="sheet-foot">
        <button type="button" class="btn" @click="clearPanel">Clear all</button>
        <button type="button" class="btn btn-primary" @click="filtersOpen = false">
          Show {{ store.meta.total }} {{ store.meta.total === 1 ? 'bill' : 'bills' }}
        </button>
      </div>
    </div>
    <div v-if="sheetOpen" class="sheet-backdrop" aria-hidden="true" @click="filtersOpen = false" />
  </div>

  <p v-if="!loaded">Loading bills…</p>

  <div v-else-if="loadError" class="alert-error load-error" role="alert">
    <span>{{ loadError }}</span>
    <button type="button" class="btn" @click="load">Try again</button>
  </div>

  <p v-else-if="store.bills.length === 0">
    {{ isFiltered ? 'No bills match these filters.' : 'No bills yet. Make the first one.' }}
  </p>

  <div v-else class="bill-list" :inert="sheetOpen || undefined">
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
      <table class="data-table stack">
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
            <td class="cell-title">
              <RouterLink class="mono" :to="{ name: 'bill-show', params: { id: bill.id } }">
                {{ bill.bill_number }}
              </RouterLink>
            </td>
            <td class="cell-date" data-label="Date">{{ formatDateTime(bill.created_at) }}</td>
            <td class="customer-cell" data-label="Customer">
              <div class="name">{{ bill.customer.name }}</div>
              <div class="details">{{ formatMobile(bill.customer.mobile) }}</div>
            </td>
            <td class="num cell-items" data-label="Items">
              {{ bill.items_count
              }}<span class="items-word">{{ bill.items_count === 1 ? ' item' : ' items' }}</span>
            </td>
            <td class="num cell-total" data-label="Total">{{ formatMoney(bill.total) }}</td>
            <td class="num cell-paid" data-label="Paid">{{ formatMoney(bill.paid_amount) }}</td>
            <td class="num cell-due" :class="{ owing: bill.due_amount > 0 }">
              <span class="cell-label">Due <InfoTip term="Due" /></span>
              {{ formatMoney(bill.due_amount) }}
            </td>
            <td class="cell-status" data-label="Status">
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
  </div>
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

/* Only on a phone: the Filters bar, the tags, the panel's own parts and the item count's word. */
.phone-bar,
.chips,
.sheet-head,
.choice-field.sheet-product,
.sheet-foot,
.items-word {
  display: none;
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

@media (max-width: 1000px) {
  .data-table td.customer-cell {
    min-width: 0;
  }
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

/*
 * On a phone: the search, then a Filters button (with how many are on) and the order, then the
 * filters in use as tags. The filters themselves wait in a panel that slides up from the bottom.
 * Each bill is a slim card that opens the bill when tapped.
 */
@media (max-width: 640px) {
  .typed > .product-input,
  .typed > .clear-all,
  .list-head .sort {
    display: none;
  }

  .phone-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
  }

  .filters-button {
    align-items: center;
    gap: 0.5rem;
  }

  .filter-count {
    display: inline-grid;
    place-items: center;
    min-width: 1.35rem;
    height: 1.35rem;
    padding: 0 0.3rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: #fff;
    background: hsla(160, 100%, 37%, 1);
    border-radius: 999px;
  }

  .phone-bar .sort .input {
    width: 9.5rem;
  }

  .chips {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin: 0;
    padding: 0;
    list-style: none;
  }

  .chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    min-height: 2rem;
    padding: 0.25rem 0.7rem;
    font: inherit;
    font-size: 0.85rem;
    color: var(--color-heading);
    background: var(--color-background-mute);
    border: 1px solid var(--color-border-hover);
    border-radius: 999px;
    cursor: pointer;
  }

  .chip span {
    opacity: 0.7;
  }

  /* The panel. */
  .choices {
    position: fixed;
    inset: auto 0 0;
    z-index: 50;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.6rem 0.75rem;
    max-height: 85vh;
    padding: 0 1rem 1rem;
    overflow-y: auto;
    background: var(--color-background-soft);
    border-top: 1px solid var(--color-border);
    border-radius: 14px 14px 0 0;
    box-shadow: 0 -8px 24px rgba(0, 0, 0, 0.35);
    transform: translateY(100%);
    visibility: hidden;
    transition:
      transform 0.25s ease,
      visibility 0s linear 0.25s;
  }

  .choices.open {
    transform: none;
    visibility: visible;
    transition: transform 0.25s ease;
  }

  .sheet-head {
    position: sticky;
    top: 0;
    z-index: 1;
    display: flex;
    grid-column: 1 / -1;
    align-items: center;
    justify-content: space-between;
    margin: 0 -1rem;
    padding: 0.6rem 0.75rem 0.6rem 1rem;
    background: var(--color-background-soft);
    border-bottom: 1px solid var(--color-border);
  }

  .sheet-head h2 {
    font-size: 1.1rem;
    font-weight: 600;
    color: var(--color-heading);
  }

  .sheet-close {
    justify-content: center;
    align-items: center;
    width: 2.5rem;
    height: 2.5rem;
    padding: 0;
  }

  .sheet-close svg {
    width: 1.3rem;
    height: 1.3rem;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
  }

  .choice-field.sheet-product {
    display: flex;
    grid-column: 1 / -1;
  }

  .sheet-foot {
    position: sticky;
    bottom: -1rem;
    display: flex;
    grid-column: 1 / -1;
    gap: 0.5rem;
    margin: 0.25rem -1rem -1rem;
    padding: 0.75rem 1rem max(0.75rem, env(safe-area-inset-bottom));
    background: var(--color-background-soft);
    border-top: 1px solid var(--color-border);
  }

  .sheet-foot .btn {
    flex: 1;
    justify-content: center;
  }

  .sheet-backdrop {
    position: fixed;
    inset: 0;
    z-index: 45;
    background: rgba(0, 0, 0, 0.5);
    animation: fade-in 0.25s ease;
  }

  /* A slim card for each bill: number and status, the customer, when and how many items,
     then Total, Paid and Due side by side. */
  .data-table.stack tr {
    position: relative;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    grid-template-areas:
      'number number status'
      'customer customer customer'
      'date date items'
      'total paid due';
    gap: 0.3rem 0.75rem;
    padding: 0.8rem 1rem;
  }

  .data-table.stack td.cell-title {
    grid-area: number;
    align-self: center;
  }

  .data-table.stack td.cell-status {
    grid-area: status;
    justify-self: end;
    align-self: center;
  }

  .data-table.stack td.customer-cell {
    grid-area: customer;
  }

  .data-table.stack td.cell-date {
    grid-area: date;
  }

  .data-table.stack td.cell-items {
    grid-area: items;
    text-align: right;
  }

  .data-table.stack td.cell-total {
    grid-area: total;
    margin-top: 0.3rem;
  }

  .data-table.stack td.cell-paid {
    grid-area: paid;
    margin-top: 0.3rem;
  }

  .data-table.stack td.cell-due {
    grid-area: due;
    margin-top: 0.3rem;
  }

  /* The number, status, customer and date speak for themselves, so they go without a name. */
  .data-table.stack td.cell-status::before,
  .data-table.stack td.customer-cell::before,
  .data-table.stack td.cell-date::before,
  .data-table.stack td.cell-items::before {
    content: none;
  }

  .data-table.stack td.cell-date,
  .data-table.stack td.cell-items {
    font-size: 0.85rem;
    opacity: 0.8;
  }

  .customer-cell .name,
  .customer-cell .details {
    display: inline;
  }

  .customer-cell .details::before {
    content: ' · ';
  }

  .items-word {
    display: inline;
  }

  /* The whole card opens the bill; the "i" beside Due still opens its explanation. */
  .data-table.stack td.cell-title a::after {
    content: '';
    position: absolute;
    inset: 0;
  }

  .data-table.stack td.cell-due :deep(.info) {
    position: relative;
    z-index: 1;
  }
}

@keyframes fade-in {
  from {
    opacity: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .choices,
  .choices.open {
    transition: none;
  }

  .sheet-backdrop {
    animation: none;
  }
}
</style>
