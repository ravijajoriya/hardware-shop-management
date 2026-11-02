<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { formatMoney } from '@/utils/format'
import { PAYMENT_METHODS, duesPayment, toPaise } from '@/utils/billing'
import FieldLabel from '@/components/FieldLabel.vue'
import InfoTip from '@/components/InfoTip.vue'

// Takes a payment towards what is due: on one bill, or across all of a customer's bills.
const props = defineProps({
  title: { type: String, default: 'Receive payment' },
  // Leads into the amount owed: "Ramesh Kumar owes" or "Due on this bill:".
  owedText: { type: String, required: true },
  owed: { type: Number, required: true },
  // Where the money goes, such as "Their oldest bills are cleared first."
  note: { type: String, required: true },
  // Saves the payment and resolves with what the page needs, or rejects with the server's error.
  save: { type: Function, required: true },
})

const emit = defineEmits(['close', 'received'])

const dialog = ref(null)
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

// Most customers clear everything at once, so the whole amount owed is filled in.
const form = reactive({ method: 'cash', amount: props.owed, reference: '' })

const left = computed(() => Math.max(toPaise(props.owed) - toPaise(form.amount), 0) / 100)

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
    const result = await props.save(payment)

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
      <h2>{{ title }}</h2>
      <p class="owed">
        {{ owedText }} <strong>{{ formatMoney(owed) }}</strong>
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
          :max="owed"
          required
          autofocus
        />
        <p v-if="fieldErrors.amount" class="field-error">{{ fieldErrors.amount[0] }}</p>
        <p v-else class="hint">
          {{ left > 0 ? `${formatMoney(left)} will still be due.` : 'Nothing will be left due.' }}
          {{ note }}
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
