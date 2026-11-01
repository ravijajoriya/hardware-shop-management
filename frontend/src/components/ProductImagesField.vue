<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { fileImageItem } from '@/utils/productForm'

const MAX_IMAGES = 10
const MAX_BYTES = 2 * 1024 * 1024
const TYPES = ['image/jpeg', 'image/png', 'image/webp']

// The images in display order: saved ones and newly chosen files. The first is the main image.
const items = defineModel({ type: Array, required: true })

defineProps({
  error: { type: String, default: '' },
})

const notice = ref('')

const remaining = computed(() => MAX_IMAGES - items.value.length)

// Free the preview of any chosen file that leaves the list, however it leaves.
watch(items, (current, previous) => {
  for (const item of previous) {
    if (item.file && !current.includes(item)) {
      URL.revokeObjectURL(item.url)
    }
  }
})

onBeforeUnmount(() => {
  for (const item of items.value) {
    if (item.file) {
      URL.revokeObjectURL(item.url)
    }
  }
})

function choose(event) {
  const files = [...event.target.files]

  // Let the same files be picked again later.
  event.target.value = ''

  const usable = files.filter((file) => TYPES.includes(file.type) && file.size <= MAX_BYTES)
  const added = usable.slice(0, remaining.value)

  notice.value = [
    usable.length < files.length ? 'Some files were skipped: use JPG, PNG or WebP up to 2 MB.' : '',
    added.length < usable.length ? `A product can have up to ${MAX_IMAGES} images.` : '',
  ]
    .filter(Boolean)
    .join(' ')

  items.value = [...items.value, ...added.map(fileImageItem)]
}

function makeMain(item) {
  items.value = [item, ...items.value.filter((other) => other !== item)]
}

function remove(item) {
  notice.value = ''
  items.value = items.value.filter((other) => other !== item)
}
</script>

<template>
  <ul v-if="items.length" class="gallery">
    <li v-for="(item, index) in items" :key="item.key" class="tile">
      <img :src="item.url" :alt="`Product photo ${index + 1}`" />

      <span v-if="index === 0" class="tag">Main</span>

      <div class="tile-actions">
        <button v-if="index > 0" type="button" class="btn small" @click="makeMain(item)">
          Make main
        </button>
        <button
          type="button"
          class="btn small"
          :aria-label="`Remove photo ${index + 1}`"
          title="Remove"
          @click="remove(item)"
        >
          ✕
        </button>
      </div>
    </li>
  </ul>

  <div class="add">
    <label v-if="remaining > 0" class="btn">
      Add images
      <input
        type="file"
        multiple
        accept="image/jpeg,image/png,image/webp"
        class="visually-hidden"
        @change="choose"
      />
    </label>

    <p class="hint">
      {{ items.length }} of {{ MAX_IMAGES }} images. JPG, PNG or WebP, up to 2 MB each. The first
      one is the main image. Changes are saved with the product.
    </p>
  </div>

  <p v-if="notice" class="field-error">{{ notice }}</p>
  <p v-if="error" class="field-error">{{ error }}</p>
</template>

<style scoped>
.gallery {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr));
  gap: 0.75rem;
  margin: 0 0 1rem;
  padding: 0;
  list-style: none;
}

.tile {
  position: relative;
  overflow: hidden;
  aspect-ratio: 1;
  background: var(--color-background-mute);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.tile img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.tag {
  position: absolute;
  top: 0.4rem;
  left: 0.4rem;
  padding: 0.05rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--vt-c-white);
  background: hsla(160, 100%, 28%, 0.9);
  border-radius: 999px;
}

.tile-actions {
  position: absolute;
  right: 0.4rem;
  bottom: 0.4rem;
  left: 0.4rem;
  display: flex;
  justify-content: flex-end;
  gap: 0.3rem;
}

.btn.small {
  padding: 0.15rem 0.5rem;
  font-size: 0.8rem;
  background: var(--color-background-soft);
}

.add {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 1rem;
}

label.btn:focus-within {
  outline: 2px solid hsla(160, 100%, 37%, 0.6);
  outline-offset: 1px;
}

.hint {
  font-size: 0.85rem;
  opacity: 0.8;
}

.field-error {
  margin-top: 0.5rem;
}
</style>
