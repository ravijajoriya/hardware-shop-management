<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useStockStore } from '@/stores/stock'
import { formatDate, formatDateTime, formatMoney } from '@/utils/format'
import { itemName, itemQuantity } from '@/utils/billing'
import { stockChange } from '@/utils/stock'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import InfoTip from '@/components/InfoTip.vue'

const props = defineProps({
  id: { type: String, required: true },
})

const stock = useStockStore()
const route = useRoute()
const router = useRouter()

const listRoute = computed(() => ({ name: 'stock-entries', query: stock.listQuery }))

const entry = ref(null)
const loading = ref(true)
const error = ref('')
const notFound = ref(false)

const deleting = ref(false)
const deleteBusy = ref(false)
const deleteError = ref('')

// Set when arriving straight from the form. The note shows once, so it is taken out of the
// address: a reload or a later visit should not repeat it.
const justSaved = ref(route.query.saved === '1')

if (justSaved.value) {
  router.replace({ query: {} })
}

// What deleting would take back out of stock, product by product.
const deleteMessage = computed(() => {
  const changes = entry.value.items
    .filter((item) => item.product_variant_id)
    .map((item) => `${itemName(item)} ${stockChange(-item.quantity, item.unit)}`)
    .join(', ')

  return `Delete this delivery? Its stock comes back out (${changes}), and the purchase prices go back to what they were. This cannot be undone.`
})

async function load() {
  loading.value = true
  error.value = ''

  try {
    entry.value = await stock.fetchOne(props.id)
  } catch (e) {
    notFound.value = e.status === 404
    error.value = notFound.value ? 'This delivery does not exist.' : e.message
  } finally {
    loading.value = false
  }
}

async function confirmDelete() {
  deleteBusy.value = true
  deleteError.value = ''

  try {
    await stock.remove(entry.value.id)
    deleting.value = false
    router.push(listRoute.value)
  } catch (e) {
    deleteError.value = e.message
  } finally {
    deleteBusy.value = false
  }
}

// Back to wherever the delivery was opened from, such as the list or a product's history.
function goBack() {
  if (window.history.state?.back) {
    router.back()
  } else {
    router.push(listRoute.value)
  }
}

watch(() => props.id, load, { immediate: true })
</script>

<template>
  <div class="page-header">
    <button type="button" class="btn" @click="goBack">← Back</button>
    <div v-if="entry" class="actions">
      <button type="button" class="btn" @click="deleting = true">Delete</button>
      <RouterLink class="btn btn-primary" :to="{ name: 'stock-entry-create' }">
        New stock entry
      </RouterLink>
    </div>
  </div>

  <p v-if="loading">Loading…</p>

  <div v-else-if="error" class="alert-error load-error" role="alert">
    <span>{{ error }}</span>
    <RouterLink v-if="notFound" class="btn" :to="{ name: 'stock-entries' }">
      See all deliveries
    </RouterLink>
    <button v-else type="button" class="btn" @click="load">Try again</button>
  </div>

  <template v-else-if="entry">
    <p v-if="justSaved" class="alert-success saved" role="status">
      Saved. {{ entry.items.length }} {{ entry.items.length === 1 ? 'item was' : 'items were' }}
      added to stock.
    </p>

    <h1 class="page-title">
      Stock in{{ entry.supplier_name ? ` from ${entry.supplier_name}` : '' }}
    </h1>

    <dl class="facts">
      <div>
        <dt>Date received</dt>
        <dd>{{ formatDate(`${entry.received_on}T00:00:00`) }}</dd>
      </div>
      <div>
        <dt>Bill or challan no. <InfoTip term="Challan" /></dt>
        <dd class="mono">{{ entry.supplier_bill_number ?? '—' }}</dd>
      </div>
      <div>
        <dt>Total cost</dt>
        <dd>{{ formatMoney(entry.total) }}</dd>
      </div>
      <div>
        <dt>Entered</dt>
        <dd>
          {{ formatDateTime(entry.created_at)
          }}{{ entry.created_by ? ` by ${entry.created_by}` : '' }}
        </dd>
      </div>
    </dl>

    <p v-if="entry.note" class="note">{{ entry.note }}</p>

    <div class="table-wrap">
      <table class="data-table">
        <thead>
          <tr>
            <th scope="col">Item</th>
            <th scope="col" class="num">Qty</th>
            <th scope="col" class="num">Purchase price</th>
            <th scope="col" class="num">Amount</th>
            <th scope="col" class="num">Average cost <InfoTip term="Average cost" /></th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="item in entry.items" :key="item.id">
            <td class="item-cell">
              <RouterLink
                v-if="item.product_id"
                :to="{ name: 'product-show', params: { id: item.product_id } }"
              >
                {{ item.product_name }}
              </RouterLink>
              <template v-else>{{ item.product_name }} (product deleted)</template>
              <div v-if="item.variant_name" class="details">{{ item.variant_name }}</div>
            </td>
            <td class="num">{{ itemQuantity(item) }}</td>
            <td class="num">{{ formatMoney(item.purchase_price) }}</td>
            <td class="num">{{ formatMoney(item.line_total) }}</td>
            <td class="num">
              {{ formatMoney(item.cost_after) }}
              <div
                v-if="item.cost_before !== null && item.cost_before !== item.cost_after"
                class="details"
              >
                was {{ formatMoney(item.cost_before) }}
              </div>
            </td>
          </tr>
        </tbody>
        <tfoot>
          <tr>
            <th scope="row" colspan="3" class="num">Total</th>
            <td class="num total">{{ formatMoney(entry.total) }}</td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>
  </template>

  <ConfirmDialog
    v-if="deleting"
    title="Delete delivery"
    :message="deleteMessage"
    confirm-label="Delete"
    :busy="deleteBusy"
    :error="deleteError"
    @confirm="confirmDelete"
    @close="deleting = false"
  />
</template>

<style scoped>
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.saved {
  margin-bottom: 1rem;
}

.facts {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
  gap: 0.75rem 1.5rem;
  margin: 1rem 0;
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

.note {
  margin-bottom: 1rem;
  white-space: pre-line;
}

.data-table td.item-cell {
  min-width: 14rem;
  white-space: normal;
}

.details {
  font-size: 0.85rem;
  opacity: 0.8;
}

.num {
  text-align: right;
}

.data-table tfoot tr {
  border-top: 1px solid var(--color-border);
}

.data-table tfoot th {
  background: none;
}

.total {
  font-weight: 700;
  color: var(--color-heading);
}
</style>
