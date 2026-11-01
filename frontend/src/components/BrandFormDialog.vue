<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useBrandStore } from '@/stores/brands'
import FieldLabel from '@/components/FieldLabel.vue'

const props = defineProps({
  // The brand to edit, or null to create a new one.
  brand: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const store = useBrandStore()

const dialog = ref(null)
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

const form = reactive({
  name: props.brand?.name ?? '',
  slug: props.brand?.slug ?? '',
  description: props.brand?.description ?? '',
  is_active: props.brand?.is_active ?? true,
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

  const payload = {
    ...form,
    slug: form.slug.trim() || null,
    description: form.description.trim() || null,
  }

  try {
    if (props.brand) {
      await store.update(props.brand.id, payload)
    } else {
      await store.create(payload)
    }

    emit('close')
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
      <h2>{{ brand ? 'Edit brand' : 'New brand' }}</h2>

      <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

      <div class="field">
        <label for="brand-name">Name</label>
        <input
          id="brand-name"
          v-model="form.name"
          class="input"
          type="text"
          maxlength="255"
          required
          autofocus
        />
        <p v-if="fieldErrors.name" class="field-error">{{ fieldErrors.name[0] }}</p>
      </div>

      <div class="field">
        <FieldLabel input-id="brand-slug" term="Slug">Slug</FieldLabel>
        <input id="brand-slug" v-model="form.slug" class="input" type="text" maxlength="255" />
        <p v-if="fieldErrors.slug" class="field-error">{{ fieldErrors.slug[0] }}</p>
        <p v-else class="hint">Leave blank to create it from the name.</p>
      </div>

      <div class="field">
        <label for="brand-description">Description</label>
        <textarea
          id="brand-description"
          v-model="form.description"
          class="input"
          rows="3"
          maxlength="1000"
        ></textarea>
        <p v-if="fieldErrors.description" class="field-error">{{ fieldErrors.description[0] }}</p>
      </div>

      <label class="checkbox">
        <input v-model="form.is_active" type="checkbox" />
        Active
      </label>

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
.alert-error {
  margin-bottom: 1rem;
}
</style>
