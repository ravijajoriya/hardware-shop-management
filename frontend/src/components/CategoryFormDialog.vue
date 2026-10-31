<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useCategoryStore } from '@/stores/categories'

const props = defineProps({
  // The category to edit, or null to create a new one.
  category: { type: Object, default: null },
})

const emit = defineEmits(['close'])

const store = useCategoryStore()

const dialog = ref(null)
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

const form = reactive({
  name: props.category?.name ?? '',
  slug: props.category?.slug ?? '',
  parent_id: props.category?.parent_id ?? null,
  description: props.category?.description ?? '',
  is_active: props.category?.is_active ?? true,
})

// A category cannot sit under itself or one of its own subcategories.
const parentOptions = computed(() => {
  const excluded = props.category ? store.branchIds(props.category.id) : new Set()

  return store.tree.filter((option) => !excluded.has(option.id))
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
    if (props.category) {
      await store.update(props.category.id, payload)
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
      <h2>{{ category ? 'Edit category' : 'New category' }}</h2>

      <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

      <div class="field">
        <label for="category-name">Name</label>
        <input
          id="category-name"
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
        <label for="category-slug">Slug</label>
        <input id="category-slug" v-model="form.slug" class="input" type="text" maxlength="255" />
        <p v-if="fieldErrors.slug" class="field-error">{{ fieldErrors.slug[0] }}</p>
        <p v-else class="hint">Leave blank to create it from the name.</p>
      </div>

      <div class="field">
        <label for="category-parent">Parent category</label>
        <select id="category-parent" v-model="form.parent_id" class="input">
          <option :value="null">None (top level)</option>
          <option v-for="option in parentOptions" :key="option.id" :value="option.id">
            {{ '— '.repeat(option.depth) }}{{ option.name }}
          </option>
        </select>
        <p v-if="fieldErrors.parent_id" class="field-error">{{ fieldErrors.parent_id[0] }}</p>
      </div>

      <div class="field">
        <label for="category-description">Description</label>
        <textarea
          id="category-description"
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

textarea {
  resize: vertical;
}

.checkbox {
  display: inline-flex;
  align-items: center;
  gap: 0.5rem;
  cursor: pointer;
}
</style>
