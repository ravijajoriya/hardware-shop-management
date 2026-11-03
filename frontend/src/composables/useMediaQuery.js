import { onBeforeUnmount, onMounted, ref } from 'vue'

// Whether a CSS media query matches, kept up to date as the screen changes (a phone turned on
// its side, a window resized). Where matchMedia is missing, such as in tests, it never matches.
export function useMediaQuery(query) {
  const list = typeof window.matchMedia === 'function' ? window.matchMedia(query) : null
  const matches = ref(list?.matches ?? false)

  function update(event) {
    matches.value = event.matches
  }

  onMounted(() => list?.addEventListener('change', update))
  onBeforeUnmount(() => list?.removeEventListener('change', update))

  return matches
}
