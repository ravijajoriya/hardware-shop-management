<script setup>
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useProductStore } from '@/stores/products'
import {
  NEW_BRAND,
  imageChanges,
  imageOrder,
  productPayload,
  savedImageItem,
  variantRow,
} from '@/utils/productForm'
import FieldLabel from '@/components/FieldLabel.vue'
import ProductImagesField from '@/components/ProductImagesField.vue'
import ProductVariantsEditor from '@/components/ProductVariantsEditor.vue'

const props = defineProps({
  // Set when editing; a new product has none.
  id: { type: String, default: null },
})

const store = useProductStore()
const router = useRouter()

const isEdit = computed(() => props.id !== null)
const listRoute = computed(() => ({ name: 'products', query: store.listQuery }))

const loading = ref(true)
const loadError = ref('')
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})

const form = reactive({
  name: '',
  slug: '',
  category_id: '',
  brand_id: '',
  // Typed when the brand is not in the list yet (brand_id is then NEW_BRAND).
  new_brand_name: '',
  unit_id: '',
  description: '',
  hsn_code: '',
  gst_rate: '',
  is_active: true,
})
const variants = ref([variantRow()])

// The images as last saved, and the list being edited (saved ones plus newly chosen files).
const savedImages = ref([])
const imageItems = ref([])

// Inactive choices are hidden unless this product already uses them.
function selectable(items, selectedId) {
  return items.filter((item) => item.is_active || item.id === selectedId)
}

const categories = computed(() => selectable(store.options.categories, form.category_id))
const brands = computed(() => selectable(store.options.brands, form.brand_id))
const units = computed(() => selectable(store.options.units, form.unit_id))
const unit = computed(
  () => store.options.units.find((option) => option.id === form.unit_id) ?? null,
)

const addingBrand = computed(() => form.brand_id === NEW_BRAND)
const newBrandInput = ref(null)

// A typed name that is already in the list, so it can be picked instead of added twice.
const matchingBrand = computed(() => {
  const typed = form.new_brand_name.trim().toLowerCase()

  return typed ? store.options.brands.find((brand) => brand.name.toLowerCase() === typed) : null
})

watch(addingBrand, async (adding) => {
  if (adding) {
    await nextTick()
    newBrandInput.value?.focus()
  }
})

function errorFor(field) {
  return fieldErrors.value[field]?.[0]
}

function fill(product) {
  Object.assign(form, {
    name: product.name,
    slug: product.slug,
    category_id: product.category.id,
    brand_id: product.brand?.id ?? '',
    unit_id: product.unit.id,
    description: product.description ?? '',
    hsn_code: product.hsn_code ?? '',
    gst_rate: product.gst_rate ?? '',
    is_active: product.is_active,
  })

  variants.value = product.variants.map(variantRow)
  setImages(product.images)
}

function setImages(images) {
  savedImages.value = images
  imageItems.value = images.map(savedImageItem)
}

// Bring the product's images in line with the form: delete the removed ones, upload new
// files one at a time (keeping each request under the server's size limit), then fix the order.
async function saveImages(productId) {
  const { deleteIds, files } = imageChanges(savedImages.value, imageItems.value)
  let current = savedImages.value

  for (const imageId of deleteIds) {
    current = await store.deleteImage(productId, imageId)
  }

  const uploadedIds = []

  for (const file of files) {
    current = await store.uploadImages(productId, [file])
    uploadedIds.push(current.at(-1).id)
  }

  const order = imageOrder(imageItems.value, uploadedIds)

  if (order.join() !== current.map((image) => image.id).join()) {
    await store.reorderImages(productId, order)
  }
}

async function load() {
  loading.value = true
  loadError.value = ''

  try {
    const [, product] = await Promise.all([
      store.fetchOptions(),
      isEdit.value ? store.fetchOne(props.id) : null,
    ])

    if (product) {
      fill(product)
    }
  } catch (e) {
    loadError.value = e.message
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  error.value = ''
  fieldErrors.value = {}

  let product

  try {
    const payload = productPayload(form, variants.value)

    product = isEdit.value ? await store.update(props.id, payload) : await store.create(payload)
  } catch (e) {
    fieldErrors.value = e.errors ?? {}
    error.value = Object.keys(fieldErrors.value).length
      ? 'Some details need fixing. Check the messages above.'
      : e.message
    saving.value = false

    return
  }

  try {
    await saveImages(product.id)
  } catch (e) {
    // The product is saved; stay on its edit page, showing the images as they now are.
    const reason = Object.values(e.errors ?? {})[0]?.[0] ?? e.message

    fieldErrors.value = { images: [reason] }
    error.value =
      'The product was saved, but not all image changes were. Check the images and save again.'
    saving.value = false

    store
      .fetchOne(product.id)
      .then((fresh) => setImages(fresh.images))
      .catch(() => {})

    if (!isEdit.value) {
      router.replace({ name: 'product-edit', params: { id: product.id } })
    }

    return
  }

  saving.value = false
  router.push(listRoute.value)
}

onMounted(load)
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">{{ isEdit ? 'Edit product' : 'New product' }}</h1>
    <RouterLink class="btn" :to="listRoute">Back to products</RouterLink>
  </div>

  <p v-if="loading">Loading…</p>

  <div v-else-if="loadError" class="alert-error load-error" role="alert">
    <span>{{ loadError }}</span>
    <button type="button" class="btn" @click="load">Try again</button>
  </div>

  <form v-else @submit.prevent="save">
    <section class="form-section">
      <h2>Details</h2>

      <div class="grid">
        <div class="field wide">
          <label for="product-name">Name</label>
          <input
            id="product-name"
            v-model="form.name"
            class="input"
            type="text"
            maxlength="255"
            required
          />
          <p v-if="errorFor('name')" class="field-error">{{ errorFor('name') }}</p>
        </div>

        <div class="field">
          <label for="product-category">Category</label>
          <select id="product-category" v-model="form.category_id" class="input" required>
            <option value="" disabled>Choose a category</option>
            <option v-for="category in categories" :key="category.id" :value="category.id">
              {{ '— '.repeat(category.depth) }}{{ category.name
              }}{{ category.is_active ? '' : ' (inactive)' }}
            </option>
          </select>
          <p v-if="errorFor('category_id')" class="field-error">{{ errorFor('category_id') }}</p>
        </div>

        <div class="field">
          <label for="product-brand">Brand</label>
          <select id="product-brand" v-model="form.brand_id" class="input">
            <option value="">No brand</option>
            <option v-for="brand in brands" :key="brand.id" :value="brand.id">
              {{ brand.name }}{{ brand.is_active ? '' : ' (inactive)' }}
            </option>
            <option :value="NEW_BRAND">+ Add a new brand…</option>
          </select>
          <p v-if="errorFor('brand_id')" class="field-error">{{ errorFor('brand_id') }}</p>
        </div>

        <div v-if="addingBrand" class="field">
          <label for="product-new-brand">New brand name</label>
          <input
            id="product-new-brand"
            ref="newBrandInput"
            v-model="form.new_brand_name"
            class="input"
            type="text"
            maxlength="255"
            placeholder="e.g. Asian Paints"
            required
          />
          <p v-if="errorFor('new_brand_name')" class="field-error">
            {{ errorFor('new_brand_name') }}
          </p>
          <p v-else-if="matchingBrand" class="hint">
            “{{ matchingBrand.name }}” is already in your brands.
            <button type="button" class="link-button" @click="form.brand_id = matchingBrand.id">
              Use it
            </button>
          </p>
          <p v-else class="hint">It is added to your brands when you save this product.</p>
        </div>

        <div class="field">
          <label for="product-unit">Unit</label>
          <select id="product-unit" v-model="form.unit_id" class="input" required>
            <option value="" disabled>Choose a unit</option>
            <option v-for="option in units" :key="option.id" :value="option.id">
              {{ option.name }} ({{ option.short_name }})
            </option>
          </select>
          <p v-if="errorFor('unit_id')" class="field-error">{{ errorFor('unit_id') }}</p>
          <p v-else class="hint">Stock and prices are per this unit.</p>
        </div>

        <div class="field">
          <FieldLabel input-id="product-slug" term="Slug">Slug</FieldLabel>
          <input id="product-slug" v-model="form.slug" class="input" type="text" maxlength="255" />
          <p v-if="errorFor('slug')" class="field-error">{{ errorFor('slug') }}</p>
          <p v-else class="hint">Leave blank to create it from the name.</p>
        </div>

        <div class="field wide">
          <label for="product-description">Description</label>
          <textarea
            id="product-description"
            v-model="form.description"
            class="input"
            rows="3"
            maxlength="2000"
          ></textarea>
          <p v-if="errorFor('description')" class="field-error">{{ errorFor('description') }}</p>
        </div>
      </div>

      <label class="checkbox">
        <input v-model="form.is_active" type="checkbox" />
        Active
      </label>
    </section>

    <section class="form-section">
      <h2>Tax <span class="optional">optional</span></h2>

      <div class="grid">
        <div class="field">
          <FieldLabel input-id="product-hsn" term="HSN">HSN code</FieldLabel>
          <input
            id="product-hsn"
            v-model="form.hsn_code"
            class="input"
            type="text"
            inputmode="numeric"
            maxlength="8"
            placeholder="e.g. 3209"
          />
          <p v-if="errorFor('hsn_code')" class="field-error">{{ errorFor('hsn_code') }}</p>
        </div>

        <div class="field">
          <FieldLabel input-id="product-gst" term="GST">GST rate (%)</FieldLabel>
          <input
            id="product-gst"
            v-model="form.gst_rate"
            class="input"
            type="number"
            min="0"
            max="100"
            step="0.01"
            placeholder="e.g. 18"
          />
          <p v-if="errorFor('gst_rate')" class="field-error">{{ errorFor('gst_rate') }}</p>
        </div>
      </div>

      <p class="hint">Leave both blank if GST does not apply to this product.</p>
    </section>

    <section class="form-section">
      <h2>Images <span class="optional">optional</span></h2>

      <ProductImagesField v-model="imageItems" :error="errorFor('images')" />
    </section>

    <section class="form-section">
      <h2>Variants, prices and stock</h2>

      <ProductVariantsEditor v-model="variants" :unit="unit" :errors="fieldErrors" />
      <p v-if="errorFor('variants')" class="field-error">{{ errorFor('variants') }}</p>
    </section>

    <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

    <div class="form-actions">
      <RouterLink class="btn" :to="listRoute">Cancel</RouterLink>
      <button type="submit" class="btn btn-primary" :disabled="saving">
        {{ saving ? 'Saving…' : 'Save product' }}
      </button>
    </div>
  </form>
</template>

<style scoped>
.form-section {
  margin-bottom: 1.25rem;
  padding: 1.25rem 1.5rem;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.form-section h2 {
  margin-bottom: 1rem;
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--color-heading);
}

.optional {
  margin-left: 0.35rem;
  font-size: 0.8rem;
  font-weight: normal;
  opacity: 0.7;
}

.grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(16rem, 1fr));
  gap: 0 1rem;
}

.grid .wide {
  grid-column: 1 / -1;
}

.form-section > .hint {
  font-size: 0.85rem;
  opacity: 0.8;
}

.alert-error {
  margin-bottom: 1rem;
}

.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.5rem;
}

.link-button {
  padding: 0;
  font: inherit;
  color: hsla(160, 100%, 37%, 1);
  text-decoration: underline;
  background: none;
  border: 0;
  cursor: pointer;
}
</style>
