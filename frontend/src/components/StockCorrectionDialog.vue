<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useStockStore } from '@/stores/stock'
import { formatStock, joinStock, packWord, splitStock } from '@/utils/packs'
import { CORRECTION_REASONS, correctionPayload, stockChange } from '@/utils/stock'

// Sets one size's stock to the count it really is, such as after counting the shelf.
const props = defineProps({
  productName: { type: String, required: true },
  // The size being corrected, with its stock as the count shows it.
  variant: { type: Object, required: true },
  unit: { type: Object, required: true },
  // The pack it is bought in, if any: the shelf is then counted as full packs plus loose.
  pack: { type: Object, default: null },
})

const emit = defineEmits(['close', 'saved'])

const store = useStockStore()

const dialog = ref(null)
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

const shelf = props.pack ? splitStock(props.variant.stock_quantity, props.pack.size) : null
const form = reactive({
  quantity: props.variant.stock_quantity,
  // Counted as on the shelf for a product bought in packs: 5 coils and 85 m.
  packs: shelf?.packs ?? 0,
  loose: shelf?.loose ?? 0,
  reason: 'counted',
  note: '',
})

// What the count comes to in loose units, which is what the stock is kept in.
const counted = computed(() => {
  if (!props.pack) {
    const typed = String(form.quantity).trim()

    return typed === '' || Number.isNaN(Number(typed)) ? null : Number(typed)
  }

  return String(form.packs).trim() === '' && String(form.loose).trim() === ''
    ? null
    : joinStock(form.packs, form.loose, props.pack.size)
})

const change = computed(() =>
  counted.value === null
    ? null
    : Math.round((counted.value - Number(props.variant.stock_quantity)) * 1000) / 1000,
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

  try {
    emit(
      'saved',
      await store.correct(
        props.variant.id,
        correctionPayload({ ...form, quantity: counted.value }),
      ),
    )
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
        {{ formatStock(variant.stock_quantity, unit, pack) }}.
      </p>

      <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

      <fieldset v-if="pack" class="field shelf">
        <legend>Stock it really is now</legend>
        <div class="shelf-parts">
          <div class="with-unit">
            <input
              id="correct-packs"
              v-model="form.packs"
              class="input"
              type="number"
              min="0"
              step="1"
              required
              autofocus
              :aria-label="`Full ${packWord(pack.unit, 2)}`"
            />
            <span class="unit">{{ packWord(pack.unit, 2) }}</span>
          </div>
          <span class="plus" aria-hidden="true">+</span>
          <div class="with-unit">
            <input
              id="correct-loose"
              v-model="form.loose"
              class="input"
              type="number"
              min="0"
              :step="unit.allow_decimal ? 'any' : 1"
              :aria-label="`${unit.name} loose`"
            />
            <span class="unit">{{ unit.short_name }} loose</span>
          </div>
        </div>
        <p v-if="fieldErrors.quantity" class="field-error">{{ fieldErrors.quantity[0] }}</p>
        <p v-else-if="change" class="hint">
          {{ stockChange(change, unit.short_name) }} from the count shown.
        </p>
      </fieldset>

      <div v-else class="field">
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

.shelf {
  margin: 0 0 1rem;
  padding: 0;
  border: 0;
}

.shelf legend {
  margin-bottom: 0.25rem;
  padding: 0;
  font-weight: 500;
}

.shelf-parts {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.shelf-parts .with-unit {
  flex: 1 1 8rem;
}

.plus {
  font-weight: 600;
  opacity: 0.7;
}
</style>
