<script setup>
import { computed, ref, watch } from 'vue'
import { useProductStore } from '@/stores/products'
import { formatMoney } from '@/utils/format'
import { itemName } from '@/utils/billing'
import { costPerPack, formatStock, fullPackPrice, packWord } from '@/utils/packs'

// Finds products while typing and lists every size to pick, for adding items to a bill or to a
// delivery of stock. Enter picks the only size found.
const props = defineProps({
  placeholder: { type: String, required: true },
  label: { type: String, required: true },
  // The price shown for each size: what it sells for, or what it cost to buy.
  priceField: { type: String, default: 'selling_price' },
  // The sizes already added, and what picking one of them again does.
  pickedIds: { type: Array, default: () => [] },
  pickedNote: { type: String, default: 'Added: adds 1 more' },
})

const emit = defineEmits(['pick'])

const products = useProductStore()

const input = ref(null)
const term = ref('')
const results = ref([])
const searched = ref('')
const searching = ref(false)
const error = ref('')

const picked = computed(() => new Set(props.pickedIds))

let timer
let latest = 0

watch(term, (value) => {
  clearTimeout(timer)
  timer = setTimeout(() => search(value.trim()), 250)
})

async function search(value) {
  const request = ++latest
  error.value = ''

  if (!value) {
    results.value = []
    searched.value = ''
    searching.value = false
    return
  }

  searching.value = true

  try {
    const found = await products.find(value)

    // An older, slower search must not replace the results of a newer one.
    if (request === latest) {
      results.value = found
      searched.value = value
    }
  } catch (e) {
    if (request === latest) {
      error.value = e.message
    }
  } finally {
    if (request === latest) {
      searching.value = false
    }
  }
}

// The price on a size's button. A product bought in packs shows the pack's price too when
// selling (₹18 a m · ₹1,350 a coil), and the pack's cost when buying (₹1,000 a coil).
function priceText(product, variant) {
  const pack = product.pack

  if (!pack) {
    return formatMoney(variant[props.priceField])
  }

  if (props.priceField === 'purchase_price') {
    return `${formatMoney(costPerPack(variant.purchase_price, pack.size))} a ${packWord(pack.unit)}`
  }

  return `${formatMoney(variant[props.priceField])} a ${product.unit.short_name} · ${formatMoney(fullPackPrice(variant, pack))} a ${packWord(pack.unit)}`
}

// Every size in the results, so Enter can pick one when it is the only one.
const choices = computed(() =>
  results.value.flatMap((product) => product.variants.map((variant) => ({ product, variant }))),
)

function pick(product, variant) {
  latest++
  term.value = ''
  results.value = []
  searched.value = ''
  searching.value = false

  emit('pick', product, variant)
}

function pickOnlyChoice() {
  if (choices.value.length === 1) {
    const [{ product, variant }] = choices.value

    pick(product, variant)
  }
}

defineExpose({ focus: () => input.value?.focus() })
</script>

<template>
  <div class="finder">
    <input
      ref="input"
      v-model="term"
      class="input"
      type="search"
      autocomplete="off"
      :placeholder="placeholder"
      :aria-label="label"
      @keydown.enter.prevent="pickOnlyChoice"
    />

    <p v-if="error" class="field-error">{{ error }}</p>
    <p v-else-if="searching && results.length === 0" class="hint">Searching…</p>
    <p v-else-if="searched && results.length === 0" class="hint">
      Nothing found for “{{ searched }}”.
    </p>

    <ul v-if="results.length" class="results" :class="{ busy: searching }">
      <li v-for="product in results" :key="product.id" class="result">
        <span class="result-name">
          {{ product.name }}
          <span v-if="!product.is_active" class="badge">Inactive</span>
        </span>
        <div class="picks">
          <button
            v-for="variant in product.variants"
            :key="variant.id"
            type="button"
            class="pick"
            :aria-label="`Add ${itemName({ product_name: product.name, variant_name: variant.name })} at ${priceText(product, variant)}`"
            @click="pick(product, variant)"
          >
            <span v-if="variant.name" class="pick-size">{{ variant.name }}</span>
            <strong>{{ priceText(product, variant) }}</strong>
            <span class="pick-stock" :class="{ out: variant.stock_quantity <= 0 }">
              {{ formatStock(variant.stock_quantity, product.unit, product.pack) }} in stock
            </span>
            <span v-if="picked.has(variant.id)" class="pick-added">{{ pickedNote }}</span>
          </button>
        </div>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.finder {
  margin-bottom: 1rem;
}

.hint {
  font-size: 0.85rem;
  opacity: 0.8;
}

.hint,
.field-error {
  margin-top: 0.5rem;
}

.results {
  max-height: 24rem;
  margin: 0.5rem 0 0;
  padding: 0;
  overflow-y: auto;
  list-style: none;
  background: var(--color-background);
  border: 1px solid var(--color-border-hover);
  border-radius: 6px;
}

.results.busy {
  opacity: 0.6;
}

.result {
  padding: 0.6rem 0.75rem;
  border-top: 1px solid var(--color-border);
}

.result:first-child {
  border-top: 0;
}

.result-name {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  font-weight: 600;
  color: var(--color-heading);
}

.picks {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-top: 0.4rem;
}

.pick {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  padding: 0.4rem 0.75rem;
  font: inherit;
  line-height: 1.4;
  text-align: left;
  color: var(--color-text);
  background: var(--color-background-soft);
  border: 1px solid var(--color-border-hover);
  border-radius: 6px;
  cursor: pointer;
}

.pick:hover,
.pick:focus-visible {
  border-color: hsla(160, 100%, 37%, 1);
  box-shadow: inset 0 0 0 1px hsla(160, 100%, 37%, 1);
}

.pick strong {
  font-weight: 600;
  color: var(--color-heading);
}

.pick-size,
.pick-stock,
.pick-added {
  font-size: 0.8rem;
}

.pick-size,
.pick-stock {
  opacity: 0.85;
}

.pick-stock.out {
  color: var(--color-danger);
  opacity: 1;
}

.pick-added {
  color: var(--color-success);
}
</style>
