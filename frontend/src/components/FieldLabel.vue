<script setup>
import InfoTip from '@/components/InfoTip.vue'

defineProps({
  // The id of the input this label belongs to.
  inputId: { type: String, required: true },
  // The short form or trade term in the label, explained by the "i" beside it.
  term: { type: String, required: true },
})
</script>

<template>
  <div class="field-label">
    <label :for="inputId"><slot /></label>
    <InfoTip :term="term" />
  </div>
</template>

<style scoped>
.field-label {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  margin-bottom: 0.25rem;
}

.field-label label {
  margin-bottom: 0;
}
</style>
