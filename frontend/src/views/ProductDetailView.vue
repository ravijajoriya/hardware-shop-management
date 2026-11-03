<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useProductStore } from '@/stores/products'
import { useStockStore } from '@/stores/stock'
import { formatDate, formatMoney, formatQuantity } from '@/utils/format'
import { locationLabel } from '@/utils/location'
import { stockChange } from '@/utils/stock'
import InfoTip from '@/components/InfoTip.vue'
import ProductGallery from '@/components/ProductGallery.vue'
import StockCorrectionDialog from '@/components/StockCorrectionDialog.vue'

const props = defineProps({
  id: { type: String, required: true },
})

const store = useProductStore()
const stock = useStockStore()
const router = useRouter()

const product = ref(null)
const loading = ref(true)
const error = ref('')
const notFound = ref(false)

// Deliveries from Stock in and stock corrections, newest first.
const history = ref([])
const historyLoaded = ref(false)
const historyError = ref('')

// The size whose stock is being corrected, and the last correction made on this page.
const correcting = ref(null)
const corrected = ref(null)

const hasSizes = computed(() => (product.value?.variants.length ?? 0) > 1)

// One price, or the lowest to highest when the sizes cost different amounts.
const price = computed(() => {
  const prices = product.value.variants.map((variant) => variant.selling_price)
  const lowest = Math.min(...prices)
  const highest = Math.max(...prices)

  return lowest === highest
    ? formatMoney(lowest)
    : `${formatMoney(lowest)} – ${formatMoney(highest)}`
})

async function load() {
  loading.value = true
  error.value = ''

  try {
    product.value = await store.fetchOne(props.id)
    loadHistory()
  } catch (e) {
    notFound.value = e.status === 404
    error.value = notFound.value ? 'This product no longer exists.' : e.message
  } finally {
    loading.value = false
  }
}

async function loadHistory() {
  historyError.value = ''

  try {
    history.value = await stock.history(props.id)
  } catch (e) {
    historyError.value = e.message
  } finally {
    historyLoaded.value = true
  }
}

// The page shows the new stock straight away, without reloading everything.
async function onCorrected(correction) {
  correcting.value = null
  corrected.value = correction

  try {
    product.value = await store.fetchOne(props.id)
  } catch {
    // The old figures stay; the correction is saved, and a reload shows it.
  }

  loadHistory()
}

function deliveryText(row) {
  const from = row.supplier_name ? ` from ${row.supplier_name}` : ''
  const bill = row.supplier_bill_number ? ` (bill ${row.supplier_bill_number})` : ''

  return `${from}${bill} at ${formatMoney(row.purchase_price)} each. Average cost became ${formatMoney(row.cost_after)}.`
}

function correctionText(row) {
  const unit = { short_name: row.unit }
  const note = row.note ? ` “${row.note}”` : ''
  const by = row.by ? ` By ${row.by}.` : ''

  return `Stock corrected (${row.reason.label.toLowerCase()}) from ${formatQuantity(row.stock_before, unit)} to ${formatQuantity(row.stock_after, unit)}.${note}${by}`
}

// Back to wherever the product was opened from, such as the dashboard search.
function goBack() {
  if (window.history.state?.back) {
    router.back()
  } else {
    router.push({ name: 'dashboard' })
  }
}

// Opening another product reuses this page, so load whenever the id changes.
watch(
  () => props.id,
  () => {
    history.value = []
    historyLoaded.value = false
    corrected.value = null
    load()
  },
  { immediate: true },
)
</script>

<template>
  <div class="page-header">
    <button type="button" class="btn" @click="goBack">← Back</button>
    <div v-if="product" class="header-actions">
      <RouterLink class="btn" :to="{ name: 'stock-entry-create' }">Add stock</RouterLink>
      <RouterLink
        class="btn btn-primary"
        :to="{ name: 'product-edit', params: { id: product.id } }"
      >
        Edit product
      </RouterLink>
    </div>
  </div>

  <p v-if="loading">Loading…</p>

  <div v-else-if="error" class="alert-error load-error" role="alert">
    <span>{{ error }}</span>
    <RouterLink v-if="notFound" class="btn" :to="{ name: 'products' }">See all products</RouterLink>
    <button v-else type="button" class="btn" @click="load">Try again</button>
  </div>

  <article v-else-if="product" class="detail">
    <div class="top">
      <ProductGallery :images="product.images" :name="product.name" />

      <div class="summary">
        <h1 class="page-title">{{ product.name }}</h1>
        <p class="subtitle">
          {{ [product.brand?.name, product.category.name].filter(Boolean).join(' · ') }}
          <span class="badge" :class="{ 'badge-active': product.is_active }">
            {{ product.is_active ? 'Active' : 'Inactive' }}
          </span>
        </p>

        <div class="price">
          <span class="amount">{{ price }}</span>
          <span v-if="hasSizes" class="note"
            >for {{ product.variants.length }} sizes, see below</span
          >
          <span v-else class="note">per {{ product.unit.short_name }}</span>
          <span v-if="!hasSizes && product.variants[0].mrp !== null" class="mrp">
            MRP <InfoTip term="MRP" /> {{ formatMoney(product.variants[0].mrp) }}
          </span>
        </div>

        <p class="stock">
          <strong>{{ formatQuantity(product.stock_quantity, product.unit) }}</strong> in stock
        </p>

        <div class="place">
          <span class="place-label">Kept at <InfoTip term="Location code" /></span>
          <strong class="code">{{ product.location.code }}</strong>
          <span class="words">{{ locationLabel(product.location) }}</span>
        </div>

        <dl class="facts">
          <div>
            <dt>Category</dt>
            <dd>{{ product.category.name }}</dd>
          </div>
          <div>
            <dt>Brand</dt>
            <dd>{{ product.brand?.name ?? 'No brand' }}</dd>
          </div>
          <div>
            <dt>Unit</dt>
            <dd>{{ product.unit.name }} ({{ product.unit.short_name }})</dd>
          </div>
          <div>
            <dt>HSN code <InfoTip term="HSN" /></dt>
            <dd>{{ product.hsn_code ?? 'Not set' }}</dd>
          </div>
          <div>
            <dt>GST rate <InfoTip term="GST" /></dt>
            <dd>{{ product.gst_rate === null ? 'Not set' : `${product.gst_rate}%` }}</dd>
          </div>
        </dl>
      </div>
    </div>

    <p v-if="product.description" class="description">{{ product.description }}</p>

    <section class="sizes" aria-labelledby="sizes-title">
      <h2 id="sizes-title">{{ hasSizes ? 'Sizes, prices and stock' : 'Price and stock' }}</h2>

      <p v-if="corrected" class="alert-success corrected" role="status">
        Stock of {{ corrected.product_name
        }}{{ corrected.variant_name ? ` · ${corrected.variant_name}` : '' }} is now
        {{ formatQuantity(corrected.stock_after, { short_name: corrected.unit }) }}
        ({{ stockChange(corrected.stock_after - corrected.stock_before, corrected.unit) }}).
      </p>

      <div class="table-wrap">
        <table class="data-table sizes-table">
          <thead>
            <tr>
              <th v-if="hasSizes" scope="col">Size</th>
              <th scope="col">SKU <InfoTip term="SKU" /></th>
              <th scope="col" class="num">Selling price</th>
              <th scope="col" class="num">MRP <InfoTip term="MRP" /></th>
              <th scope="col" class="num">Stock</th>
              <th scope="col" class="actions"><span class="visually-hidden">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="variant in product.variants" :key="variant.id">
              <td v-if="hasSizes" class="size">{{ variant.name }}</td>
              <td class="mono">{{ variant.sku ?? '—' }}</td>
              <td class="num">{{ formatMoney(variant.selling_price) }}</td>
              <td class="num">{{ variant.mrp === null ? '—' : formatMoney(variant.mrp) }}</td>
              <td class="num">{{ formatQuantity(variant.stock_quantity, product.unit) }}</td>
              <td class="actions">
                <button
                  type="button"
                  class="btn"
                  :aria-label="`Correct stock of ${variant.name ?? product.name}`"
                  @click="correcting = variant"
                >
                  Correct stock
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section class="history" aria-labelledby="history-title">
      <h2 id="history-title">Stock history</h2>
      <p class="history-intro">
        What came in through Stock in, and every stock correction. Sales are on the bills.
      </p>

      <p v-if="!historyLoaded">Loading…</p>

      <div v-else-if="historyError" class="alert-error load-error" role="alert">
        <span>Could not load the stock history: {{ historyError }}</span>
        <button type="button" class="btn" @click="loadHistory">Try again</button>
      </div>

      <p v-else-if="history.length === 0" class="empty">
        Nothing yet. Deliveries from Stock in and stock corrections show here.
      </p>

      <div v-else class="table-wrap">
        <table class="data-table history-table">
          <thead>
            <tr>
              <th scope="col">Date</th>
              <th v-if="hasSizes" scope="col">Size</th>
              <th scope="col" class="num">Change</th>
              <th scope="col">What happened</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, index) in history" :key="index">
              <td>{{ formatDate(`${row.date}T00:00:00`) }}</td>
              <td v-if="hasSizes">{{ row.variant_name ?? '—' }}</td>
              <td class="num change" :class="row.change < 0 ? 'down' : 'up'">
                {{ stockChange(row.change, row.unit) }}
              </td>
              <td class="what-cell">
                <template v-if="row.type === 'stock_in'">
                  <RouterLink :to="{ name: 'stock-entry-show', params: { id: row.stock_entry_id } }"
                    >Stock in</RouterLink
                  >{{ deliveryText(row) }}
                </template>
                <template v-else>{{ correctionText(row) }}</template>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </article>

  <StockCorrectionDialog
    v-if="correcting"
    :product-name="product.name"
    :variant="correcting"
    :unit="product.unit"
    @saved="onCorrected"
    @close="correcting = null"
  />
</template>

<style scoped>
.top {
  display: grid;
  grid-template-columns: minmax(0, 22rem) minmax(0, 1fr);
  gap: 1.5rem 2rem;
  align-items: start;
}

@media (max-width: 720px) {
  .top {
    grid-template-columns: minmax(0, 1fr);
  }
}

.subtitle {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  margin: 0.25rem 0 1rem;
}

.price {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.25rem 0.75rem;
}

.amount {
  font-size: 1.75rem;
  font-weight: 700;
  color: var(--color-heading);
}

.note,
.mrp {
  font-size: 0.9rem;
  opacity: 0.85;
}

.mrp {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
}

.stock {
  margin: 0.5rem 0 1rem;
}

.stock strong {
  font-weight: 600;
  color: var(--color-heading);
}

.place {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 0.25rem 0.75rem;
  margin-bottom: 1.25rem;
  padding: 0.75rem 1rem;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.place-label {
  display: inline-flex;
  align-items: center;
  gap: 0.35rem;
  font-size: 0.85rem;
}

.code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--color-heading);
  letter-spacing: 0.03em;
}

.words {
  font-size: 0.9rem;
  opacity: 0.85;
}

.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(10rem, 1fr));
  gap: 0.75rem 1.5rem;
  margin: 0;
}

.facts dt {
  display: flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.85rem;
  opacity: 0.8;
}

.facts dd {
  margin: 0;
  color: var(--color-heading);
}

.description {
  max-width: 48rem;
  margin-top: 1.5rem;
  white-space: pre-line;
}

.sizes {
  margin-top: 1.75rem;
}

.sizes h2 {
  margin-bottom: 0.75rem;
  font-size: 1.15rem;
  font-weight: 600;
  color: var(--color-heading);
}

.num {
  text-align: right;
}

.size {
  color: var(--color-heading);
}

.header-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.corrected {
  margin-bottom: 0.75rem;
}

.history {
  margin-top: 1.75rem;
}

.history h2 {
  font-size: 1.15rem;
  font-weight: 600;
  color: var(--color-heading);
}

.history-intro {
  margin-bottom: 0.75rem;
  font-size: 0.9rem;
  opacity: 0.85;
}

.empty {
  opacity: 0.85;
}

.change.up {
  color: var(--color-success);
}

.change.down {
  color: var(--color-danger);
}

/* Long notes and supplier names wrap, so the table keeps to the page. */
.data-table td.what-cell {
  min-width: 18rem;
  white-space: normal;
}
</style>
