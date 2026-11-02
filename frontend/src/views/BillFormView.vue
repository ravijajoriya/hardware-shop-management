<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, onBeforeRouteLeave, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useBillStore } from '@/stores/bills'
import { useCustomerStore } from '@/stores/customers'
import { useProductStore } from '@/stores/products'
import { formatMoney, formatQuantity } from '@/utils/format'
import {
  PAYMENT_METHODS,
  PAYMENT_MODES,
  billLine,
  billPayload,
  billPayments,
  billTotals,
  isShortOfStock,
  isValidMobile,
  itemName,
  lineTotal,
  normalizeMobile,
  paymentRow,
  toPaise,
} from '@/utils/billing'
import ConfirmDialog from '@/components/ConfirmDialog.vue'
import FieldLabel from '@/components/FieldLabel.vue'
import InfoTip from '@/components/InfoTip.vue'

const auth = useAuthStore()
const bills = useBillStore()
const customers = useCustomerStore()
const products = useProductStore()
const route = useRoute()
const router = useRouter()

const listRoute = computed(() => ({ name: 'bills', query: bills.listQuery }))

const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})
const confirming = ref(false)
let saved = false

function errorFor(field) {
  return fieldErrors.value[field]?.[0]
}

// --- Customer ---

const customer = reactive({
  // Filled in when coming from a customer's page with "New bill".
  mobile: typeof route.query.mobile === 'string' ? route.query.mobile : '',
  name: '',
})

const mobileInput = ref(null)
const nameInput = ref(null)

// The saved customer with the typed number: null for a new number, undefined until checked.
const known = ref(undefined)
const lookupError = ref('')

// The name filled in by the last lookup, so it can be taken back when the number changes.
let filledName = ''
let lookupTimer
let latestLookup = 0

const mobile = computed(() => normalizeMobile(customer.mobile))
const mobileComplete = computed(() => isValidMobile(mobile.value))

// A returning customer's saved name is replaced by the one typed on this bill.
const renaming = computed(() => {
  const typed = customer.name.trim()

  return Boolean(known.value && typed && typed !== known.value.name)
})

watch(
  mobile,
  (value) => {
    clearTimeout(lookupTimer)
    latestLookup++
    known.value = undefined
    lookupError.value = ''

    if (isValidMobile(value)) {
      lookupTimer = setTimeout(() => lookup(value), 250)
    }
  },
  { immediate: true },
)

async function lookup(value) {
  const request = ++latestLookup

  try {
    const found = await customers.lookup(value)

    // The number may have changed while this one was being checked.
    if (request === latestLookup) {
      known.value = found
      fillName(found)
    }
  } catch (e) {
    if (request === latestLookup) {
      lookupError.value = e.message
    }
  }
}

// Fill in a returning customer's name unless another was typed, and clear a name that was
// filled in for a different number.
function fillName(found) {
  const typed = customer.name.trim()

  if (found && (!typed || typed === filledName)) {
    customer.name = found.name
    filledName = found.name
  } else if (!found && typed && typed === filledName) {
    customer.name = ''
    filledName = ''
  }
}

// --- Items ---

const lines = ref([])
const searchInput = ref(null)
const term = ref('')
const results = ref([])
const searched = ref('')
const searching = ref(false)
const searchError = ref('')

let searchTimer
let latestSearch = 0

watch(term, (value) => {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => search(value.trim()), 250)
})

async function search(value) {
  const request = ++latestSearch
  searchError.value = ''

  if (!value) {
    results.value = []
    searched.value = ''
    searching.value = false
    return
  }

  searching.value = true

  try {
    const found = await products.find(value)

    // An older, slower search must not replace the results of a newer one.
    if (request === latestSearch) {
      results.value = found
      searched.value = value
    }
  } catch (e) {
    if (request === latestSearch) {
      searchError.value = e.message
    }
  } finally {
    if (request === latestSearch) {
      searching.value = false
    }
  }
}

// Every size in the results, so Enter can add one when it is the only one.
const choices = computed(() =>
  results.value.flatMap((product) => product.variants.map((variant) => ({ product, variant }))),
)

function lineFor(variantId) {
  return lines.value.find((line) => line.variant_id === variantId)
}

// Picking a size that is already on the bill adds one more of it instead of a second line.
// Either way the quantity is selected, ready to be typed over.
async function pick(product, variant) {
  let line = lineFor(variant.id)

  if (line) {
    line.quantity = (Number(line.quantity) || 0) + 1
  } else {
    line = billLine(product, variant)
    lines.value.push(line)
  }

  term.value = ''
  results.value = []
  searched.value = ''

  await nextTick()
  document.getElementById(`line-${line.key}-quantity`)?.select()
}

function pickOnlyChoice() {
  if (choices.value.length === 1) {
    const [{ product, variant }] = choices.value

    pick(product, variant)
  }
}

function removeLine(index) {
  lines.value.splice(index, 1)
  // The server's messages are by line position, which has now changed.
  fieldErrors.value = {}
}

function focusSearch() {
  searchInput.value?.focus()
}

function stockNote(line) {
  return line.stock > 0
    ? `Only ${formatQuantity(line.stock, line.unit)} in stock`
    : 'None in stock by the count'
}

// --- Payment ---

const paymentMode = ref('full')
const fullPayment = reactive({ method: 'cash', reference: '' })
// Cash and UPI is the usual split; a row left empty is ignored.
const splitRows = ref([paymentRow('cash'), paymentRow('upi')])

const billTotal = computed(() => billTotals(lines.value, []).total)
const payments = computed(() =>
  billPayments(paymentMode.value, { full: fullPayment, rows: splitRows.value }, billTotal.value),
)
const totals = computed(() => billTotals(lines.value, payments.value))

// What is left to pay after the other split rows, for the "Rest" shortcut.
function restFor(row) {
  const others = splitRows.value
    .filter((other) => other.key !== row.key)
    .reduce((sum, other) => sum + toPaise(other.amount), 0)

  return Math.max(toPaise(billTotal.value) - others, 0) / 100
}

function isBlank(value) {
  return String(value ?? '').trim() === ''
}

function addPaymentRow() {
  const used = new Set(splitRows.value.map((row) => row.method))
  const unused = PAYMENT_METHODS.find((method) => !used.has(method.value))

  splitRows.value.push(paymentRow(unused?.value ?? 'cash'))
}

function removePaymentRow(index) {
  splitRows.value.splice(index, 1)
  fieldErrors.value = {}
}

function paymentRowError(index) {
  return ['method', 'amount', 'reference']
    .map((field) => errorFor(`payments.${index}.${field}`))
    .find(Boolean)
}

// --- Saving ---

const shortLines = computed(() => lines.value.filter(isShortOfStock))

// What the shopkeeper should agree to before saving; empty when there is nothing to check.
const confirmMessage = computed(() => {
  const parts = []

  if (totals.value.due > 0) {
    parts.push(
      `${formatMoney(totals.value.due)} of this bill stays due (udhaar) on ${customer.name.trim()}’s account.`,
    )
  }

  if (shortLines.value.length) {
    const names = shortLines.value
      .map((line) => `${itemName(line)} (${formatQuantity(line.stock, line.unit)} in stock)`)
      .join(', ')

    parts.push(`More than the stock shows: ${names}. The stock count will go below zero.`)
  }

  return parts.join(' ')
})

function submit() {
  error.value = ''

  if (paymentMode.value === 'split') {
    // Empty rows are dropped, so the server's messages line up with the rows on screen.
    const filled = splitRows.value.filter((row) => !isBlank(row.amount))

    splitRows.value = filled.length ? filled : [paymentRow()]
  }

  if (lines.value.length === 0) {
    error.value = 'Add at least one item to the bill.'
    focusSearch()
    return
  }

  if (paymentMode.value === 'split' && totals.value.paid === 0) {
    error.value = 'Enter how much was paid each way, or choose “Nothing paid now”.'
    return
  }

  if (totals.value.overpaid) {
    error.value = `The payments add up to ${formatMoney(totals.value.paid)}, more than the bill total of ${formatMoney(totals.value.total)}.`
    return
  }

  if (confirmMessage.value) {
    confirming.value = true
  } else {
    save()
  }
}

async function save() {
  saving.value = true
  fieldErrors.value = {}

  try {
    const bill = await bills.create(billPayload(customer, lines.value, payments.value))

    saved = true
    // Replaced, so Back from the bill does not return to an empty form.
    router.replace({ name: 'bill-show', params: { id: bill.id }, query: { saved: '1' } })
  } catch (e) {
    fieldErrors.value = e.errors ?? {}
    error.value = Object.keys(fieldErrors.value).length
      ? 'Some details need fixing. Check the messages above.'
      : e.message
  } finally {
    saving.value = false
    confirming.value = false
  }
}

// Leaving by mistake would lose the items picked so far. A logout or an expired session
// leaves anyway.
onBeforeRouteLeave(() => {
  if (!saved && auth.isAuthenticated && lines.value.length > 0) {
    return window.confirm('Leave without saving this bill? The items on it will be lost.')
  }
})

onMounted(() => (customer.mobile ? searchInput : mobileInput).value?.focus())
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">New bill</h1>
    <RouterLink class="btn" :to="listRoute">Back to bills</RouterLink>
  </div>

  <form @submit.prevent="submit">
    <section class="form-section">
      <h2>Customer</h2>

      <div class="grid">
        <div class="field">
          <label for="bill-mobile">Mobile number</label>
          <input
            id="bill-mobile"
            ref="mobileInput"
            v-model="customer.mobile"
            class="input"
            type="tel"
            inputmode="tel"
            autocomplete="off"
            maxlength="16"
            placeholder="e.g. 98765 43210"
            required
            @keydown.enter.prevent="nameInput?.focus()"
          />
          <p v-if="errorFor('customer.mobile')" class="field-error">
            {{ errorFor('customer.mobile') }}
          </p>
          <p v-else-if="lookupError" class="field-error">
            Could not check this number: {{ lookupError }}
          </p>
          <p v-else-if="!mobileComplete" class="hint">
            10 digits. The bill can be sent to this number on WhatsApp.
          </p>
          <p v-else-if="known === undefined" class="hint">Checking…</p>
          <p v-else-if="known" class="hint">
            Returning customer, {{ known.bills_count }}
            {{ known.bills_count === 1 ? 'bill' : 'bills' }} so far.
            <template v-if="known.total_due > 0">
              <strong class="owes">{{ formatMoney(known.total_due) }} due</strong>
              <InfoTip term="Due" class="owes-tip" />
            </template>
          </p>
          <p v-else class="hint">New customer. They are saved with this bill.</p>
        </div>

        <div class="field">
          <label for="bill-name">Customer name</label>
          <input
            id="bill-name"
            ref="nameInput"
            v-model="customer.name"
            class="input"
            type="text"
            maxlength="255"
            autocomplete="off"
            required
            @keydown.enter.prevent="focusSearch"
          />
          <p v-if="errorFor('customer.name')" class="field-error">
            {{ errorFor('customer.name') }}
          </p>
          <p v-else-if="renaming" class="hint">
            Saved as “{{ known.name }}”. This bill changes it to “{{ customer.name.trim() }}”.
          </p>
        </div>
      </div>
    </section>

    <section class="form-section">
      <h2>Items</h2>

      <div class="finder">
        <input
          ref="searchInput"
          v-model="term"
          class="input"
          type="search"
          autocomplete="off"
          placeholder="Search by product name or item code to add it"
          aria-label="Find a product to add to the bill"
          @keydown.enter.prevent="pickOnlyChoice"
        />

        <p v-if="searchError" class="field-error">{{ searchError }}</p>
        <p v-else-if="searching && results.length === 0" class="hint">Searching…</p>
        <p v-else-if="searched && results.length === 0" class="hint">
          Nothing found for “{{ searched }}”.
        </p>

        <ul v-if="results.length" class="results" :class="{ busy: searching }">
          <li v-for="product in results" :key="product.id" class="result">
            <span class="result-name">
              {{ product.name }}
              <span v-if="!product.is_active" class="badge">Inactive</span>
            </span>
            <div class="picks">
              <button
                v-for="variant in product.variants"
                :key="variant.id"
                type="button"
                class="pick"
                :aria-label="`Add ${itemName({ product_name: product.name, variant_name: variant.name })} at ${formatMoney(variant.selling_price)}`"
                @click="pick(product, variant)"
              >
                <span v-if="variant.name" class="pick-size">{{ variant.name }}</span>
                <strong>{{ formatMoney(variant.selling_price) }}</strong>
                <span class="pick-stock" :class="{ out: variant.stock_quantity <= 0 }">
                  {{ formatQuantity(variant.stock_quantity, product.unit) }} in stock
                </span>
                <span v-if="lineFor(variant.id)" class="pick-added">On the bill: adds 1 more</span>
              </button>
            </div>
          </li>
        </ul>
      </div>

      <div v-if="lines.length" class="lines">
        <div class="line line-head" aria-hidden="true">
          <span>Item</span>
          <span class="num">Qty</span>
          <span class="num">Price</span>
          <span class="num">Amount</span>
          <span></span>
        </div>

        <div v-for="(line, index) in lines" :key="line.key" class="line">
          <div class="line-item">
            <span class="name">{{ line.product_name }}</span>
            <span v-if="line.variant_name" class="details">{{ line.variant_name }}</span>
            <p v-if="errorFor(`items.${index}.product_variant_id`)" class="field-error">
              {{ errorFor(`items.${index}.product_variant_id`) }}
            </p>
          </div>

          <div class="line-qty">
            <span class="caption" aria-hidden="true">Qty</span>
            <div class="with-unit">
              <input
                :id="`line-${line.key}-quantity`"
                v-model="line.quantity"
                class="input"
                type="number"
                :min="line.unit.allow_decimal ? 0.001 : 1"
                :step="line.unit.allow_decimal ? 'any' : 1"
                required
                :aria-label="`Quantity of ${itemName(line)}, in ${line.unit.name}`"
                @keydown.enter.prevent="focusSearch"
              />
              <span class="unit">{{ line.unit.short_name }}</span>
            </div>
            <p v-if="errorFor(`items.${index}.quantity`)" class="field-error">
              {{ errorFor(`items.${index}.quantity`) }}
            </p>
            <p v-else-if="isShortOfStock(line)" class="stock-warning">{{ stockNote(line) }}</p>
          </div>

          <div class="line-price">
            <span class="caption" aria-hidden="true">Price</span>
            <input
              v-model="line.price"
              class="input"
              type="number"
              min="0"
              step="0.01"
              required
              :aria-label="`Price of ${itemName(line)}, per ${line.unit.short_name}`"
              @keydown.enter.prevent="focusSearch"
            />
            <p v-if="errorFor(`items.${index}.price`)" class="field-error">
              {{ errorFor(`items.${index}.price`) }}
            </p>
            <p v-else-if="toPaise(line.price) !== toPaise(line.list_price)" class="list-price">
              List price <InfoTip term="List price" /> {{ formatMoney(line.list_price) }}
            </p>
          </div>

          <div class="line-amount">
            <span class="caption" aria-hidden="true">Amount</span>
            <strong>{{ formatMoney(lineTotal(line)) }}</strong>
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
          <strong>{{ formatMoney(totals.total) }}</strong>
        </div>
      </div>
      <p v-else class="hint">No items yet. Search above and pick a product to add it.</p>
      <p v-if="errorFor('items')" class="field-error">{{ errorFor('items') }}</p>
    </section>

    <section class="form-section">
      <h2>Payment</h2>

      <fieldset class="choice-group">
        <legend>How did the customer pay?</legend>
        <label v-for="mode in PAYMENT_MODES" :key="mode.value" class="choice">
          <input v-model="paymentMode" type="radio" name="payment_mode" :value="mode.value" />
          {{ mode.label }}
        </label>
      </fieldset>

      <template v-if="paymentMode === 'full'">
        <fieldset class="choice-group">
          <legend>Paid by</legend>
          <label v-for="method in PAYMENT_METHODS" :key="method.value" class="choice">
            <input
              v-model="fullPayment.method"
              type="radio"
              name="full_payment_method"
              :value="method.value"
            />
            {{ method.label }}
            <InfoTip v-if="method.value === 'upi'" term="UPI" />
          </label>
        </fieldset>

        <div v-if="fullPayment.method !== 'cash'" class="field reference">
          <FieldLabel input-id="bill-reference" term="Transaction number">
            Transaction number <span class="optional">optional</span>
          </FieldLabel>
          <input
            id="bill-reference"
            v-model="fullPayment.reference"
            class="input"
            type="text"
            maxlength="100"
            autocomplete="off"
          />
          <p v-if="paymentRowError(0)" class="field-error">{{ paymentRowError(0) }}</p>
        </div>
      </template>

      <template v-else-if="paymentMode === 'split'">
        <div v-for="(row, index) in splitRows" :key="row.key" class="split-row">
          <select v-model="row.method" class="input" :aria-label="`Payment ${index + 1}: paid by`">
            <option v-for="method in PAYMENT_METHODS" :key="method.value" :value="method.value">
              {{ method.label }}
            </option>
          </select>

          <div class="split-amount">
            <input
              v-model="row.amount"
              class="input"
              type="number"
              min="0.01"
              step="0.01"
              placeholder="Amount"
              :aria-label="`Payment ${index + 1}: amount`"
            />
            <button
              v-if="isBlank(row.amount) && restFor(row) > 0"
              type="button"
              class="link-button"
              @click="row.amount = restFor(row)"
            >
              Rest: {{ formatMoney(restFor(row)) }}
            </button>
          </div>

          <input
            v-if="row.method !== 'cash'"
            v-model="row.reference"
            class="input split-reference"
            type="text"
            maxlength="100"
            autocomplete="off"
            placeholder="Transaction number (optional)"
            :aria-label="`Payment ${index + 1}: transaction number`"
          />
          <span v-else class="split-no-reference"></span>

          <button
            type="button"
            class="btn remove"
            :disabled="splitRows.length === 1"
            :aria-label="`Remove payment ${index + 1}`"
            @click="removePaymentRow(index)"
          >
            ✕
          </button>

          <p v-if="paymentRowError(index)" class="field-error">{{ paymentRowError(index) }}</p>
        </div>

        <button type="button" class="btn" :disabled="splitRows.length >= 10" @click="addPaymentRow">
          + Add another payment
        </button>
        <p class="hint split-hint">
          Whatever is not paid stays due on the customer’s account. The transaction number
          <InfoTip term="Transaction number" /> is optional.
        </p>
      </template>

      <p v-else class="hint">
        The whole bill stays due on the customer’s account. They can pay it later from the Customers
        page.
      </p>

      <dl class="totals">
        <div>
          <dt>Total</dt>
          <dd>{{ formatMoney(totals.total) }}</dd>
        </div>
        <div>
          <dt>Paid now</dt>
          <dd>{{ formatMoney(totals.paid) }}</dd>
        </div>
        <div :class="{ owing: totals.due > 0 }">
          <dt>Due <InfoTip term="Due" /></dt>
          <dd>{{ formatMoney(totals.due) }}</dd>
        </div>
      </dl>
      <p v-if="totals.overpaid" class="field-error">
        The payments add up to {{ formatMoney(totals.paid) }}, more than the bill total.
      </p>
      <p v-if="errorFor('payments')" class="field-error">{{ errorFor('payments') }}</p>
    </section>

    <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

    <div class="form-actions">
      <RouterLink class="btn" :to="listRoute">Cancel</RouterLink>
      <button type="submit" class="btn btn-primary" :disabled="saving">
        {{ saving ? 'Saving…' : 'Save bill' }}
      </button>
    </div>
  </form>

  <ConfirmDialog
    v-if="confirming"
    title="Save this bill?"
    :message="confirmMessage"
    confirm-label="Save bill"
    :danger="false"
    :busy="saving"
    @confirm="save"
    @close="confirming = false"
  />
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
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 0 1rem;
}

.hint {
  font-size: 0.85rem;
  opacity: 0.8;
}

.owes {
  font-weight: 600;
  color: var(--color-danger);
}

/* The template drops the line break before it, which would otherwise be the space. */
.owes-tip {
  margin-left: 0.3rem;
}

.optional {
  margin-left: 0.25rem;
  font-size: 0.8rem;
  font-weight: normal;
  opacity: 0.7;
}

.finder {
  margin-bottom: 1rem;
}

.finder .hint,
.finder .field-error {
  margin-top: 0.5rem;
}

.results {
  max-height: 24rem;
  margin: 0.5rem 0 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
  background: var(--color-background);
  border: 1px solid var(--color-border-hover);
  border-radius: 6px;
}

.results.busy {
  opacity: 0.6;
}

.result {
  padding: 0.6rem 0.75rem;
  border-top: 1px solid var(--color-border);
}

.result:first-child {
  border-top: 0;
}

.result-name {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  font-weight: 600;
  color: var(--color-heading);
}

.picks {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.4rem;
}

.pick {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  padding: 0.4rem 0.75rem;
  font: inherit;
  line-height: 1.4;
  text-align: left;
  color: var(--color-text);
  background: var(--color-background-soft);
  border: 1px solid var(--color-border-hover);
  border-radius: 6px;
  cursor: pointer;
}

.pick:hover,
.pick:focus-visible {
  border-color: hsla(160, 100%, 37%, 1);
  box-shadow: inset 0 0 0 1px hsla(160, 100%, 37%, 1);
}

.pick strong {
  font-weight: 600;
  color: var(--color-heading);
}

.pick-size,
.pick-stock,
.pick-added {
  font-size: 0.8rem;
}

.pick-size,
.pick-stock {
  opacity: 0.85;
}

.pick-stock.out {
  color: var(--color-danger);
  opacity: 1;
}

.pick-added {
  color: var(--color-success);
}

.lines {
  background: var(--color-background);
  border: 1px solid var(--color-border-hover);
  border-radius: 6px;
}

.line {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 9rem 9rem 8rem 2.5rem;
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

.details {
  font-size: 0.85rem;
  opacity: 0.8;
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

.line-amount {
  padding-top: 0.4rem;
  text-align: right;
}

.line-amount strong {
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

.line .field-error,
.stock-warning,
.list-price {
  margin-top: 0.25rem;
  font-size: 0.8rem;
}

.stock-warning {
  color: var(--color-warning);
}

.list-price {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: flex-end;
  gap: 0.3rem;
  opacity: 0.85;
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

/* On a phone each line takes two rows: the item, then quantity, price and amount. */
@media (max-width: 640px) {
  .line-head {
    display: none;
  }

  .line {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
    grid-template-areas:
      'item item remove'
      'qty price amount';
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
    padding-top: 0;
  }

  .remove {
    grid-area: remove;
    justify-self: end;
  }

  .caption {
    display: block;
    font-size: 0.75rem;
    opacity: 0.75;
  }
}

.reference {
  max-width: 22rem;
}

.split-row {
  display: grid;
  grid-template-columns: 10rem 10rem minmax(0, 1fr) auto;
  gap: 0.5rem;
  align-items: start;
  margin-bottom: 0.5rem;
}

.split-row .field-error {
  grid-column: 1 / -1;
  margin-top: 0;
}

.split-amount {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 0.2rem;
}

@media (max-width: 640px) {
  /* Method, amount and remove on one row; the transaction number below them. */
  .split-row {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
  }

  .split-row > select {
    grid-area: 1 / 1;
  }

  .split-amount {
    grid-area: 1 / 2;
  }

  .split-row > .remove {
    grid-area: 1 / 3;
  }

  .split-reference {
    grid-area: 2 / 1 / 3 / 3;
  }

  .split-no-reference {
    display: none;
  }
}

.split-hint {
  margin-top: 0.5rem;
}

.link-button {
  padding: 0;
  font: inherit;
  font-size: 0.8rem;
  color: hsla(160, 100%, 37%, 1);
  text-decoration: underline;
  background: none;
  border: 0;
  cursor: pointer;
}

.totals {
  display: flex;
  flex-wrap: wrap;
  gap: 0.75rem 2.5rem;
  margin: 1rem 0 0;
  padding: 0.75rem 1rem;
  background: var(--color-background);
  border: 1px dashed var(--color-border-hover);
  border-radius: 6px;
}

.totals div {
  display: flex;
  flex-direction: column;
}

.totals dt {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.85rem;
}

.totals dd {
  margin: 0;
  font-size: 1.25rem;
  font-weight: 600;
  color: var(--color-heading);
}

.totals .owing dd {
  color: var(--color-danger);
}

.totals + .field-error {
  margin-top: 0.5rem;
}

.alert-error {
  margin-bottom: 1rem;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}
</style>
