<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useBillStore } from '@/stores/bills'
import { formatDateTime, formatMoney } from '@/utils/format'
import {
  billCaption,
  billMessage,
  formatMobile,
  itemQuantity,
  statusClass,
  whatsappLink,
} from '@/utils/billing'
import { canShareFile, downloadFile } from '@/utils/files'
import InfoTip from '@/components/InfoTip.vue'

const props = defineProps({
  id: { type: String, required: true },
})

const store = useBillStore()
const route = useRoute()
const router = useRouter()

const bill = ref(null)
const shop = ref(null)
const loading = ref(true)
const error = ref('')
const notFound = ref(false)

// Set when arriving straight from the new bill form. The note shows once, so it is taken out
// of the address: a reload or a later visit should not repeat it.
const justSaved = ref(route.query.saved === '1')

if (justSaved.value) {
  router.replace({ query: {} })
}

const whatsapp = computed(() =>
  whatsappLink(bill.value.customer.mobile, billMessage(bill.value, shop.value)),
)

// The bill's PDF, made as soon as the bill shows, for "Download PDF" and "Share PDF". It has to
// be ready before "Share PDF" is pressed: phones only open the share menu straight from the tap.
// ("Send on WhatsApp" needs none of this; its message links to the PDF on the server.)
const pdf = ref(null)
const pdfError = ref('')
const shareError = ref('')
let latestPdf = 0

const canShare = computed(() => pdf.value !== null && canShareFile(pdf.value))

async function load() {
  loading.value = true
  error.value = ''

  try {
    const result = await store.fetchOne(props.id)

    bill.value = result.bill
    shop.value = result.shop
    preparePdf()
  } catch (e) {
    notFound.value = e.status === 404
    error.value = notFound.value ? 'This bill does not exist.' : e.message
  } finally {
    loading.value = false
  }
}

async function preparePdf() {
  const request = ++latestPdf
  pdf.value = null
  pdfError.value = ''

  try {
    const file = await store.fetchPdf(bill.value)

    if (request === latestPdf) {
      pdf.value = file
    }
  } catch (e) {
    if (request === latestPdf) {
      pdfError.value = e.message
    }
  }
}

// Hands the PDF file itself to the device's share menu, where WhatsApp and the customer's chat
// are picked.
async function sharePdf() {
  shareError.value = ''

  try {
    await navigator.share({ files: [pdf.value], text: billCaption(bill.value, shop.value) })
  } catch (e) {
    // Closing the share menu without picking an app is not a problem.
    if (e.name !== 'AbortError') {
      shareError.value = `Could not open the share menu: ${e.message}`
    }
  }
}

// Back to wherever the bill was opened from, such as the bills list or a customer.
function goBack() {
  if (window.history.state?.back) {
    router.back()
  } else {
    router.push({ name: 'bills', query: store.listQuery })
  }
}

function print() {
  window.print()
}

watch(() => props.id, load, { immediate: true })
</script>

<template>
  <div class="page-header no-print">
    <button type="button" class="btn" @click="goBack">← Back</button>
    <div v-if="bill" class="actions">
      <button type="button" class="btn" @click="print">Print</button>
      <button type="button" class="btn" :disabled="!pdf" @click="downloadFile(pdf)">
        Download PDF
      </button>
      <!-- Only where the device can hand files to other apps, such as phones. -->
      <button v-if="canShare" type="button" class="btn" @click="sharePdf">Share PDF</button>
      <a class="btn" :href="whatsapp" target="_blank" rel="noopener">Send on WhatsApp</a>
      <RouterLink class="btn btn-primary" :to="{ name: 'bill-create' }">New bill</RouterLink>
    </div>
  </div>

  <div v-if="pdfError" class="alert-error load-error notice no-print" role="alert">
    <span>
      Could not get the PDF for download or sharing: {{ pdfError }} Send on WhatsApp still works;
      its link makes the PDF when the customer opens it.
    </span>
    <button type="button" class="btn" @click="preparePdf">Try again</button>
  </div>
  <p v-if="shareError" class="alert-error notice no-print" role="alert">{{ shareError }}</p>

  <p v-if="loading">Loading…</p>

  <div v-else-if="error" class="alert-error load-error" role="alert">
    <span>{{ error }}</span>
    <RouterLink v-if="notFound" class="btn" :to="{ name: 'bills' }">See all bills</RouterLink>
    <button v-else type="button" class="btn" @click="load">Try again</button>
  </div>

  <template v-else-if="bill">
    <p v-if="justSaved" class="alert-success saved no-print" role="status">
      Bill {{ bill.bill_number }} is saved. Print it, or send it to {{ bill.customer.name }} on
      WhatsApp.
    </p>

    <article class="sheet">
      <header class="sheet-head">
        <div class="shop">
          <h1 class="shop-name">{{ shop.name }}</h1>
          <p v-if="shop.address">{{ shop.address }}</p>
          <p v-if="shop.phone">Phone: {{ shop.phone }}</p>
        </div>

        <div class="meta">
          <p class="doc-title">Bill</p>
          <dl>
            <div>
              <dt>Bill number <InfoTip term="Bill number" class="no-print" /></dt>
              <dd class="mono">{{ bill.bill_number }}</dd>
            </div>
            <div>
              <dt>Date</dt>
              <dd>{{ formatDateTime(bill.created_at) }}</dd>
            </div>
          </dl>
        </div>
      </header>

      <div class="bill-to">
        <div>
          <span class="label">Billed to</span>
          <strong class="customer">{{ bill.customer.name }}</strong>
          <span>{{ formatMobile(bill.customer.mobile) }}</span>
        </div>
        <span class="badge" :class="statusClass(bill.status)">{{ bill.status.label }}</span>
      </div>

      <div class="items-wrap">
        <table class="items">
          <thead>
            <tr>
              <th scope="col" class="index">#</th>
              <th scope="col">Item</th>
              <th scope="col" class="num">Qty</th>
              <th scope="col" class="num">Price</th>
              <th scope="col" class="num">Amount</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, index) in bill.items" :key="item.id">
              <td class="index">{{ index + 1 }}</td>
              <td class="item">
                {{ item.product_name
                }}<span v-if="item.variant_name" class="size">{{ ` · ${item.variant_name}` }}</span>
              </td>
              <td class="num">{{ itemQuantity(item) }}</td>
              <td class="num">{{ formatMoney(item.price) }}</td>
              <td class="num">{{ formatMoney(item.line_total) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="sheet-foot">
        <section class="payments" aria-labelledby="payments-title">
          <h2 id="payments-title">Payments</h2>
          <ul v-if="bill.payments.length">
            <li v-for="payment in bill.payments" :key="payment.id">
              <span class="how">
                {{ payment.method.label
                }}<template v-if="payment.reference">{{ ` · ${payment.reference}` }}</template>
              </span>
              <span class="when">{{ formatDateTime(payment.received_at) }}</span>
              <strong>{{ formatMoney(payment.amount) }}</strong>
            </li>
          </ul>
          <p v-else class="none">Nothing paid yet.</p>
        </section>

        <dl class="sums">
          <div>
            <dt>Total</dt>
            <dd>{{ formatMoney(bill.total) }}</dd>
          </div>
          <div>
            <dt>Paid</dt>
            <dd>{{ formatMoney(bill.paid_amount) }}</dd>
          </div>
          <div class="due" :class="{ owing: bill.due_amount > 0 }">
            <dt>Due <InfoTip term="Due" class="no-print" /></dt>
            <dd>{{ formatMoney(bill.due_amount) }}</dd>
          </div>
        </dl>
      </div>

      <p class="thanks">Thank you for shopping with us!</p>
    </article>

    <p class="account no-print">
      <RouterLink :to="{ name: 'customer-show', params: { id: bill.customer.id } }">
        Open {{ bill.customer.name }}’s account
      </RouterLink>
      to take a payment towards what is due or see all their bills.
    </p>
  </template>
</template>

<style scoped>
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.saved,
.notice {
  margin-bottom: 1rem;
}

/* The bill looks like the paper it prints on, light whatever the screen theme. */
.sheet {
  --color-background: #fff;
  --color-background-soft: #f7f7f7;
  --color-background-mute: #f0f0f0;
  --color-border: #d9d9d9;
  --color-border-hover: #9a9a9a;
  --color-heading: #111;
  --color-text: #2a2a2a;
  --color-danger: hsl(0, 70%, 42%);
  --color-success: hsl(160, 100%, 25%);
  --color-warning: hsl(35, 100%, 30%);

  max-width: 210mm;
  margin: 0 auto;
  padding: 2rem 2.25rem;
  color: var(--color-text);
  background: var(--color-background);
  border: 1px solid var(--color-border);
  border-radius: 4px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.12);
}

.sheet-head {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: 1rem 2rem;
  padding-bottom: 1rem;
  border-bottom: 2px solid var(--color-heading);
}

.shop-name {
  font-size: 1.5rem;
  font-weight: 700;
  line-height: 1.25;
  color: var(--color-heading);
}

.shop p {
  font-size: 0.9rem;
}

.meta {
  text-align: right;
}

.doc-title {
  font-size: 1.1rem;
  font-weight: 700;
  letter-spacing: 0.15em;
  text-transform: uppercase;
  color: var(--color-heading);
}

.meta dl {
  margin: 0.25rem 0 0;
}

.meta dl div {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 0.5rem;
}

.meta dt {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.85rem;
  opacity: 0.8;
}

.meta dd {
  margin: 0;
  color: var(--color-heading);
}

.bill-to {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: 0.75rem;
  margin: 1rem 0 1.25rem;
}

.bill-to > div {
  display: flex;
  flex-direction: column;
}

.label {
  font-size: 0.8rem;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  opacity: 0.75;
}

.customer {
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--color-heading);
}

.items-wrap {
  overflow-x: auto;
}

.items {
  width: 100%;
  border-collapse: collapse;
}

.items th,
.items td {
  padding: 0.5rem 0.6rem;
  text-align: left;
  vertical-align: top;
  border-bottom: 1px solid var(--color-border);
}

.items th {
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--color-heading);
  background: var(--color-background-soft);
  border-bottom-color: var(--color-border-hover);
}

.items .num {
  text-align: right;
  white-space: nowrap;
}

.index {
  width: 2.5rem;
  opacity: 0.75;
}

.item {
  color: var(--color-heading);
}

.size {
  opacity: 0.8;
}

.sheet-foot {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1.25rem 2rem;
  margin-top: 1.25rem;
}

.payments {
  flex: 1 1 18rem;
}

.payments h2 {
  margin-bottom: 0.4rem;
  font-size: 0.85rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-heading);
}

.payments ul {
  margin: 0;
  padding: 0;
  list-style: none;
}

.payments li {
  display: flex;
  flex-wrap: wrap;
  gap: 0 0.75rem;
  padding: 0.2rem 0;
  font-size: 0.9rem;
}

.how {
  min-width: 7rem;
  color: var(--color-heading);
}

.when {
  flex: 1;
  opacity: 0.75;
}

.none {
  font-size: 0.9rem;
  opacity: 0.8;
}

.sums {
  flex: 0 1 16rem;
  margin: 0;
}

.sums div {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: 0.3rem 0;
}

.sums dt {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
}

.sums dd {
  margin: 0;
  font-weight: 600;
  color: var(--color-heading);
}

.sums div:first-child {
  font-size: 1.15rem;
  border-bottom: 1px solid var(--color-border);
}

.sums div:first-child dd {
  font-weight: 700;
}

.sums .owing dd,
.sums .owing dt {
  color: var(--color-danger);
}

.thanks {
  margin-top: 2rem;
  font-size: 0.9rem;
  text-align: center;
  opacity: 0.8;
}

.account {
  max-width: 210mm;
  margin: 1rem auto 0;
  font-size: 0.9rem;
}

@media (max-width: 640px) {
  .sheet {
    padding: 1.25rem 1rem;
  }

  .meta {
    text-align: left;
  }

  .meta dl div {
    justify-content: flex-start;
  }

  /* Room for the item names: tighter cells, and the line numbers are left out. */
  .items th,
  .items td {
    padding: 0.45rem 0.3rem;
  }

  .index {
    display: none;
  }

  .sums {
    flex-basis: 100%;
  }
}

@media print {
  .sheet {
    max-width: none;
    padding: 0;
    border: 0;
    border-radius: 0;
    box-shadow: none;
  }
}
</style>
