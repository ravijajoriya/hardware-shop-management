<script setup>
import { onMounted, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useProductStore } from '@/stores/products'
import { formatMoney, formatQuantity } from '@/utils/format'
import { locationLabel } from '@/utils/location'
import InfoTip from '@/components/InfoTip.vue'

const store = useProductStore()
const route = useRoute()
const router = useRouter()

// The search is kept in the address (?find=…), so coming back from a product shows it again.
const term = ref(typeof route.query.find === 'string' ? route.query.find : '')
const products = ref([])
const searched = ref('')
const loading = ref(false)
const error = ref('')

let timer
let latest = 0

watch(term, (value) => {
  clearTimeout(timer)
  timer = setTimeout(() => {
    router.replace({ query: { ...route.query, find: value.trim() || undefined } })
    search(value.trim())
  }, 300)
})

onMounted(() => {
  if (term.value.trim()) {
    search(term.value.trim())
  }
})

async function search(value) {
  const request = ++latest
  error.value = ''

  if (!value) {
    products.value = []
    searched.value = ''
    return
  }

  loading.value = true

  try {
    const found = await store.find(value)

    // An older, slower search must not replace the results of a newer one.
    if (request === latest) {
      products.value = found
      searched.value = value
    }
  } catch (e) {
    if (request === latest) {
      error.value = e.message
    }
  } finally {
    if (request === latest) {
      loading.value = false
    }
  }
}
</script>

<template>
  <section class="where" aria-labelledby="where-title">
    <h2 id="where-title">Where is it?</h2>
    <p class="intro">
      Type a product name, item code or location code
      <InfoTip term="Location code" />
      to see where it is kept.
    </p>

    <input
      v-model="term"
      class="input"
      type="search"
      placeholder="e.g. hammer or SHOP-GF-R3"
      aria-label="Find where a product is kept"
    />

    <p v-if="error" class="alert-error" role="alert">{{ error }}</p>
    <p v-else-if="loading && products.length === 0" class="hint">Searching…</p>
    <p v-else-if="searched && products.length === 0" class="hint">
      Nothing found for “{{ searched }}”.
    </p>

    <ul v-if="products.length" class="results" :class="{ busy: loading }">
      <li v-for="product in products" :key="product.id" class="result">
        <div class="what">
          <RouterLink :to="{ name: 'product-show', params: { id: product.id } }" class="name">
            {{ product.name }}
          </RouterLink>
          <!-- Selling prices: one per size, or "per pc" when there is only one size, whose
               name adds nothing. The spaces in the spans keep words apart when read aloud. -->
          <div class="prices">
            <span v-for="variant in product.variants" :key="variant.id" class="price">
              <span v-if="product.variants.length > 1" class="size">{{ `${variant.name} ` }}</span>
              <strong>{{ formatMoney(variant.selling_price) }}</strong>
              <span v-if="product.variants.length === 1" class="per">
                per {{ product.unit.short_name }}</span
              >
            </span>
          </div>
          <div class="stock">
            {{ formatQuantity(product.stock_quantity, product.unit) }} in stock
          </div>
        </div>

        <div class="place">
          <strong class="code">{{ product.location.code }}</strong>
          <span class="words">{{ locationLabel(product.location) }}</span>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.where {
  max-width: 44rem;
  margin-top: 1.5rem;
  padding: 1.25rem 1.5rem;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

h2 {
  font-size: 1.15rem;
  font-weight: 600;
  color: var(--color-heading);
}

.intro {
  margin-bottom: 0.75rem;
  font-size: 0.9rem;
  opacity: 0.85;
}

.hint,
.alert-error {
  margin-top: 0.75rem;
}

.hint {
  font-size: 0.9rem;
  opacity: 0.8;
}

.results {
  margin: 0.75rem 0 0;
  padding: 0;
  list-style: none;
}

.results.busy {
  opacity: 0.6;
}

.result {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  padding: 0.75rem 0;
  border-top: 1px solid var(--color-border);
}

.name {
  padding: 0;
  font-weight: 600;
}

.prices {
  display: flex;
  flex-wrap: wrap;
  gap: 0.15rem 1rem;
  margin: 0.15rem 0;
}

.price {
  display: inline-flex;
  align-items: baseline;
  gap: 0.35rem;
}

.price strong {
  font-weight: 600;
  color: var(--color-heading);
}

.size,
.per {
  font-size: 0.85rem;
  opacity: 0.8;
}

.stock {
  font-size: 0.85rem;
  opacity: 0.75;
}

.place {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  text-align: right;
}

.code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  font-size: 1.05rem;
  font-weight: 700;
  color: var(--color-heading);
  letter-spacing: 0.03em;
}

.words {
  font-size: 0.85rem;
  opacity: 0.8;
}
</style>
