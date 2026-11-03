<script setup>
import { computed, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useCustomerStore } from '@/stores/customers'
import { formatDate, formatDateTime, formatMoney } from '@/utils/format'
import {
  dueReminderMessage,
  formatMobile,
  methodLabel,
  paymentReceiptMessage,
  statusClass,
  whatsappLink,
} from '@/utils/billing'
import InfoTip from '@/components/InfoTip.vue'
import ReceivePaymentDialog from '@/components/ReceivePaymentDialog.vue'

const props = defineProps({
  id: { type: String, required: true },
})

const store = useCustomerStore()
const router = useRouter()

const customer = ref(null)
const shop = ref(null)
const loading = ref(true)
const error = ref('')
const notFound = ref(false)

const receiving = ref(false)
// The last payment taken on this page, with the bills it went towards.
const received = ref(null)

const paid = computed(() => customer.value.total_billed - customer.value.total_due)
const dueBills = computed(() => customer.value.bills.filter((bill) => bill.due_amount > 0).length)

const reminderLink = computed(() =>
  whatsappLink(customer.value.mobile, dueReminderMessage(customer.value, shop.value)),
)
const receiptLink = computed(() =>
  whatsappLink(
    customer.value.mobile,
    paymentReceiptMessage(customer.value, received.value.payment, shop.value),
  ),
)

const list = new Intl.ListFormat('en-IN', { style: 'long', type: 'conjunction' })

// "INV-2026-0001 (₹200) and INV-2026-0002 (₹200)"
const appliedTo = computed(() =>
  list.format(
    received.value.appliedTo.map((part) => `${part.bill_number} (${formatMoney(part.amount)})`),
  ),
)

async function load() {
  loading.value = true
  error.value = ''

  try {
    const result = await store.fetchOne(props.id)

    customer.value = result.customer
    shop.value = result.shop
  } catch (e) {
    notFound.value = e.status === 404
    error.value = notFound.value ? 'This customer does not exist.' : e.message
  } finally {
    loading.value = false
  }
}

function onReceived(result) {
  customer.value = result.customer
  shop.value = result.shop
  received.value = { payment: result.payment, appliedTo: result.appliedTo }
  receiving.value = false
}

// Back to wherever the customer was opened from, such as the list or a bill.
function goBack() {
  if (window.history.state?.back) {
    router.back()
  } else {
    router.push({ name: 'customers', query: store.listQuery })
  }
}

watch(
  () => props.id,
  () => {
    received.value = null
    load()
  },
  { immediate: true },
)
</script>

<template>
  <div class="customer-page">
    <div class="page-header">
      <button type="button" class="btn" @click="goBack">← Back</button>
      <div v-if="customer" class="actions">
        <template v-if="customer.total_due > 0">
          <a class="btn remind" :href="reminderLink" target="_blank" rel="noopener">
            Remind on WhatsApp
          </a>
          <button type="button" class="btn" @click="receiving = true">Receive payment</button>
        </template>
        <RouterLink
          class="btn btn-primary"
          :to="{ name: 'bill-create', query: { mobile: customer.mobile } }"
        >
          New bill
        </RouterLink>
      </div>
    </div>

    <p v-if="loading">Loading…</p>

    <div v-else-if="error" class="alert-error load-error" role="alert">
      <span>{{ error }}</span>
      <RouterLink v-if="notFound" class="btn" :to="{ name: 'customers' }">
        See all customers
      </RouterLink>
      <button v-else type="button" class="btn" @click="load">Try again</button>
    </div>

    <template v-else-if="customer">
      <h1 class="page-title">{{ customer.name }}</h1>
      <p class="subtitle">
        {{ [formatMobile(customer.mobile), customer.address].filter(Boolean).join(' · ') }} ·
        customer since {{ formatDate(customer.created_at) }}
      </p>

      <div v-if="received" class="alert-success received" role="status">
        <span>
          Received {{ formatMoney(received.payment.amount) }} by
          {{ methodLabel(received.payment.method) }}, put towards {{ appliedTo }}.
        </span>
        <a :href="receiptLink" target="_blank" rel="noopener">Send a receipt on WhatsApp</a>
      </div>

      <section class="stats" aria-label="Account">
        <div class="stat">
          <span class="stat-label">Bills</span>
          <span class="stat-value">{{ customer.bills_count }}</span>
        </div>
        <div class="stat">
          <span class="stat-label">Total billed</span>
          <span class="stat-value">{{ formatMoney(customer.total_billed) }}</span>
          <span class="stat-note">{{ formatMoney(paid) }} paid</span>
        </div>
        <div class="stat" :class="{ 'stat-owing': customer.total_due > 0 }">
          <span class="stat-label">Due <InfoTip term="Due" /></span>
          <span class="stat-value">{{ formatMoney(customer.total_due) }}</span>
          <span class="stat-note">
            {{
              customer.total_due > 0
                ? `on ${dueBills} ${dueBills === 1 ? 'bill' : 'bills'}`
                : 'Nothing owed'
            }}
          </span>
        </div>
      </section>

      <section class="block" aria-labelledby="bills-title">
        <h2 id="bills-title">Bills</h2>

        <p v-if="customer.bills.length === 0" class="empty">No bills yet.</p>

        <div v-else class="table-wrap">
          <table class="data-table stack">
            <thead>
              <tr>
                <th scope="col">Bill number <InfoTip term="Bill number" /></th>
                <th scope="col">Date</th>
                <th scope="col" class="num">Total</th>
                <th scope="col" class="num">Paid</th>
                <th scope="col" class="num">Due <InfoTip term="Due" /></th>
                <th scope="col">Status</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="bill in customer.bills" :key="bill.id">
                <td class="cell-title">
                  <RouterLink class="mono" :to="{ name: 'bill-show', params: { id: bill.id } }">
                    {{ bill.bill_number }}
                  </RouterLink>
                </td>
                <td class="cell-date" data-label="Date">{{ formatDateTime(bill.created_at) }}</td>
                <td class="num cell-total" data-label="Total">{{ formatMoney(bill.total) }}</td>
                <td class="num cell-paid" data-label="Paid">{{ formatMoney(bill.paid_amount) }}</td>
                <td class="num cell-due" :class="{ owing: bill.due_amount > 0 }">
                  <span class="cell-label">Due <InfoTip term="Due" /></span>
                  {{ formatMoney(bill.due_amount) }}
                </td>
                <td class="cell-status" data-label="Status">
                  <span class="badge" :class="statusClass(bill.status)">
                    {{ bill.status.label }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section class="block" aria-labelledby="payments-title">
        <h2 id="payments-title">Payments</h2>

        <p v-if="customer.payments.length === 0" class="empty">No payments yet.</p>

        <div v-else class="table-wrap">
          <table class="data-table stack">
            <thead>
              <tr>
                <th scope="col">Date</th>
                <th scope="col">For bill</th>
                <th scope="col">Paid by</th>
                <th scope="col">Transaction number <InfoTip term="Transaction number" /></th>
                <th scope="col" class="num">Amount</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="payment in customer.payments" :key="payment.id">
                <td class="cell-title cell-when">{{ formatDateTime(payment.received_at) }}</td>
                <td class="cell-bill" data-label="For bill">
                  <RouterLink
                    class="mono"
                    :to="{ name: 'bill-show', params: { id: payment.bill.id } }"
                  >
                    {{ payment.bill.bill_number }}
                  </RouterLink>
                </td>
                <td class="cell-method" data-label="Paid by">
                  {{ payment.method.label
                  }}<span v-if="payment.reference" class="phone-only">{{
                    ` · ${payment.reference}`
                  }}</span>
                </td>
                <td class="mono cell-ref">
                  <span class="cell-label">
                    Transaction number <InfoTip term="Transaction number" />
                  </span>
                  {{ payment.reference ?? '—' }}
                </td>
                <td class="num cell-amount" data-label="Amount">
                  {{ formatMoney(payment.amount) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </template>

    <ReceivePaymentDialog
      v-if="receiving"
      :owed-text="`${customer.name} owes`"
      :owed="customer.total_due"
      note="Their oldest bills are cleared first."
      :save="(payment) => store.receivePayment(customer.id, payment)"
      @received="onReceived"
      @close="receiving = false"
    />
  </div>
</template>

<style scoped>
.actions {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
}

.subtitle {
  margin: 0.15rem 0 1.25rem;
  opacity: 0.85;
}

.received {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  margin-bottom: 1.25rem;
}

.received a {
  font-weight: 600;
}

.stat-owing .stat-value {
  color: var(--color-danger);
}

.block {
  margin-top: 1.75rem;
}

.block h2 {
  margin-bottom: 0.75rem;
  font-size: 1.15rem;
  font-weight: 600;
  color: var(--color-heading);
}

.empty {
  opacity: 0.85;
}

.num {
  text-align: right;
}

.owing {
  font-weight: 600;
  color: var(--color-danger);
}

/* Only on a phone, where the payment card puts the transaction number after the method. */
.phone-only {
  display: none;
}

/*
 * On a phone: the name and details come first, then the buttons (Receive payment and New bill
 * side by side, Remind on WhatsApp under them), three small account figures in a row, and slim
 * cards for the bills and payments. A card opens its bill when tapped.
 */
@media (max-width: 640px) {
  .customer-page {
    display: flex;
    flex-direction: column;
  }

  .page-header {
    display: contents;
  }

  .page-header > .btn {
    align-self: flex-start;
    margin-bottom: 1rem;
  }

  .page-title {
    order: 1;
  }

  .subtitle {
    order: 2;
    margin-bottom: 1rem;
  }

  .actions {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    order: 3;
    margin-bottom: 1.25rem;
  }

  .actions > .btn {
    justify-content: center;
    padding-inline: 0.5rem;
    text-align: center;
    white-space: nowrap;
  }

  .actions > .remind {
    grid-column: 1 / -1;
    order: 1;
  }

  .actions > :only-child {
    grid-column: 1 / -1;
  }

  .customer-page > :not(.page-header, .page-title, .subtitle) {
    order: 4;
  }

  /* The bill count needs less room than the two amounts. */
  .stats {
    grid-template-columns: minmax(0, 0.7fr) repeat(2, minmax(0, 1fr));
    gap: 0.5rem;
  }

  .stat {
    padding: 0.7rem 0.75rem;
  }

  .stat-value {
    font-size: 1.05rem;
    overflow-wrap: anywhere;
  }

  .stat-note {
    font-size: 0.75rem;
  }

  .block {
    margin-top: 1.25rem;
  }

  .data-table.stack tr {
    position: relative;
    gap: 0.3rem 0.75rem;
    padding: 0.8rem 1rem;
  }

  /* A bill: number and status, when, then Total, Paid and Due side by side. The last column
     takes the status's width, so "Partly paid" stays on one line. */
  .data-table.stack tr:has(td.cell-status) {
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto;
    grid-template-areas:
      'number number status'
      'date date date'
      'total paid due';
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

  .data-table.stack td.cell-status .badge {
    white-space: nowrap;
  }

  .data-table.stack td.cell-date {
    grid-area: date;
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

  /* A payment: the amount and how it was paid, then when and for which bill. */
  .data-table.stack tr:has(td.cell-amount) {
    grid-template-columns: minmax(0, 1fr) auto;
    grid-template-areas:
      'amount method'
      'when bill';
  }

  .data-table.stack td.cell-amount {
    grid-area: amount;
    font-size: 1.05rem;
    font-weight: 600;
    color: var(--color-heading);
  }

  .data-table.stack td.cell-method {
    grid-area: method;
    align-self: center;
    text-align: right;
  }

  .data-table.stack td.cell-when {
    grid-area: when;
    font-weight: normal;
  }

  .data-table.stack td.cell-bill {
    grid-area: bill;
    text-align: right;
  }

  .data-table.stack td.cell-ref {
    display: none;
  }

  .phone-only {
    display: inline;
  }

  /* These speak for themselves, so they go without a name. */
  .data-table.stack td.cell-status::before,
  .data-table.stack td.cell-date::before,
  .data-table.stack td.cell-amount::before,
  .data-table.stack td.cell-method::before,
  .data-table.stack td.cell-bill::before {
    content: none;
  }

  .data-table.stack td.cell-date,
  .data-table.stack td.cell-when,
  .data-table.stack td.cell-bill {
    font-size: 0.85rem;
    opacity: 0.8;
  }

  /* The whole card opens the bill (above the faded lines, whose opacity gives them a layer of
     their own); the faded "Due" label is lifted whole to keep its "i" on top. */
  .data-table.stack td.cell-title a::after,
  .data-table.stack td.cell-bill a::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
  }

  .data-table.stack td.cell-due .cell-label {
    position: relative;
    z-index: 2;
  }
}
</style>
