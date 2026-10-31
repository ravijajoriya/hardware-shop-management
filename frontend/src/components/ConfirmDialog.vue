<script setup>
import { onMounted, ref } from 'vue'

const props = defineProps({
  title: { type: String, required: true },
  message: { type: String, required: true },
  confirmLabel: { type: String, default: 'Confirm' },
  busy: { type: Boolean, default: false },
  error: { type: String, default: '' },
})

defineEmits(['confirm', 'close'])

const dialog = ref(null)

onMounted(() => dialog.value.showModal())

// Escape must not dismiss the dialog while the confirmed action is still running.
function onCancel(event) {
  if (props.busy) {
    event.preventDefault()
  }
}
</script>

<template>
  <dialog ref="dialog" class="dialog" @cancel="onCancel" @close="$emit('close')">
    <h2>{{ title }}</h2>
    <p>{{ message }}</p>

    <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

    <div class="dialog-actions">
      <button type="button" class="btn" :disabled="busy" @click="$emit('close')">Cancel</button>
      <button type="button" class="btn btn-danger" :disabled="busy" @click="$emit('confirm')">
        {{ confirmLabel }}
      </button>
    </div>
  </dialog>
</template>

<style scoped>
.alert-error {
  margin-top: 1rem;
}
</style>
