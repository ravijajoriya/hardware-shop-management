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

// "INV-2026-0001 (₹200.00) and INV-2026-0002 (₹200.00)"
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
  <div class="page-header">
    <button type="button" class="btn" @click="goBack">← Back</button>
    <div v-if="customer" class="actions">
      <template v-if="customer.total_due > 0">
        <a class="btn" :href="reminderLink" target="_blank" rel="noopener">Remind on WhatsApp</a>
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
      {{ formatMobile(customer.mobile) }} · customer since {{ formatDate(customer.created_at) }}
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
        <table class="data-table">
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
              <td>
                <RouterLink class="mono" :to="{ name: 'bill-show', params: { id: bill.id } }">
                  {{ bill.bill_number }}
                </RouterLink>
              </td>
              <td>{{ formatDateTime(bill.created_at) }}</td>
              <td class="num">{{ formatMoney(bill.total) }}</td>
              <td class="num">{{ formatMoney(bill.paid_amount) }}</td>
              <td class="num" :class="{ owing: bill.due_amount > 0 }">
                {{ formatMoney(bill.due_amount) }}
              </td>
              <td>
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
        <table class="data-table">
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
              <td>{{ formatDateTime(payment.received_at) }}</td>
              <td>
                <RouterLink
                  class="mono"
                  :to="{ name: 'bill-show', params: { id: payment.bill.id } }"
                >
                  {{ payment.bill.bill_number }}
                </RouterLink>
              </td>
              <td>{{ payment.method.label }}</td>
              <td class="mono">{{ payment.reference ?? '—' }}</td>
              <td class="num">{{ formatMoney(payment.amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>
  </template>

  <ReceivePaymentDialog
    v-if="receiving"
    :customer="customer"
    @received="onReceived"
    @close="receiving = false"
  />
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
</style>
