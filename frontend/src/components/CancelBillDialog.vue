<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useBillStore } from '@/stores/bills'
import { formatMoney } from '@/utils/format'
import { PAYMENT_METHODS, cancelPayload, netPaid } from '@/utils/billing'
import FieldLabel from '@/components/FieldLabel.vue'
import InfoTip from '@/components/InfoTip.vue'

// Cancels a bill: its items go back into stock and anything paid on it is given back.
const props = defineProps({
  bill: { type: Object, required: true },
})

const emit = defineEmits(['close', 'done'])

const store = useBillStore()

const dialog = ref(null)
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

const reason = ref('')
const refund = reactive({ method: 'cash', reference: '' })
const paid = computed(() => netPaid(props.bill))

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

  try {
    emit(
      'done',
      await store.cancel(
        props.bill.id,
        cancelPayload(reason.value, paid.value > 0 ? refund : null),
      ),
    )
  } catch (e) {
    fieldErrors.value = e.errors ?? {}
    error.value =
      fieldErrors.value.bill?.[0] ?? (Object.keys(fieldErrors.value).length ? '' : e.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <dialog ref="dialog" class="dialog" @cancel="onCancel" @close="emit('close')">
    <form @submit.prevent="submit">
      <h2>Cancel {{ bill.bill_number }}</h2>
      <p class="what">
        Everything on it goes back into stock{{
          bill.returned_amount > 0 ? ' (apart from what was already returned)' : ''
        }}, and it stops counting as a sale and as money due. It stays in your bills, marked
        cancelled. This cannot be undone.
      </p>

      <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

      <div class="field">
        <label for="cancel-reason">Why is it being cancelled?</label>
        <input
          id="cancel-reason"
          v-model="reason"
          class="input"
          type="text"
          maxlength="255"
          autocomplete="off"
          placeholder="e.g. Made by mistake"
          required
          autofocus
        />
        <p v-if="fieldErrors.reason" class="field-error">{{ fieldErrors.reason[0] }}</p>
      </div>

      <template v-if="paid > 0">
        <p class="give-back">{{ formatMoney(paid) }} was paid on this bill. Give it back by:</p>
        <fieldset class="choice-group">
          <legend class="visually-hidden">Given back by</legend>
          <label v-for="method in PAYMENT_METHODS" :key="method.value" class="choice">
            <input
              v-model="refund.method"
              type="radio"
              name="cancel_refund_method"
              :value="method.value"
            />
            {{ method.label }}
            <InfoTip v-if="method.value === 'upi'" term="UPI" />
          </label>
        </fieldset>
        <div v-if="refund.method !== 'cash'" class="field">
          <FieldLabel input-id="cancel-reference" term="Transaction number">
            Transaction number <span class="optional">optional</span>
          </FieldLabel>
          <input
            id="cancel-reference"
            v-model="refund.reference"
            class="input"
            type="text"
            maxlength="100"
            autocomplete="off"
          />
        </div>
        <p v-if="fieldErrors['refund.method']" class="field-error">
          {{ fieldErrors['refund.method'][0] }}
        </p>
      </template>

      <div class="dialog-actions">
        <button type="button" class="btn" :disabled="saving" @click="emit('close')">
          Keep the bill
        </button>
        <button type="submit" class="btn btn-danger" :disabled="saving">
          {{ saving ? 'Cancelling…' : 'Cancel bill' }}
        </button>
      </div>
    </form>
  </dialog>
</template>

<style scoped>
.what {
  margin: -0.5rem 0 1rem;
  font-size: 0.9rem;
}

.alert-error {
  margin-bottom: 1rem;
}

.give-back {
  margin-bottom: 0.5rem;
  font-weight: 500;
  color: var(--color-heading);
}

.optional {
  margin-left: 0.25rem;
  font-size: 0.8rem;
  font-weight: normal;
  opacity: 0.7;
}
</style>
