<script setup>
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useDashboardStore } from '@/stores/dashboard'
import { formatDate, formatMoney } from '@/utils/format'
import { moneyKept } from '@/utils/reports'
import InfoTip from '@/components/InfoTip.vue'
import WhereIsIt from '@/components/WhereIsIt.vue'

const auth = useAuthStore()
const store = useDashboardStore()

const loading = ref(false)
const error = ref('')

const today = computed(() => store.today)
const kept = computed(() => moneyKept(today.value.collected, today.value.refunded))

async function load() {
  loading.value = true
  error.value = ''

  try {
    await store.fetchToday()
  } catch (e) {
    error.value = e.message
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="page-header">
    <div>
      <h1 class="page-title">Dashboard</h1>
      <p v-if="auth.user" class="who">You are logged in as {{ auth.user.email }}.</p>
    </div>
    <RouterLink class="btn btn-primary" :to="{ name: 'bill-create' }">New bill</RouterLink>
  </div>

  <section class="today" aria-labelledby="today-title">
    <h2 id="today-title">
      Today
      <span v-if="today" class="date">{{ formatDate(`${today.date}T00:00:00`) }}</span>
    </h2>

    <div v-if="error" class="alert-error load-error" role="alert">
      <span>Could not load today’s figures: {{ error }}</span>
      <button type="button" class="btn" @click="load">Try again</button>
    </div>

    <p v-else-if="!today">Loading today’s figures…</p>

    <div v-else class="stats" :class="{ busy: loading }">
      <div class="stat">
        <span class="stat-label">Net sales <InfoTip term="Net sales" /></span>
        <span class="stat-value">{{ formatMoney(today.sales) }}</span>
        <RouterLink class="stat-note" :to="{ name: 'bills' }">
          {{ today.bills }} {{ today.bills === 1 ? 'bill' : 'bills' }} made
        </RouterLink>
        <span v-if="today.returns > 0" class="stat-note">
          after {{ formatMoney(today.returns) }} returned
        </span>
      </div>

      <div class="stat" :class="{ 'stat-owing': today.profit < 0 }">
        <span class="stat-label">Profit <InfoTip term="Profit" /></span>
        <span class="stat-value">{{ formatMoney(today.profit) }}</span>
        <span class="stat-note">
          Margin <InfoTip term="Margin" /> {{ today.margin === null ? '—' : `${today.margin}%`
          }}{{ today.cost_estimated ? ' (estimated)' : '' }}
        </span>
        <RouterLink class="stat-note" :to="{ name: 'reports' }">See the sales report</RouterLink>
      </div>

      <div class="stat">
        <span class="stat-label">Money received</span>
        <span class="stat-value">{{ formatMoney(kept.total) }}</span>
        <span class="stat-note">
          Cash {{ formatMoney(kept.cash) }} · Online {{ formatMoney(kept.online) }}
        </span>
        <span class="stat-note">
          UPI <InfoTip term="UPI" /> {{ formatMoney(kept.upi) }} · Bank
          {{ formatMoney(kept.bank_transfer) }}
        </span>
        <span v-if="today.refunded.total > 0" class="stat-note">
          {{ formatMoney(today.collected.total) }} in, {{ formatMoney(today.refunded.total) }}
          given back
        </span>
      </div>

      <div class="stat" :class="{ 'stat-owing': today.due.total > 0 }">
        <span class="stat-label">Due from customers <InfoTip term="Due" /></span>
        <span class="stat-value">{{ formatMoney(today.due.total) }}</span>
        <RouterLink
          v-if="today.due.customers > 0"
          class="stat-note"
          :to="{ name: 'customers', query: { with_due: '1' } }"
        >
          Owed by {{ today.due.customers }}
          {{ today.due.customers === 1 ? 'customer' : 'customers' }}, across all bills
        </RouterLink>
        <span v-else class="stat-note">Nobody owes anything</span>
      </div>
    </div>

    <p class="note">
      Money received counts every payment taken today, including dues paid for older bills, less
      anything given back.
    </p>
  </section>

  <WhereIsIt />
</template>

<style scoped>
.page-header {
  align-items: flex-start;
}

.who {
  font-size: 0.9rem;
  opacity: 0.85;
}

.today h2 {
  display: flex;
  align-items: baseline;
  gap: 0.6rem;
  margin-bottom: 0.75rem;
  font-size: 1.15rem;
  font-weight: 600;
  color: var(--color-heading);
}

.date {
  font-size: 0.9rem;
  font-weight: normal;
  opacity: 0.8;
}

.stats {
  margin-bottom: 0.5rem;
}

.stat-note {
  display: inline-flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.3rem;
}

a.stat-note {
  align-self: flex-start;
  padding: 0;
  opacity: 1;
}

.stat-owing .stat-value {
  color: var(--color-danger);
}

.busy {
  opacity: 0.6;
}

.note {
  font-size: 0.85rem;
  opacity: 0.75;
}
</style>
