<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useBillStore } from '@/stores/bills'
import { formatMoney, formatQuantity } from '@/utils/format'
import {
  PAYMENT_METHODS,
  itemName,
  itemQuantity,
  lineTotal,
  returnPayload,
  returnRow,
  returnSettlement,
  returnTotal,
  returnableQuantity,
} from '@/utils/billing'
import FieldLabel from '@/components/FieldLabel.vue'
import InfoTip from '@/components/InfoTip.vue'

// Takes items back from a bill. What they were worth comes off what is still due on the bill
// first; anything beyond that is given back.
const props = defineProps({
  bill: { type: Object, required: true },
})

const emit = defineEmits(['close', 'done'])

const store = useBillStore()

const dialog = ref(null)
const saving = ref(false)
const error = ref('')
// Messages for each row, by its key, and for the refund.
const rowErrors = ref({})
const refundError = ref('')

const rows = ref(props.bill.items.filter((item) => returnableQuantity(item) > 0).map(returnRow))
const note = ref('')
const refund = reactive({ method: 'cash', reference: '' })

const amount = computed(() => returnTotal(rows.value))
const settlement = computed(() => returnSettlement(props.bill, amount.value))

function unitOf(item) {
  return { short_name: item.unit }
}

onMounted(() => dialog.value.showModal())

// Escape must not dismiss the form while a save is still in flight.
function onCancel(event) {
  if (saving.value) {
    event.preventDefault()
  }
}

async function submit() {
  error.value = ''
  rowErrors.value = {}
  refundError.value = ''

  // Only the rows with a quantity are sent, so the server's messages are matched back by order.
  const sent = rows.value.filter((row) => Number(row.quantity) > 0)

  if (sent.length === 0) {
    error.value = 'Enter how many of each item came back.'
    return
  }

  saving.value = true

  try {
    const payload = returnPayload(
      rows.value,
      note.value,
      settlement.value.refund > 0 ? refund : null,
    )

    emit('done', await store.createReturn(props.bill.id, payload))
  } catch (e) {
    const errors = e.errors ?? {}

    sent.forEach((row, index) => {
      const message =
        errors[`items.${index}.quantity`]?.[0] ?? errors[`items.${index}.bill_item_id`]?.[0]

      if (message) {
        rowErrors.value[row.key] = message
      }
    })

    refundError.value = errors['refund.method']?.[0] ?? ''

    // A message beside a row or the refund says it all; otherwise it shows at the top.
    const shownBelow = Object.keys(rowErrors.value).length > 0 || refundError.value !== ''
    error.value = errors.items?.[0] ?? (shownBelow ? '' : e.message)
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <dialog ref="dialog" class="dialog return-dialog" @cancel="onCancel" @close="emit('close')">
    <form @submit.prevent="submit">
      <h2>Return items from {{ bill.bill_number }}</h2>
      <p class="intro">Enter how many of each came back. They go back into stock.</p>

      <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

      <div class="table-wrap">
        <table class="data-table return-table">
          <thead>
            <tr>
              <th scope="col">Item</th>
              <th scope="col" class="num">Coming back</th>
              <th scope="col" class="num">Worth</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.key">
              <td class="item-cell">
                <span class="name">{{ itemName(row.item) }}</span>
                <span class="details">
                  {{ itemQuantity(row.item) }} bought at {{ formatMoney(row.item.price)
                  }}{{
                    row.item.returned_quantity > 0
                      ? `, ${formatQuantity(row.item.returned_quantity, unitOf(row.item))} already back`
                      : ''
                  }}
                </span>
              </td>
              <td class="num qty-cell">
                <div class="with-unit">
                  <input
                    v-model="row.quantity"
                    class="input"
                    type="number"
                    min="0"
                    :max="returnableQuantity(row.item)"
                    :step="row.item.current?.allow_decimal === false ? 1 : 'any'"
                    placeholder="0"
                    :aria-label="`How many ${itemName(row.item)} came back`"
                  />
                  <span class="unit">{{ row.item.unit }}</span>
                </div>
                <p v-if="rowErrors[row.key]" class="field-error">{{ rowErrors[row.key] }}</p>
                <p v-else class="hint">
                  up to {{ formatQuantity(returnableQuantity(row.item), unitOf(row.item)) }}
                </p>
              </td>
              <td class="num">
                {{ formatMoney(lineTotal({ quantity: row.quantity, price: row.item.price })) }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <dl class="settle">
        <div>
          <dt>Worth</dt>
          <dd>{{ formatMoney(amount) }}</dd>
        </div>
        <div v-if="settlement.dueReduced > 0">
          <dt>Off the due <InfoTip term="Due" /></dt>
          <dd>{{ formatMoney(settlement.dueReduced) }}</dd>
        </div>
        <div v-if="settlement.refund > 0" class="give-back">
          <dt>To give back</dt>
          <dd>{{ formatMoney(settlement.refund) }}</dd>
        </div>
      </dl>

      <template v-if="settlement.refund > 0">
        <fieldset class="choice-group">
          <legend>Given back by</legend>
          <label v-for="method in PAYMENT_METHODS" :key="method.value" class="choice">
            <input
              v-model="refund.method"
              type="radio"
              name="return_refund_method"
              :value="method.value"
            />
            {{ method.label }}
            <InfoTip v-if="method.value === 'upi'" term="UPI" />
          </label>
        </fieldset>
        <div v-if="refund.method !== 'cash'" class="field">
          <FieldLabel input-id="return-reference" term="Transaction number">
            Transaction number <span class="optional">optional</span>
          </FieldLabel>
          <input
            id="return-reference"
            v-model="refund.reference"
            class="input"
            type="text"
            maxlength="100"
            autocomplete="off"
          />
        </div>
        <p v-if="refundError" class="field-error">{{ refundError }}</p>
      </template>

      <div class="field">
        <label for="return-note">Note <span class="optional">optional</span></label>
        <input
          id="return-note"
          v-model="note"
          class="input"
          type="text"
          maxlength="255"
          autocomplete="off"
          placeholder="e.g. Wrong size"
        />
      </div>

      <div class="dialog-actions">
        <button type="button" class="btn" :disabled="saving" @click="emit('close')">Cancel</button>
        <button type="submit" class="btn btn-primary" :disabled="saving">
          {{ saving ? 'Saving…' : 'Take back items' }}
        </button>
      </div>
    </form>
  </dialog>
</template>

<style scoped>
.return-dialog {
  width: min(640px, calc(100vw - 2rem));
}

.intro {
  margin: -0.5rem 0 1rem;
  font-size: 0.9rem;
  opacity: 0.85;
}

.alert-error {
  margin-bottom: 1rem;
}

.return-table td {
  vertical-align: top;
}

.data-table td.item-cell {
  min-width: 12rem;
  white-space: normal;
}

.name {
  display: block;
  color: var(--color-heading);
}

.details,
.hint {
  font-size: 0.8rem;
  opacity: 0.8;
}

.qty-cell {
  min-width: 8rem;
}

.with-unit {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 0.35rem;
}

.with-unit .input {
  width: 5.5rem;
  padding: 0.4rem 0.5rem;
  text-align: right;
}

.unit {
  min-width: 1.5rem;
  font-size: 0.85rem;
  opacity: 0.8;
}

.num {
  text-align: right;
}

.field-error,
.hint {
  margin-top: 0.25rem;
}

.settle {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 2rem;
  margin: 1rem 0;
}

.settle div {
  display: flex;
  flex-direction: column;
}

.settle dt {
  display: inline-flex;
  align-items: center;
  gap: 0.3rem;
  font-size: 0.85rem;
  opacity: 0.85;
}

.settle dd {
  margin: 0;
  font-size: 1.15rem;
  font-weight: 600;
  color: var(--color-heading);
}

.give-back dd {
  color: var(--color-danger);
}

.optional {
  margin-left: 0.25rem;
  font-size: 0.8rem;
  font-weight: normal;
  opacity: 0.7;
}
</style>
