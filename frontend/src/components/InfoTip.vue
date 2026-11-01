<script setup>
import { computed, nextTick, onBeforeUnmount, ref, useId } from 'vue'
import { glossary } from '@/utils/glossary'

const props = defineProps({
  // The short form or trade term to explain, such as "SKU".
  term: { type: String, required: true },
  // The explanation; by default the shared glossary entry for the term.
  text: { type: String, default: '' },
})

const entry = computed(() => glossary[props.term] ?? { full: null, meaning: '' })
const heading = computed(() =>
  entry.value.full ? `${props.term} · ${entry.value.full}` : props.term,
)
const meaning = computed(() => props.text || entry.value.meaning)

const tooltipId = useId()
const button = ref(null)
const bubble = ref(null)
const open = ref(false)
const position = ref({ top: 0, left: 0 })

const GAP = 6
const EDGE = 8

// The bubble is fixed to the viewport, so scrolling tables cannot clip it; inside a
// dialog it is still part of the dialog and shows above it.
async function show() {
  if (open.value) {
    return
  }

  open.value = true
  await nextTick()
  place()

  window.addEventListener('scroll', hide, true)
  window.addEventListener('resize', hide)
}

// Below the "i" when it fits, otherwise above, and never past the screen's edges.
function place() {
  const anchor = button.value.getBoundingClientRect()
  const { width, height } = bubble.value.getBoundingClientRect()
  const below = anchor.bottom + GAP
  const fitsBelow = below + height + EDGE <= window.innerHeight

  position.value = {
    top: fitsBelow ? below : Math.max(anchor.top - GAP - height, EDGE),
    left: Math.min(
      Math.max(anchor.left + anchor.width / 2 - width / 2, EDGE),
      window.innerWidth - width - EDGE,
    ),
  }
}

function hide() {
  open.value = false

  window.removeEventListener('scroll', hide, true)
  window.removeEventListener('resize', hide)
}

onBeforeUnmount(hide)
</script>

<template>
  <span class="info" @mouseenter="show" @mouseleave="hide">
    <button
      ref="button"
      type="button"
      class="info-button"
      :aria-label="`What is ${term}?`"
      :aria-describedby="open ? tooltipId : undefined"
      @click="show"
      @focus="show"
      @blur="hide"
      @keydown.esc="hide"
    >
      i
    </button>
    <span
      v-if="open"
      :id="tooltipId"
      ref="bubble"
      role="tooltip"
      class="info-bubble"
      :style="{ top: `${position.top}px`, left: `${position.left}px` }"
    >
      <strong>{{ heading }}</strong>
      {{ meaning }}
    </span>
  </span>
</template>

<style scoped>
.info {
  display: inline-flex;
  vertical-align: middle;
}

.info-button {
  display: inline-grid;
  place-items: center;
  width: 1.1rem;
  height: 1.1rem;
  padding: 0;
  font:
    italic 600 0.72rem/1 Georgia,
    'Times New Roman',
    serif;
  color: var(--color-text);
  background: transparent;
  border: 1px solid var(--color-border-hover);
  border-radius: 50%;
  cursor: help;
}

.info-button:hover,
.info-button:focus-visible {
  color: var(--color-heading);
  border-color: currentColor;
}

.info-button:focus-visible {
  outline: 2px solid hsla(160, 100%, 37%, 0.6);
  outline-offset: 1px;
}

.info-bubble {
  position: fixed;
  z-index: 1000;
  width: max-content;
  max-width: min(18rem, calc(100vw - 16px));
  padding: 0.55rem 0.75rem;
  font-size: 0.85rem;
  font-style: normal;
  font-weight: normal;
  line-height: 1.45;
  text-align: left;
  white-space: normal;
  color: var(--color-text);
  background: var(--color-background);
  border: 1px solid var(--color-border-hover);
  border-radius: 6px;
  box-shadow: 0 4px 16px rgba(0, 0, 0, 0.25);
}

.info-bubble strong {
  display: block;
  margin-bottom: 0.15rem;
  font-weight: 600;
  color: var(--color-heading);
}
</style>
