import { onBeforeUnmount, watch } from 'vue'

// Keeps the page from scrolling while a panel over it is open, such as the phone menu.
export function useScrollLock(active) {
  watch(active, (locked) => {
    document.documentElement.classList.toggle('scroll-locked', locked)
  })

  onBeforeUnmount(() => {
    if (active.value) {
      document.documentElement.classList.remove('scroll-locked')
    }
  })
}
