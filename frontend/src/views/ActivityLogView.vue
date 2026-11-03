<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useActivityStore } from '@/stores/activity'
import { formatDateTime } from '@/utils/format'
import { deviceName, subjectRoute } from '@/utils/activity'
import InfoTip from '@/components/InfoTip.vue'

const store = useActivityStore()
const route = useRoute()
const router = useRouter()

const loaded = ref(false)
const loading = ref(false)
const loadError = ref('')

// The entries whose details are open.
const expanded = reactive(new Set())

// Filters and the page live in the URL, so they survive a reload and a trip to a record.
const filters = computed(() => ({
  search: route.query.search ?? '',
  user_id: route.query.user_id ?? '',
  section: route.query.section ?? '',
  from: route.query.from ?? '',
  to: route.query.to ?? '',
  page: route.query.page ?? '',
}))

const isFiltered = computed(() =>
  ['search', 'user_id', 'section', 'from', 'to'].some((key) => filters.value[key]),
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

function toggle(id) {
  if (expanded.has(id)) {
    expanded.delete(id)
  } else {
    expanded.add(id)
  }
}

function isFailedLogin(entry) {
  return entry.action.value === 'auth.login_failed'
}

// "Chrome on Mac · IP 192.168.1.20", or what is known of it.
function whereFrom(entry) {
  return [deviceName(entry.user_agent), entry.ip_address].filter(Boolean)
}

async function load() {
  loading.value = true
  loadError.value = ''

  try {
    await store.fetchPage(filters.value)
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
    if (route.name === 'activity') {
      load()
    }
  },
  { immediate: true },
)

onMounted(() => {
  // The log still shows and searches without these choices, so a failure here is not shown.
  store.fetchOptions().catch(() => {})
})
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">Activity log</h1>
  </div>

  <p class="intro">
    Everything admins did, newest first. Entries are written as things happen and cannot be changed
    or deleted.
  </p>

  <div class="filters">
    <input
      v-model="search"
      class="input search"
      type="search"
      placeholder="Search, e.g. a bill number, product or customer"
      aria-label="Search the activity log"
    />
    <select
      class="input"
      :value="filters.user_id"
      aria-label="Filter by admin"
      @change="filter({ user_id: $event.target.value })"
    >
      <option value="">All admins</option>
      <option v-for="admin in store.options.admins" :key="admin.id" :value="String(admin.id)">
        {{ admin.name }}
      </option>
    </select>
    <select
      class="input"
      :value="filters.section"
      aria-label="Filter by section"
      @change="filter({ section: $event.target.value })"
    >
      <option value="">All sections</option>
      <option v-for="section in store.options.sections" :key="section.value" :value="section.value">
        {{ section.label }}
      </option>
    </select>
    <label class="date">
      <span>From</span>
      <input
        class="input"
        type="date"
        :value="filters.from"
        :max="filters.to || undefined"
        @change="filter({ from: $event.target.value })"
      />
    </label>
    <label class="date">
      <span>To</span>
      <input
        class="input"
        type="date"
        :value="filters.to"
        :min="filters.from || undefined"
        @change="filter({ to: $event.target.value })"
      />
    </label>
    <button v-if="isFiltered" type="button" class="btn" @click="clearFilters">Clear</button>
  </div>

  <p v-if="!loaded">Loading the activity log…</p>

  <div v-else-if="loadError" class="alert-error load-error" role="alert">
    <span>{{ loadError }}</span>
    <button type="button" class="btn" @click="load">Try again</button>
  </div>

  <p v-else-if="store.entries.length === 0">
    {{ isFiltered ? 'Nothing matches these filters.' : 'Nothing has been logged yet.' }}
  </p>

  <template v-else>
    <div class="table-wrap" :class="{ busy: loading }">
      <table class="data-table log">
        <thead>
          <tr>
            <th scope="col">When</th>
            <th scope="col">Who</th>
            <th scope="col">What happened</th>
            <th scope="col" class="actions"><span class="visually-hidden">Details</span></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="entry in store.entries" :key="entry.id">
            <tr :class="{ failed: isFailedLogin(entry) }">
              <td class="when">{{ formatDateTime(entry.created_at) }}</td>
              <td class="who">{{ entry.user?.name ?? '—' }}</td>
              <td class="what-cell">
                <span class="badge" :class="{ 'badge-danger': isFailedLogin(entry) }">
                  {{ entry.action.label }}
                </span>
                <span class="description">{{ entry.description }}</span>
                <RouterLink
                  v-if="subjectRoute(entry.subject)"
                  :to="subjectRoute(entry.subject)"
                  class="open"
                >
                  Open {{ entry.subject.label }}
                </RouterLink>
              </td>
              <td class="actions">
                <button
                  type="button"
                  class="btn"
                  :aria-expanded="expanded.has(entry.id)"
                  :aria-controls="`activity-${entry.id}`"
                  @click="toggle(entry.id)"
                >
                  {{ expanded.has(entry.id) ? 'Hide' : 'Details' }}
                </button>
              </td>
            </tr>
            <tr v-if="expanded.has(entry.id)" :id="`activity-${entry.id}`" class="details-row">
              <td colspan="4">
                <table v-if="entry.changes.length" class="changes">
                  <thead>
                    <tr>
                      <th scope="col">Field</th>
                      <th scope="col">Before</th>
                      <th scope="col">After</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(change, index) in entry.changes" :key="index">
                      <th scope="row">{{ change.field }}</th>
                      <td>{{ change.old ?? '—' }}</td>
                      <td>{{ change.new ?? '—' }}</td>
                    </tr>
                  </tbody>
                </table>

                <dl v-if="entry.details.length" class="detail-list">
                  <div v-for="detail in entry.details" :key="detail.label">
                    <dt>{{ detail.label }}</dt>
                    <dd>
                      <ul v-if="Array.isArray(detail.value)">
                        <li v-for="(line, index) in detail.value" :key="index">{{ line }}</li>
                      </ul>
                      <template v-else>{{ detail.value }}</template>
                    </dd>
                  </div>
                </dl>

                <p class="from">
                  <template v-if="whereFrom(entry).length">
                    From {{ deviceName(entry.user_agent) ?? 'an unknown device' }}
                    <template v-if="entry.ip_address">
                      · <abbr title="Internet Protocol address">IP</abbr> <InfoTip term="IP" />
                      {{ entry.ip_address }}
                    </template>
                  </template>
                  <template v-else>Where it came from was not recorded.</template>
                </p>
              </td>
            </tr>
          </template>
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
  align-items: flex-end;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.filters > .input {
  flex: 1 1 10rem;
  width: auto;
}

.filters > .search {
  flex: 3 1 16rem;
}

.date {
  display: flex;
  flex: 1 1 9rem;
  flex-direction: column;
  font-size: 0.8rem;
}

.date span {
  opacity: 0.8;
}

.busy {
  opacity: 0.6;
}

.log td {
  vertical-align: top;
}

.when {
  font-size: 0.9rem;
}

/* Descriptions are sentences, so they wrap instead of widening the table. */
.data-table td.what-cell {
  min-width: 20rem;
  white-space: normal;
}

.what-cell .badge {
  margin-right: 0.5rem;
  font-size: 0.75rem;
}

.description {
  color: var(--color-heading);
}

.open {
  display: inline-block;
  margin-left: 0.5rem;
  padding: 0;
  font-size: 0.85rem;
}

tr.failed td:first-child {
  box-shadow: inset 3px 0 0 var(--color-danger);
}

/* On a phone each entry is a card: when and who, then what happened, with Details beside. */
@media (max-width: 640px) {
  .log > thead {
    display: none;
  }

  .log > tbody > tr:not(.details-row) {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 0.15rem 0.75rem;
    padding: 0.65rem 0.75rem;
  }

  .log > tbody > tr > td {
    padding: 0;
  }

  .log td.who {
    grid-row: 2;
    font-size: 0.85rem;
    opacity: 0.8;
  }

  .data-table td.what-cell {
    grid-column: 1 / -1;
    min-width: 0;
    margin-top: 0.25rem;
  }

  .log td.actions {
    grid-column: 2;
    grid-row: 1 / 3;
  }

  tr.failed td:first-child {
    box-shadow: none;
  }

  tr.failed {
    box-shadow: inset 3px 0 0 var(--color-danger);
  }

  .details-row > td {
    display: block;
    padding: 0.5rem 0.75rem 0.75rem;
  }
}

.details-row > td {
  padding-top: 0.25rem;
  white-space: normal;
  background: var(--color-background-soft);
}

.changes {
  width: auto;
  max-width: 100%;
  margin-bottom: 0.75rem;
  border-collapse: collapse;
  font-size: 0.9rem;
}

.changes th,
.changes td {
  padding: 0.3rem 1rem 0.3rem 0;
  text-align: left;
  vertical-align: top;
  white-space: normal;
  border-bottom: 1px solid var(--color-border);
}

.changes thead th {
  font-size: 0.8rem;
  opacity: 0.8;
}

.changes tbody th {
  font-weight: 500;
  color: var(--color-heading);
}

.detail-list {
  display: grid;
  gap: 0.4rem;
  margin: 0 0 0.75rem;
  font-size: 0.9rem;
}

.detail-list dt {
  font-size: 0.8rem;
  opacity: 0.8;
}

.detail-list dd {
  margin: 0;
  color: var(--color-heading);
}

.detail-list ul {
  margin: 0;
  padding-left: 1.1rem;
}

.from {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.85rem;
  opacity: 0.85;
}

.from abbr {
  text-decoration: none;
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
