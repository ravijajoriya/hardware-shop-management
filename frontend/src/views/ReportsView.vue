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
    <h1 class="page-title">Sales Report</h1>
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
      <div class="stat" :class="{ 'stat-owing': summary.due.total > 0 }">
        <span class="stat-label">Due <InfoTip term="Due" /></span>
        <span class="stat-value">{{ formatMoney(summary.due.total) }}</span>
        <span class="stat-note">
          {{
            summary.due.total > 0
              ? `Still unpaid on ${summary.due.bills} ${summary.due.bills === 1 ? 'bill' : 'bills'} of this period`
              : 'Every bill of this period is paid'
          }}
        </span>
        <RouterLink
          v-if="summary.due_overall.customers > 0"
          class="stat-note"
          :to="{ name: 'customers', query: { with_due: '1' } }"
        >
          {{ formatMoney(summary.due_overall.total) }} due in all, from
          {{ summary.due_overall.customers }}
          {{ summary.due_overall.customers === 1 ? 'customer' : 'customers' }}
        </RouterLink>
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
                <td class="cell-title d-day">{{ day(row.date) }}</td>
                <td class="num d-bills" data-label="Bills">{{ row.bills }}</td>
                <td class="num d-sold" data-label="Sold">{{ formatMoney(row.sales) }}</td>
                <td class="num d-returned" data-label="Returned">
                  {{ row.returns > 0 ? `−${formatMoney(row.returns)}` : '—' }}
                </td>
                <td class="num strong d-net" data-label="Net sales">
                  {{ formatMoney(row.net_sales) }}
                </td>
                <td class="num d-profit" :class="{ loss: row.profit < 0 }" data-label="Profit">
                  {{ formatMoney(row.profit) }}
                </td>
                <td class="num d-in" data-label="Money in">{{ formatMoney(row.received) }}</td>
                <td class="num d-back" data-label="Given back">
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
                  <td class="name-cell cell-title p-name">
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
                  <td class="num p-qty" data-label="Qty">
                    {{ formatQuantity(product.quantity, { short_name: product.unit }) }}
                  </td>
                  <td class="num p-net" data-label="Net sales">
                    {{ formatMoney(product.amount) }}
                  </td>
                  <td
                    class="num p-profit"
                    :class="{ loss: product.profit < 0 }"
                    data-label="Profit"
                  >
                    <span class="phone-only">Profit </span>{{ formatMoney(product.profit) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <section class="block" aria-labelledby="customers-title">
          <h2 id="customers-title">Top customers</h2>
          <p v-if="report.customers.length" class="ranked-by phone-only">
            Ranked by what they bought in this period.
          </p>
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
                <tr v-for="(customer, index) in report.customers" :key="customer.id">
                  <td class="c-rank" aria-hidden="true">{{ index + 1 }}</td>
                  <td class="name-cell cell-title c-name">
                    <RouterLink :to="{ name: 'customer-show', params: { id: customer.id } }">
                      {{ customer.name }}
                    </RouterLink>
                  </td>
                  <td class="num c-bills" data-label="Bills">
                    {{ customer.bills
                    }}<span class="phone-only">{{
                      customer.bills === 1 ? ' Bill' : ' Bills'
                    }}</span>
                  </td>
                  <td class="num c-bought" data-label="Bought">
                    {{ formatMoney(customer.amount) }}
                  </td>
                  <td class="num c-due" :class="{ loss: customer.due > 0 }">
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
.stat-owing .stat-value,
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
@media (max-width: 1000px) {
  .data-table td.name-cell {
    min-width: 0;
  }
}

/* Only on a phone, where a card names a figure in its line instead of above it. */
.phone-only,
.data-table td.c-rank {
  display: none;
}

/*
 * On a phone: the ready-made periods as a 3 × 2 grid with the two dates side by side, Net sales
 * and Profit side by side with Money received under them, and a slim card for each day, product
 * and customer. A product or customer card opens it when tapped.
 */
@media (max-width: 640px) {
  .period {
    flex-direction: column;
    align-items: stretch;
  }

  .presets {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 0.4rem;
  }

  .presets .btn {
    justify-content: center;
    padding-inline: 0.3rem;
    white-space: nowrap;
  }

  .custom {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .custom .input {
    width: 100%;
  }

  .estimate-note {
    font-size: 0.8rem;
  }

  .stats {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 0.6rem;
  }

  .stat {
    padding: 0.8rem 0.85rem;
  }

  .stat-value {
    font-size: 1.15rem;
    overflow-wrap: anywhere;
  }

  .block {
    margin-top: 1.25rem;
  }

  .data-table.stack tr {
    position: relative;
    gap: 0.3rem 0.75rem;
    padding: 0.8rem 1rem;
  }

  /* A day: the date and net sales, then Bills, Sold and Returned, then Profit, Money in and
     Given back. */
  .data-table.stack tr:has(td.d-net) {
    grid-template-columns: repeat(3, minmax(0, 1fr));
    grid-template-areas:
      'day day net'
      'bills sold returned'
      'profit in back';
  }

  .data-table.stack td.d-day {
    grid-area: day;
    align-self: end;
  }

  .data-table.stack td.d-net {
    grid-area: net;
    text-align: right;
  }

  .data-table.stack td.d-bills {
    grid-area: bills;
  }

  .data-table.stack td.d-sold {
    grid-area: sold;
  }

  .data-table.stack td.d-returned {
    grid-area: returned;
  }

  .data-table.stack td.d-profit {
    grid-area: profit;
  }

  .data-table.stack td.d-in {
    grid-area: in;
  }

  .data-table.stack td.d-back {
    grid-area: back;
  }

  .data-table.stack :is(td.d-bills, td.d-sold, td.d-returned, td.d-profit, td.d-in, td.d-back) {
    font-size: 0.9rem;
  }

  /* A product: its name and net sales, then how many sold and the profit. */
  .data-table.stack tr:has(td.p-net) {
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
      'name net'
      'qty profit';
  }

  .data-table.stack td.p-name {
    grid-area: name;
  }

  .data-table.stack td.p-net {
    grid-area: net;
    text-align: right;
  }

  .data-table.stack td.p-qty {
    grid-area: qty;
  }

  .data-table.stack td.p-profit {
    grid-area: profit;
    text-align: right;
  }

  /* A customer: their place in the ranking, the name and what they bought, then how many bills
     and what they owe now. The amounts line up on the right. */
  .ranked-by.phone-only {
    display: block;
    margin: -0.5rem 0 0.6rem;
    font-size: 0.8rem;
    opacity: 0.75;
  }

  .data-table.stack tr:has(td.c-due) {
    grid-template-columns: auto minmax(0, 1fr) auto;
    grid-template-areas:
      'rank name bought'
      'rank bills due';
    gap: 0.15rem 0.75rem;
    padding: 0.65rem 0.9rem;
  }

  .data-table.stack td.c-rank {
    display: grid;
    grid-area: rank;
    place-items: center;
    align-self: center;
    width: 1.6rem;
    height: 1.6rem;
    font-size: 0.8rem;
    font-weight: 600;
    color: var(--color-heading);
    background: var(--color-background-mute);
    border-radius: 50%;
  }

  .data-table.stack td.c-name {
    grid-area: name;
  }

  .data-table.stack td.c-bought {
    grid-area: bought;
    font-weight: 600;
    color: var(--color-heading);
    text-align: right;
  }

  .data-table.stack td.c-bills {
    grid-area: bills;
  }

  .data-table.stack td.c-due {
    grid-area: due;
    text-align: right;
  }

  .data-table.stack td.c-due .cell-label {
    display: inline-flex;
    margin-right: 0.3rem;
  }

  /* Amounts on the right have their name above, also on the right. */
  .data-table.stack :is(td.d-net, td.p-net, td.c-bought)::before {
    justify-content: flex-end;
  }

  /* These say what they are in their own line, so they go without a name above. What a
     customer bought is named by the note under Top customers. */
  .data-table.stack :is(td.p-qty, td.p-profit, td.c-bills, td.c-bought)::before {
    content: none;
  }

  .data-table.stack :is(td.p-qty, td.p-profit, td.c-bills, td.c-due) {
    font-size: 0.85rem;
  }

  .data-table.stack :is(td.p-qty, td.c-bills) {
    opacity: 0.8;
  }

  .phone-only {
    display: inline;
  }

  /* The whole product or customer card opens it (above the faded lines, whose opacity gives
     them a layer of their own); the faded "Due now" label is lifted to keep its "i" on top. */
  .data-table.stack :is(td.p-name, td.c-name) a::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
  }

  .data-table.stack td.c-due .cell-label {
    position: relative;
    z-index: 2;
  }
}
</style>
