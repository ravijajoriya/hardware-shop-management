<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useStockStore } from '@/stores/stock'
import { formatQuantity } from '@/utils/format'
import { CORRECTION_REASONS, correctionPayload, stockChange } from '@/utils/stock'

// Sets one size's stock to the count it really is, such as after counting the shelf.
const props = defineProps({
  productName: { type: String, required: true },
  // The size being corrected, with its stock as the count shows it.
  variant: { type: Object, required: true },
  unit: { type: Object, required: true },
})

const emit = defineEmits(['close', 'saved'])

const store = useStockStore()

const dialog = ref(null)
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

const form = reactive({ quantity: props.variant.stock_quantity, reason: 'counted', note: '' })

const change = computed(() => {
  const typed = Number(form.quantity)

  return String(form.quantity).trim() === '' || Number.isNaN(typed)
    ? null
    : Math.round((typed - Number(props.variant.stock_quantity)) * 1000) / 1000
})

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
    emit('saved', await store.correct(props.variant.id, correctionPayload(form)))
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
      <h2>Correct stock</h2>
      <p class="what">
        <strong>{{ productName }}</strong
        >{{ variant.name ? ` · ${variant.name}` : '' }}. The count shows
        {{ formatQuantity(variant.stock_quantity, unit) }}.
      </p>

      <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

      <div class="field">
        <label for="correct-quantity">Stock it really is now</label>
        <div class="with-unit">
          <input
            id="correct-quantity"
            v-model="form.quantity"
            class="input"
            type="number"
            min="0"
            :step="unit.allow_decimal ? 'any' : 1"
            required
            autofocus
          />
          <span class="unit">{{ unit.short_name }}</span>
        </div>
        <p v-if="fieldErrors.quantity" class="field-error">{{ fieldErrors.quantity[0] }}</p>
        <p v-else-if="change" class="hint">
          {{ stockChange(change, unit.short_name) }} from the count shown.
        </p>
      </div>

      <div class="field">
        <label for="correct-reason">Why</label>
        <select id="correct-reason" v-model="form.reason" class="input">
          <option v-for="reason in CORRECTION_REASONS" :key="reason.value" :value="reason.value">
            {{ reason.label }}
          </option>
        </select>
        <p v-if="fieldErrors.reason" class="field-error">{{ fieldErrors.reason[0] }}</p>
      </div>

      <div class="field">
        <label for="correct-note">
          Note <span v-if="form.reason !== 'other'" class="optional">optional</span>
        </label>
        <input
          id="correct-note"
          v-model="form.note"
          class="input"
          type="text"
          maxlength="255"
          autocomplete="off"
          :required="form.reason === 'other'"
          placeholder="e.g. 2 handles cracked"
        />
        <p v-if="fieldErrors.note" class="field-error">{{ fieldErrors.note[0] }}</p>
      </div>

      <p class="hint">The purchase price stays the same. This is kept in the stock history.</p>

      <div class="dialog-actions">
        <button type="button" class="btn" :disabled="saving" @click="emit('close')">Cancel</button>
        <button type="submit" class="btn btn-primary" :disabled="saving">
          {{ saving ? 'Saving…' : 'Save' }}
        </button>
      </div>
    </form>
  </dialog>
</template>

<style scoped>
.what {
  margin: -0.5rem 0 1rem;
}

.what strong {
  font-weight: 600;
  color: var(--color-heading);
}

.alert-error {
  margin-bottom: 1rem;
}

.with-unit {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.with-unit .input {
  max-width: 12rem;
}

.unit {
  opacity: 0.8;
}

.optional {
  margin-left: 0.25rem;
  font-size: 0.8rem;
  font-weight: normal;
  opacity: 0.7;
}

.hint {
  font-size: 0.85rem;
  opacity: 0.8;
}
</style>
