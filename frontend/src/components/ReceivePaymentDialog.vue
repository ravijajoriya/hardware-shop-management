<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useCustomerStore } from '@/stores/customers'
import { formatMoney } from '@/utils/format'
import { PAYMENT_METHODS, duesPayment, toPaise } from '@/utils/billing'
import FieldLabel from '@/components/FieldLabel.vue'
import InfoTip from '@/components/InfoTip.vue'

const props = defineProps({
  // The customer paying, with what they owe across all their bills.
  customer: { type: Object, required: true },
})

const emit = defineEmits(['close', 'received'])

const store = useCustomerStore()

const dialog = ref(null)
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

// Most customers clear everything at once, so the whole amount owed is filled in.
const form = reactive({ method: 'cash', amount: props.customer.total_due, reference: '' })

const left = computed(
  () => Math.max(toPaise(props.customer.total_due) - toPaise(form.amount), 0) / 100,
)

onMounted(() => dialog.value.showModal())

// Escape must not dismiss the form while a save is still in flight.
function onCancel(event) {
  if (saving.value) {
    event.preventDefault()
  }
}

async function submit() {
  saving.value = true
  error.value = ''
  fieldErrors.value = {}

  const payment = duesPayment(form)

  try {
    const result = await store.receivePayment(props.customer.id, payment)

    emit('received', { ...result, payment })
  } catch (e) {
    fieldErrors.value = e.errors ?? {}

    if (Object.keys(fieldErrors.value).length === 0) {
      error.value = e.message
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <dialog ref="dialog" class="dialog" @cancel="onCancel" @close="emit('close')">
    <form @submit.prevent="submit">
      <h2>Receive payment</h2>
      <p class="owed">
        {{ customer.name }} owes <strong>{{ formatMoney(customer.total_due) }}</strong>
        <InfoTip term="Due" />
      </p>

      <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

      <div class="field">
        <label for="payment-amount">Amount received</label>
        <input
          id="payment-amount"
          v-model="form.amount"
          class="input"
          type="number"
          min="0.01"
          step="0.01"
          :max="customer.total_due"
          required
          autofocus
        />
        <p v-if="fieldErrors.amount" class="field-error">{{ fieldErrors.amount[0] }}</p>
        <p v-else class="hint">
          {{ left > 0 ? `${formatMoney(left)} will still be due.` : 'This clears all they owe.' }}
          Their oldest bills are cleared first.
        </p>
      </div>

      <fieldset class="choice-group">
        <legend>Paid by</legend>
        <label v-for="method in PAYMENT_METHODS" :key="method.value" class="choice">
          <input v-model="form.method" type="radio" name="dues_method" :value="method.value" />
          {{ method.label }}
          <InfoTip v-if="method.value === 'upi'" term="UPI" />
        </label>
      </fieldset>
      <p v-if="fieldErrors.method" class="field-error">{{ fieldErrors.method[0] }}</p>

      <div v-if="form.method !== 'cash'" class="field">
        <FieldLabel input-id="payment-reference" term="Transaction number">
          Transaction number <span class="optional">optional</span>
        </FieldLabel>
        <input
          id="payment-reference"
          v-model="form.reference"
          class="input"
          type="text"
          maxlength="100"
          autocomplete="off"
        />
        <p v-if="fieldErrors.reference" class="field-error">{{ fieldErrors.reference[0] }}</p>
      </div>

      <div class="dialog-actions">
        <button type="button" class="btn" :disabled="saving" @click="emit('close')">Cancel</button>
        <button type="submit" class="btn btn-primary" :disabled="saving">
          {{ saving ? 'Saving…' : 'Save payment' }}
        </button>
      </div>
    </form>
  </dialog>
</template>

<style scoped>
.owed {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.35rem;
  margin: -0.5rem 0 1rem;
}

.owed strong {
  font-weight: 600;
  color: var(--color-danger);
}

.alert-error {
  margin-bottom: 1rem;
}

.optional {
  margin-left: 0.25rem;
  font-size: 0.8rem;
  font-weight: normal;
  opacity: 0.7;
}
</style>
