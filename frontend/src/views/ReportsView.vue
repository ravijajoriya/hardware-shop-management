<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useReportStore } from '@/stores/reports'
import { formatDate, formatMoney, formatQuantity } from '@/utils/format'
import { PERIODS, moneyKept, periodOf, periodRange } from '@/utils/reports'
import InfoTip from '@/components/InfoTip.vue'

const store = useReportStore()
const route = useRoute()
const router = useRouter()

const loading = ref(false)
const error = ref('')

// The period lives in the URL, so a report can be reloaded or shared; this month by default.
const range = computed(() => {
  const fallback = periodRange('this_month')

  return {
    from: typeof route.query.from === 'string' ? route.query.from : fallback.from,
    to: typeof route.query.to === 'string' ? route.query.to : fallback.to,
  }
})

const period = computed(() => periodOf(range.value.from, range.value.to))
const report = computed(() => store.sales)
const summary = computed(() => report.value?.summary)
const kept = computed(
  () => summary.value && moneyKept(summary.value.received, summary.value.refunded),
)

function choose(value) {
  router.replace({ query: periodRange(value) })
}

function setDay(field, value) {
  if (value) {
    router.replace({ query: { ...range.value, [field]: value } })
  }
}

// "2 Nov 2026", from a day by the shop's calendar.
function day(date) {
  return formatDate(`${date}T00:00:00`)
}

function margin(value) {
  return value === null ? '—' : `${value}%`
}

async function load() {
  loading.value = true
  error.value = ''

  try {
    await store.fetchSales(range.value.from, range.value.to)
  } catch (e) {
    error.value = Object.values(e.errors ?? {})[0]?.[0] ?? e.message
  } finally {
    loading.value = false
  }
}

watch(
  () => route.query,
  () => {
    // The query also changes while leaving for another page; only reload while still here.
    if (route.name === 'reports') {
      load()
    }
  },
  { immediate: true },
)
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">Sales report</h1>
  </div>

  <div class="period">
    <div class="presets" role="group" aria-label="Period">
      <button
        v-for="option in PERIODS"
        :key="option.value"
        type="button"
        class="btn"
        :class="{ chosen: period === option.value }"
        :aria-pressed="period === option.value"
        @click="choose(option.value)"
      >
        {{ option.label }}
      </button>
    </div>
    <div class="custom">
      <label>
        <span>From</span>
        <input
          class="input"
          type="date"
          :value="range.from"
          :max="range.to"
          @change="setDay('from', $event.target.value)"
        />
      </label>
      <label>
        <span>To</span>
        <input
          class="input"
          type="date"
          :value="range.to"
          :min="range.from"
          @change="setDay('to', $event.target.value)"
        />
      </label>
    </div>
  </div>

  <div v-if="error" class="alert-error load-error" role="alert">
    <span>{{ error }}</span>
    <button type="button" class="btn" @click="load">Try again</button>
  </div>

  <p v-else-if="!report">Loading the report…</p>

  <div v-else :class="{ busy: loading }">
    <p class="showing">
      {{ day(report.from) }}{{ report.from === report.to ? '' : ` to ${day(report.to)}` }}
    </p>

    <p v-if="summary.cost_estimated" class="estimate-note">
      Some bills in this period were made before costs were kept with each sale, so their cost — and
      the profit — uses today’s purchase prices instead. Treat it as a close estimate.
    </p>

    <section class="stats" aria-label="Totals">
      <div class="stat">
        <span class="stat-label">Net sales <InfoTip term="Net sales" /></span>
        <span class="stat-value">{{ formatMoney(summary.net_sales) }}</span>
        <span class="stat-note">
          {{ summary.bills }} {{ summary.bills === 1 ? 'bill' : 'bills' }},
          {{ formatMoney(summary.sales) }} sold
        </span>
        <span v-if="summary.returns > 0" class="stat-note">
          {{ formatMoney(summary.returns) }} returned
        </span>
      </div>
      <div class="stat" :class="{ 'stat-loss': summary.profit < 0 }">
        <span class="stat-label">Profit <InfoTip term="Profit" /></span>
        <span class="stat-value">{{ formatMoney(summary.profit) }}</span>
        <span class="stat-note">
          Margin <InfoTip term="Margin" /> {{ margin(summary.margin) }} · cost
          {{ formatMoney(summary.cost) }}
        </span>
      </div>
      <div class="stat">
        <span class="stat-label">Money received</span>
        <span class="stat-value">{{ formatMoney(summary.net_received) }}</span>
        <span class="stat-note">
          Cash {{ formatMoney(kept.cash) }} · Online {{ formatMoney(kept.online) }}
        </span>
        <span v-if="summary.refunded.total > 0" class="stat-note">
          {{ formatMoney(summary.received.total) }} in,
          {{ formatMoney(summary.refunded.total) }} given back
        </span>
      </div>
    </section>

    <p v-if="report.days.length === 0" class="empty">Nothing was sold or paid in this period.</p>

    <template v-else>
      <section class="block" aria-labelledby="days-title">
        <h2 id="days-title">Day by day</h2>
        <div class="table-wrap">
          <table class="data-table stack">
            <thead>
              <tr>
                <th scope="col">Day</th>
                <th scope="col" class="num">Bills</th>
                <th scope="col" class="num">Sold</th>
                <th scope="col" class="num">Returned</th>
                <th scope="col" class="num">Net sales</th>
                <th scope="col" class="num">Profit</th>
                <th scope="col" class="num">Money in</th>
                <th scope="col" class="num">Given back</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in report.days" :key="row.date">
                <td class="cell-title">{{ day(row.date) }}</td>
                <td class="num" data-label="Bills">{{ row.bills }}</td>
                <td class="num" data-label="Sold">{{ formatMoney(row.sales) }}</td>
                <td class="num" data-label="Returned">
                  {{ row.returns > 0 ? `−${formatMoney(row.returns)}` : '—' }}
                </td>
                <td class="num strong" data-label="Net sales">{{ formatMoney(row.net_sales) }}</td>
                <td class="num" :class="{ loss: row.profit < 0 }" data-label="Profit">
                  {{ formatMoney(row.profit) }}
                </td>
                <td class="num" data-label="Money in">{{ formatMoney(row.received) }}</td>
                <td class="num" data-label="Given back">
                  {{ row.refunded > 0 ? formatMoney(row.refunded) : '—' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div class="tops">
        <section class="block" aria-labelledby="products-title">
          <h2 id="products-title">Top products</h2>
          <p v-if="report.products.length === 0" class="empty">No products sold.</p>
          <div v-else class="table-wrap">
            <table class="data-table stack">
              <thead>
                <tr>
                  <th scope="col">Product</th>
                  <th scope="col" class="num">Qty</th>
                  <th scope="col" class="num">Net sales</th>
                  <th scope="col" class="num">Profit</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="product in report.products"
                  :key="`${product.name}|${product.variant_name}`"
                >
                  <td class="name-cell cell-title">
                    <RouterLink
                      v-if="product.product_id"
                      :to="{ name: 'product-show', params: { id: product.product_id } }"
                    >
                      {{ product.name }}
                    </RouterLink>
                    <template v-else>{{ product.name }}</template>
                    <div v-if="product.variant_name" class="details">
                      {{ product.variant_name }}
                    </div>
                  </td>
                  <td class="num" data-label="Qty">
                    {{ formatQuantity(product.quantity, { short_name: product.unit }) }}
                  </td>
                  <td class="num" data-label="Net sales">{{ formatMoney(product.amount) }}</td>
                  <td class="num" :class="{ loss: product.profit < 0 }" data-label="Profit">
                    {{ formatMoney(product.profit) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="block" aria-labelledby="customers-title">
          <h2 id="customers-title">Top customers</h2>
          <p v-if="report.customers.length === 0" class="empty">No customers in this period.</p>
          <div v-else class="table-wrap">
            <table class="data-table stack">
              <thead>
                <tr>
                  <th scope="col">Customer</th>
                  <th scope="col" class="num">Bills</th>
                  <th scope="col" class="num">Bought</th>
                  <th scope="col" class="num">Due now <InfoTip term="Due" /></th>
                </tr>
              </thead>
              <tbody>
                <tr v-for="customer in report.customers" :key="customer.id">
                  <td class="name-cell cell-title">
                    <RouterLink :to="{ name: 'customer-show', params: { id: customer.id } }">
                      {{ customer.name }}
                    </RouterLink>
                  </td>
                  <td class="num" data-label="Bills">{{ customer.bills }}</td>
                  <td class="num" data-label="Bought">{{ formatMoney(customer.amount) }}</td>
                  <td class="num" :class="{ loss: customer.due > 0 }">
                    <span class="cell-label">Due now <InfoTip term="Due" /></span>
                    {{ formatMoney(customer.due) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>
      </div>
    </template>
  </div>
</template>

<style scoped>
.period {
  display: flex;
  flex-wrap: wrap;
  align-items: flex-end;
  justify-content: space-between;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.presets {
  display: flex;
  flex-wrap: wrap;
  gap: 0.4rem;
}

.presets .chosen {
  font-weight: 600;
  color: var(--color-heading);
  border-color: hsla(160, 100%, 37%, 1);
  box-shadow: inset 0 0 0 1px hsla(160, 100%, 37%, 1);
}

.custom {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.custom label {
  display: flex;
  flex-direction: column;
  font-size: 0.8rem;
}

.custom span {
  opacity: 0.8;
}

.custom .input {
  width: 10.5rem;
}

.busy {
  opacity: 0.6;
}

.showing {
  margin-bottom: 0.75rem;
  font-weight: 500;
  color: var(--color-heading);
}

.estimate-note {
  margin-bottom: 1rem;
  padding: 0.6rem 0.75rem;
  font-size: 0.9rem;
  border: 1px dashed var(--color-border-hover);
  border-radius: 6px;
}

.stat-loss .stat-value,
.loss {
  color: var(--color-danger);
}

.empty {
  opacity: 0.85;
}

.block {
  margin-top: 1.5rem;
}

.block h2 {
  margin-bottom: 0.75rem;
  font-size: 1.15rem;
  font-weight: 600;
  color: var(--color-heading);
}

.tops {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(22rem, 100%), 1fr));
  gap: 0 1.5rem;
}

.data-table td.name-cell {
  min-width: 10rem;
  white-space: normal;
}

.details {
  font-size: 0.85rem;
  opacity: 0.8;
}

.num {
  text-align: right;
}

.strong {
  font-weight: 600;
  color: var(--color-heading);
}

/* As cards on tablets and phones (see .stack), the cells share the width. */
@media (max-width: 820px) {
  .data-table td.name-cell {
    min-width: 0;
  }
}
</style>
