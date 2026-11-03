<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useCustomerStore } from '@/stores/customers'
import { formatMoney } from '@/utils/format'
import { formatMobile } from '@/utils/billing'
import InfoTip from '@/components/InfoTip.vue'

const store = useCustomerStore()
const route = useRoute()
const router = useRouter()

const loaded = ref(false)
const loading = ref(false)
const loadError = ref('')

// Filters and the page live in the URL, so they survive a reload and a trip to a customer and back.
const filters = computed(() => ({
  search: route.query.search ?? '',
  with_due: route.query.with_due === '1' ? '1' : '',
  page: route.query.page ?? '',
}))

const isFiltered = computed(() => Boolean(filters.value.search || filters.value.with_due))

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
    if (route.name === 'customers') {
      load()
    }
  },
  { immediate: true },
)
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">Customers</h1>
    <RouterLink class="btn btn-primary" :to="{ name: 'bill-create' }">New bill</RouterLink>
  </div>

  <p class="intro">
    Customers are saved from their bills, by mobile number. Open one to take a payment towards what
    they owe.
  </p>

  <div class="filters">
    <input
      v-model="search"
      class="input search"
      type="search"
      placeholder="Search by name or mobile number"
      aria-label="Search customers"
    />
    <label class="checkbox">
      <input
        type="checkbox"
        :checked="filters.with_due === '1'"
        @change="filter({ with_due: $event.target.checked ? '1' : '' })"
      />
      Only customers with dues
      <InfoTip term="Due" />
    </label>
    <button v-if="isFiltered" type="button" class="btn" @click="clearFilters">Clear</button>
  </div>

  <p v-if="!loaded">Loading customers…</p>

  <div v-else-if="loadError" class="alert-error load-error" role="alert">
    <span>{{ loadError }}</span>
    <button type="button" class="btn" @click="load">Try again</button>
  </div>

  <p v-else-if="store.customers.length === 0">
    {{
      isFiltered
        ? 'No customers match these filters.'
        : 'No customers yet. They are added when you make a bill.'
    }}
  </p>

  <template v-else>
    <div class="table-wrap" :class="{ busy: loading }">
      <table class="data-table stack">
        <thead>
          <tr>
            <th scope="col">Customer</th>
            <th scope="col">Address</th>
            <th scope="col">Mobile</th>
            <th scope="col" class="num">Bills</th>
            <th scope="col" class="num">Total billed</th>
            <th scope="col" class="num">Due <InfoTip term="Due" /></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="customer in store.customers" :key="customer.id">
            <td class="name-cell cell-title">
              <RouterLink :to="{ name: 'customer-show', params: { id: customer.id } }">
                {{ customer.name }}
              </RouterLink>
            </td>
            <td class="address-cell" data-label="Address">{{ customer.address ?? '—' }}</td>
            <td class="cell-mobile" data-label="Mobile">
              {{ formatMobile(customer.mobile)
              }}<span v-if="customer.address" class="phone-only">{{
                ` · ${customer.address}`
              }}</span>
            </td>
            <td class="num cell-bills" data-label="Bills">{{ customer.bills_count }}</td>
            <td class="num cell-billed" data-label="Total billed">
              <span class="phone-only">
                {{ customer.bills_count }} {{ customer.bills_count === 1 ? 'Bill' : 'Bills' }} ·
              </span>
              {{ formatMoney(customer.total_billed)
              }}<span class="phone-only">{{ ' Billed' }}</span>
            </td>
            <td class="num cell-due" :class="{ owing: customer.total_due > 0 }">
              <span class="cell-label">Due <InfoTip term="Due" /></span>
              {{ formatMoney(customer.total_due) }}
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
.intro {
  margin: -0.75rem 0 1rem;
  font-size: 0.9rem;
  opacity: 0.85;
}

.filters {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 1rem;
  margin-bottom: 1rem;
}

.filters .search {
  flex: 2 1 16rem;
  width: auto;
}

.busy {
  opacity: 0.6;
}

.data-table td.name-cell {
  min-width: 12rem;
  white-space: normal;
}

/* Addresses are up to 50 characters, so they wrap instead of widening the table. */
.data-table td.address-cell {
  min-width: 12rem;
  white-space: normal;
}

.num {
  text-align: right;
}

/* Only on a phone, where the card puts the address after the mobile and the bill count
   before the total. */
.phone-only {
  display: none;
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

/* As cards on tablets and phones (see .stack), the cells share the width. */
@media (max-width: 1000px) {
  .data-table td.name-cell,
  .data-table td.address-cell {
    min-width: 0;
  }
}

/*
 * On a phone each customer is a slim card: the name, then mobile · address, then how many bills
 * and what they came to, with what they owe on the right. Tapping the card opens the customer.
 */
@media (max-width: 640px) {
  .data-table.stack tr {
    position: relative;
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
      'name due'
      'mobile due'
      'billed billed';
    gap: 0.25rem 1rem;
    padding: 0.8rem 1rem;
  }

  .data-table.stack td.name-cell {
    grid-area: name;
  }

  .data-table.stack td.cell-mobile {
    grid-area: mobile;
  }

  .data-table.stack td.cell-billed {
    grid-area: billed;
  }

  .data-table.stack td.cell-due {
    grid-area: due;
    align-self: center;
    text-align: right;
  }

  .data-table.stack td.cell-due :deep(.cell-label) {
    justify-content: flex-end;
  }

  .data-table.stack td.address-cell,
  .data-table.stack td.cell-bills {
    display: none;
  }

  .data-table.stack td.cell-mobile::before,
  .data-table.stack td.cell-billed::before {
    content: none;
  }

  .data-table.stack td.cell-mobile,
  .data-table.stack td.cell-billed {
    font-size: 0.85rem;
    opacity: 0.85;
  }

  .phone-only {
    display: inline;
  }

  /* The whole card opens the customer; the "i" beside Due still opens its explanation. */
  /* Above the faded lines too, whose opacity puts them in a layer of their own. */
  .data-table.stack td.name-cell a::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
  }

  /* The faded "Due" label is a layer of its own, so it is lifted whole to keep its "i" on top. */
  .data-table.stack td.cell-due .cell-label {
    position: relative;
    z-index: 2;
  }
}
</style>
