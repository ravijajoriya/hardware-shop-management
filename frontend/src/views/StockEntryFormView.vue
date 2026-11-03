<script setup>
import { computed, nextTick, onMounted, reactive, ref } from 'vue'
import { RouterLink, onBeforeRouteLeave, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useStockStore } from '@/stores/stock'
import { formatMoney, formatQuantity } from '@/utils/format'
import { itemName, lineTotal, toPaise } from '@/utils/billing'
import { costAfter, entryPayload, entryTotal, isoDate, stockLine } from '@/utils/stock'
import FieldLabel from '@/components/FieldLabel.vue'
import InfoTip from '@/components/InfoTip.vue'
import ProductPicker from '@/components/ProductPicker.vue'

const auth = useAuthStore()
const stock = useStockStore()
const router = useRouter()

const listRoute = computed(() => ({ name: 'stock-entries', query: stock.listQuery }))

// Deliveries cannot be dated in the future; the server checks this by the shop's calendar.
const today = isoDate()

const form = reactive({ supplier_name: '', supplier_bill_number: '', received_on: today, note: '' })
const lines = ref([])
const picker = ref(null)
const supplierInput = ref(null)

// Supplier names used before, offered while typing.
const suppliers = ref([])

const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})
let saved = false

const total = computed(() => entryTotal(lines.value))

function errorFor(field) {
  return fieldErrors.value[field]?.[0]
}

function lineFor(variantId) {
  return lines.value.find((line) => line.variant_id === variantId)
}

// Picking a size that is already listed adds one more of it instead of a second line.
// Either way the quantity is selected, ready to be typed over.
async function pick(product, variant) {
  let line = lineFor(variant.id)

  if (line) {
    line.quantity = (Number(line.quantity) || 0) + 1
  } else {
    line = stockLine(product, variant)
    lines.value.push(line)
  }

  await nextTick()
  document.getElementById(`stock-line-${line.key}-quantity`)?.select()
}

function removeLine(index) {
  lines.value.splice(index, 1)
  // The server's messages are by line position, which has now changed.
  fieldErrors.value = {}
}

function focusPicker() {
  picker.value?.focus()
}

// Shown when this delivery changes what the size cost.
function costChanges(line) {
  return line.cost !== null && toPaise(costAfter(line)) !== toPaise(line.cost)
}

async function save() {
  error.value = ''

  if (lines.value.length === 0) {
    error.value = 'Add at least one item that came in.'
    focusPicker()
    return
  }

  saving.value = true
  fieldErrors.value = {}

  try {
    const entry = await stock.create(entryPayload(form, lines.value))

    saved = true
    // Replaced, so Back from the delivery does not return to an empty form.
    router.replace({ name: 'stock-entry-show', params: { id: entry.id }, query: { saved: '1' } })
  } catch (e) {
    fieldErrors.value = e.errors ?? {}
    error.value = Object.keys(fieldErrors.value).length
      ? 'Some details need fixing. Check the messages above.'
      : e.message
  } finally {
    saving.value = false
  }
}

// Leaving by mistake would lose the items listed so far. A logout or an expired session
// leaves anyway.
onBeforeRouteLeave(() => {
  if (!saved && auth.isAuthenticated && lines.value.length > 0) {
    return window.confirm('Leave without saving this delivery? The items on it will be lost.')
  }
})

onMounted(() => {
  supplierInput.value?.focus()

  // Typing still works without suggestions, so a failure here is not shown.
  stock
    .supplierNames()
    .then((names) => (suppliers.value = names))
    .catch(() => {})
})
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">New stock entry</h1>
    <RouterLink class="btn" :to="listRoute">Back to stock in</RouterLink>
  </div>

  <form @submit.prevent="save">
    <section class="form-section">
      <h2>Delivery</h2>

      <div class="grid">
        <div class="field">
          <label for="stock-supplier">Supplier <span class="optional">optional</span></label>
          <input
            id="stock-supplier"
            ref="supplierInput"
            v-model="form.supplier_name"
            class="input"
            type="text"
            maxlength="100"
            list="stock-suppliers"
            autocomplete="off"
            placeholder="e.g. Sharma Traders"
            @keydown.enter.prevent="focusPicker"
          />
          <datalist id="stock-suppliers">
            <option v-for="name in suppliers" :key="name" :value="name"></option>
          </datalist>
          <p v-if="errorFor('supplier_name')" class="field-error">
            {{ errorFor('supplier_name') }}
          </p>
        </div>

        <div class="field">
          <FieldLabel input-id="stock-bill-number" term="Challan">
            Bill or challan no. <span class="optional">optional</span>
          </FieldLabel>
          <input
            id="stock-bill-number"
            v-model="form.supplier_bill_number"
            class="input"
            type="text"
            maxlength="50"
            autocomplete="off"
            placeholder="e.g. ST-4512"
            @keydown.enter.prevent="focusPicker"
          />
          <p v-if="errorFor('supplier_bill_number')" class="field-error">
            {{ errorFor('supplier_bill_number') }}
          </p>
        </div>

        <div class="field">
          <label for="stock-received-on">Date received</label>
          <input
            id="stock-received-on"
            v-model="form.received_on"
            class="input"
            type="date"
            :max="today"
            required
            @keydown.enter.prevent="focusPicker"
          />
          <p v-if="errorFor('received_on')" class="field-error">{{ errorFor('received_on') }}</p>
          <p v-else class="hint">Change it if the goods came on an earlier day.</p>
        </div>

        <div class="field wide">
          <label for="stock-note">Note <span class="optional">optional</span></label>
          <input
            id="stock-note"
            v-model="form.note"
            class="input"
            type="text"
            maxlength="255"
            autocomplete="off"
            placeholder="e.g. 2 cartons, 1 box short"
            @keydown.enter.prevent="focusPicker"
          />
          <p v-if="errorFor('note')" class="field-error">{{ errorFor('note') }}</p>
        </div>
      </div>
    </section>

    <section class="form-section">
      <h2>What came in</h2>

      <ProductPicker
        ref="picker"
        placeholder="Search by product name or item code to add it"
        label="Find a product that came in"
        price-field="purchase_price"
        :picked-ids="lines.map((line) => line.variant_id)"
        picked-note="Already added: adds 1 more"
        @pick="pick"
      />

      <div v-if="lines.length" class="lines">
        <div class="line line-head" aria-hidden="true">
          <span>Item</span>
          <span class="num">Qty</span>
          <span class="num">Purchase price</span>
          <span class="num">Amount</span>
          <span class="num">Average cost</span>
          <span></span>
        </div>

        <div v-for="(line, index) in lines" :key="line.key" class="line">
          <div class="line-item">
            <span class="name">{{ line.product_name }}</span>
            <span v-if="line.variant_name" class="details">{{ line.variant_name }}</span>
            <span class="details">{{ formatQuantity(line.stock, line.unit) }} in stock now</span>
            <p v-if="errorFor(`items.${index}.product_variant_id`)" class="field-error">
              {{ errorFor(`items.${index}.product_variant_id`) }}
            </p>
          </div>

          <div class="line-qty">
            <span class="caption" aria-hidden="true">Qty</span>
            <div class="with-unit">
              <input
                :id="`stock-line-${line.key}-quantity`"
                v-model="line.quantity"
                class="input"
                type="number"
                :min="line.unit.allow_decimal ? 0.001 : 1"
                :step="line.unit.allow_decimal ? 'any' : 1"
                required
                :aria-label="`Quantity of ${itemName(line)} that came in, in ${line.unit.name}`"
                @keydown.enter.prevent="focusPicker"
              />
              <span class="unit">{{ line.unit.short_name }}</span>
            </div>
            <p v-if="errorFor(`items.${index}.quantity`)" class="field-error">
              {{ errorFor(`items.${index}.quantity`) }}
            </p>
          </div>

          <div class="line-price">
            <span class="caption" aria-hidden="true">Purchase price</span>
            <input
              v-model="line.price"
              class="input"
              type="number"
              min="0"
              step="0.01"
              required
              :aria-label="`Purchase price of ${itemName(line)}, per ${line.unit.short_name}`"
              @keydown.enter.prevent="focusPicker"
            />
            <p v-if="errorFor(`items.${index}.purchase_price`)" class="field-error">
              {{ errorFor(`items.${index}.purchase_price`) }}
            </p>
            <p v-else class="per">per {{ line.unit.short_name }}</p>
          </div>

          <div class="line-amount">
            <span class="caption" aria-hidden="true">Amount</span>
            <strong>{{ formatMoney(lineTotal(line)) }}</strong>
          </div>

          <div class="line-cost">
            <span class="caption" aria-hidden="true">Average cost</span>
            <strong>{{ formatMoney(costAfter(line)) }}</strong>
            <span v-if="costChanges(line)" class="details">was {{ formatMoney(line.cost) }}</span>
          </div>

          <button
            type="button"
            class="btn remove"
            :aria-label="`Remove ${itemName(line)}`"
            @click="removeLine(index)"
          >
            ✕
          </button>
        </div>

        <div class="lines-total">
          <span>Total</span>
          <strong>{{ formatMoney(total) }}</strong>
        </div>
      </div>
      <p v-else class="hint">Nothing added yet. Search above and pick each product that came in.</p>
      <p v-if="errorFor('items')" class="field-error">{{ errorFor('items') }}</p>

      <p v-if="lines.length" class="hint cost-note">
        Saving adds these to stock. Each product’s purchase price becomes its average cost
        <InfoTip term="Average cost" /> shown above.
      </p>
    </section>

    <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

    <div class="form-actions">
      <RouterLink class="btn" :to="listRoute">Cancel</RouterLink>
      <button type="submit" class="btn btn-primary" :disabled="saving">
        {{ saving ? 'Saving…' : 'Save and add to stock' }}
      </button>
    </div>
  </form>
</template>

<style scoped>
.form-section {
  margin-bottom: 1.25rem;
  padding: 1.25rem 1.5rem;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.form-section h2 {
  margin-bottom: 1rem;
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--color-heading);
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(16rem, 100%), 1fr));
  gap: 0 1rem;
}

.grid .wide {
  grid-column: 1 / -1;
}

.hint {
  font-size: 0.85rem;
  opacity: 0.8;
}

.optional {
  margin-left: 0.25rem;
  font-size: 0.8rem;
  font-weight: normal;
  opacity: 0.7;
}

.lines {
  background: var(--color-background);
  border: 1px solid var(--color-border-hover);
  border-radius: 6px;
}

.line {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 8.5rem 8.5rem 7rem 7.5rem 2.5rem;
  gap: 0.75rem;
  align-items: start;
  padding: 0.6rem 0.75rem;
  border-top: 1px solid var(--color-border);
}

.line-head {
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--color-heading);
  background: var(--color-background-soft);
  border-top: 0;
  border-radius: 6px 6px 0 0;
}

.line-item {
  display: flex;
  flex-direction: column;
  padding-top: 0.4rem;
}

.line-item .name {
  color: var(--color-heading);
}

.details,
.per {
  font-size: 0.85rem;
  opacity: 0.8;
}

.per {
  margin-top: 0.2rem;
  text-align: right;
}

.with-unit {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.unit {
  min-width: 1.5rem;
  font-size: 0.85rem;
  opacity: 0.8;
}

.line .input {
  min-width: 0;
  padding: 0.4rem 0.5rem;
  text-align: right;
}

.line-amount,
.line-cost {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  padding-top: 0.4rem;
  text-align: right;
}

.line-amount strong,
.line-cost strong {
  font-weight: 600;
  color: var(--color-heading);
}

.caption {
  display: none;
}

.num {
  text-align: right;
}

.remove {
  padding: 0.35rem 0.6rem;
}

.line .field-error {
  margin-top: 0.25rem;
  font-size: 0.8rem;
}

.lines-total {
  display: flex;
  justify-content: flex-end;
  align-items: baseline;
  gap: 1.5rem;
  padding: 0.75rem;
  font-size: 1.1rem;
  border-top: 1px solid var(--color-border);
}

.lines-total strong {
  font-weight: 700;
  color: var(--color-heading);
}

.cost-note {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.3rem;
  margin-top: 0.75rem;
}

/* On a phone each line takes three rows: the item, then quantity and price, then the sums. */
@media (max-width: 640px) {
  .line-head {
    display: none;
  }

  .line {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
    grid-template-areas:
      'item item remove'
      'qty price .'
      'amount cost .';
  }

  .line-head + .line {
    border-top: 0;
  }

  .line-item {
    grid-area: item;
    padding-top: 0;
  }

  .line-qty {
    grid-area: qty;
  }

  .line-price {
    grid-area: price;
  }

  .line-amount {
    grid-area: amount;
    align-items: flex-start;
    padding-top: 0;
  }

  .line-cost {
    grid-area: cost;
    align-items: flex-start;
    padding-top: 0;
  }

  .remove {
    grid-area: remove;
    justify-self: end;
  }

  .per {
    text-align: left;
  }

  .caption {
    display: block;
    font-size: 0.75rem;
    opacity: 0.75;
  }
}

.alert-error {
  margin-bottom: 1rem;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}

/* On a small phone every bit of width counts. */
@media (max-width: 480px) {
  .form-section {
    padding: 1rem;
  }
}
</style>
