<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
  // The product's photos in order; the first is its main image.
  images: { type: Array, default: () => [] },
  // Describes the photos for screen readers.
  name: { type: String, required: true },
})

const selected = ref(0)

watch(
  () => props.images,
  () => {
    selected.value = 0
  },
)

const current = computed(() => props.images[selected.value] ?? null)
</script>

<template>
  <div class="gallery">
    <a
      v-if="current"
      :href="current.url"
      target="_blank"
      rel="noopener"
      class="main"
      title="Open the full-size photo"
    >
      <img :src="current.url" :alt="`${name}, photo ${selected + 1} of ${images.length}`" />
    </a>
    <div v-else class="main empty">No photos yet</div>

    <div v-if="images.length > 1" class="thumbs">
      <button
        v-for="(image, index) in images"
        :key="image.id"
        type="button"
        class="thumb"
        :class="{ selected: index === selected }"
        :aria-label="`Show photo ${index + 1} of ${images.length}`"
        :aria-pressed="index === selected"
        @click="selected = index"
      >
        <img :src="image.url" alt="" />
      </button>
    </div>
  </div>
</template>

<style scoped>
.main {
  display: grid;
  place-items: center;
  aspect-ratio: 1;
  padding: 0;
  overflow: hidden;
  background: var(--color-background-mute);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.main img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

/* On a phone the price and place matter first, so the photo takes less of the screen. */
@media (max-width: 720px) {
  .main {
    aspect-ratio: 4 / 3;
    max-height: 16rem;
  }
}

.main.empty {
  font-size: 0.9rem;
  opacity: 0.75;
}

.thumbs {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.75rem;
}

.thumb {
  width: 64px;
  height: 64px;
  padding: 0;
  overflow: hidden;
  background: var(--color-background-mute);
  border: 2px solid var(--color-border);
  border-radius: 6px;
  cursor: pointer;
}

.thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.thumb.selected {
  border-color: hsla(160, 100%, 37%, 1);
}

.thumb:focus-visible {
  outline: 2px solid hsla(160, 100%, 37%, 0.6);
  outline-offset: 2px;
}
</style>
