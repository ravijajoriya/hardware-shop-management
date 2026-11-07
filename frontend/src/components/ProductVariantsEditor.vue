<script setup>
import { computed } from 'vue'
import { formatMoney, formatQuantity } from '@/utils/format'
import { formatStock, packWord } from '@/utils/packs'
import { isBelowMinimum, rowStock, rowWorth, variantRow } from '@/utils/productForm'
import InfoTip from '@/components/InfoTip.vue'

const rows = defineModel({ type: Array, required: true })

const props = defineProps({
  // The product's unit; it decides whether stock may have decimals.
  unit: { type: Object, default: null },
  // The pack it is bought in, such as a 90 m coil: prices and counts are then per pack.
  pack: { type: Object, default: null },
  // Validation messages keyed the way the API sends them, such as "variants.0.selling_price".
  errors: { type: Object, default: () => ({}) },
})

const stockStep = computed(() => (props.unit?.allow_decimal ? '0.001' : '1'))

// Saved variants' stock is shown but not typed over: it changes through Stock in and stock
// corrections, which are recorded. Only a new variant takes its opening stock here.
const hasSavedRows = computed(() => rows.value.some((row) => row.id))

const packName = computed(() => (props.pack ? packWord(props.pack.unit) : ''))
const packPlural = computed(() => (props.pack ? packWord(props.pack.unit, 2) : ''))
const looseName = computed(() => props.unit?.short_name ?? 'unit')

const totals = computed(() =>
  rows.value.reduce(
    (sum, row) => {
      const worth = rowWorth(row, props.pack)

      return {
        stock: sum.stock + worth.stock,
        cost: sum.cost + worth.cost,
        selling: sum.selling + worth.selling,
      }
    },
    { stock: 0, cost: 0, selling: 0 },
  ),
)

function errorFor(index, field) {
  return props.errors[`variants.${index}.${field}`]?.[0]
}

function add() {
  rows.value.push(variantRow({}, props.pack))
}

// The minimum is typed in packs for a product bought in packs.
function belowMinimum(row) {
  const minimum = String(row.min_stock ?? '').trim()

  return isBelowMinimum(
    rowStock(row, props.pack),
    minimum === '' ? '' : Number(minimum) * (props.pack?.size ?? 1),
  )
}

// A blank pack price sells the pack at the loose price times the pack size.
function packPriceHint(row) {
  const loose = Number(row.selling_price)

  return loose > 0
    ? `Blank: ${formatMoney(Math.round(loose * props.pack.size * 100) / 100)}`
    : 'Optional'
}

function remove(index) {
  rows.value.splice(index, 1)
}
</script>

<template>
  <p class="hint intro">
    A product without sizes or colours needs one row. With several rows, name each one, such as “1
    L” or “Red”.
  </p>

  <div class="table-wrap">
    <table class="data-table variants stack">
      <thead>
        <tr>
          <th scope="col">Variant</th>
          <th scope="col">SKU <InfoTip term="SKU" /></th>
          <th scope="col">
            Purchase price (₹{{ pack ? ` per ${packName}` : '' }})
            <InfoTip term="Purchase price" />
          </th>
          <th scope="col">
            {{ pack ? `Loose price (₹ per ${looseName})` : 'Selling price (₹)' }}
          </th>
          <th v-if="pack" scope="col">{{ pack.unit.name }} price (₹)</th>
          <th scope="col">MRP (₹) <InfoTip term="MRP" /></th>
          <th scope="col">Stock{{ unit && !pack ? ` (${unit.short_name})` : '' }}</th>
          <th scope="col">
            Min. stock{{ pack ? ` (${packPlural})` : '' }} <InfoTip term="Minimum stock" />
          </th>
          <th scope="col" class="num">Stock worth</th>
          <th scope="col"><span class="visually-hidden">Remove</span></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, index) in rows" :key="row.key">
          <td class="col-name">
            <span class="cell-label">Variant</span>
            <input
              v-model="row.name"
              class="input"
              type="text"
              maxlength="100"
              :placeholder="rows.length > 1 ? 'e.g. 4 L' : 'Optional'"
              :aria-label="`Variant ${index + 1} name`"
            />
            <p v-if="errorFor(index, 'name')" class="field-error">{{ errorFor(index, 'name') }}</p>
          </td>
          <td class="col-sku">
            <span class="cell-label">SKU <InfoTip term="SKU" /></span>
            <input
              v-model="row.sku"
              class="input"
              type="text"
              maxlength="50"
              placeholder="Optional"
              :aria-label="`Variant ${index + 1} SKU`"
            />
            <p v-if="errorFor(index, 'sku')" class="field-error">{{ errorFor(index, 'sku') }}</p>
          </td>
          <td class="col-price">
            <span class="cell-label">
              Purchase price (₹{{ pack ? ` per ${packName}` : '' }})
              <InfoTip term="Purchase price" />
            </span>
            <input
              v-model="row.purchase_price"
              class="input"
              type="number"
              min="0"
              step="0.01"
              required
              :aria-label="`Variant ${index + 1} purchase price${pack ? ` per ${packName}` : ''}`"
            />
            <p v-if="errorFor(index, 'purchase_price')" class="field-error">
              {{ errorFor(index, 'purchase_price') }}
            </p>
          </td>
          <td class="col-price">
            <span class="cell-label">
              {{ pack ? `Loose price (₹ per ${looseName})` : 'Selling price (₹)' }}
            </span>
            <input
              v-model="row.selling_price"
              class="input"
              type="number"
              min="0"
              step="0.01"
              required
              :aria-label="`Variant ${index + 1} ${pack ? `loose price per ${looseName}` : 'selling price'}`"
            />
            <p v-if="errorFor(index, 'selling_price')" class="field-error">
              {{ errorFor(index, 'selling_price') }}
            </p>
          </td>
          <td v-if="pack" class="col-price">
            <span class="cell-label">{{ pack.unit.name }} price (₹)</span>
            <input
              v-model="row.pack_price"
              class="input"
              type="number"
              min="0"
              step="0.01"
              :placeholder="packPriceHint(row)"
              :aria-label="`Variant ${index + 1} ${packName} price`"
            />
            <p v-if="errorFor(index, 'pack_price')" class="field-error">
              {{ errorFor(index, 'pack_price') }}
            </p>
          </td>
          <td class="col-price">
            <span class="cell-label">MRP (₹) <InfoTip term="MRP" /></span>
            <input
              v-model="row.mrp"
              class="input"
              type="number"
              min="0"
              step="0.01"
              placeholder="Optional"
              :aria-label="`Variant ${index + 1} MRP`"
            />
            <p v-if="errorFor(index, 'mrp')" class="field-error">{{ errorFor(index, 'mrp') }}</p>
          </td>
          <td class="col-stock">
            <span class="cell-label">Stock{{ unit && !pack ? ` (${unit.short_name})` : '' }}</span>
            <span v-if="row.id" class="stock-fixed">{{
              pack
                ? formatStock(row.stock_quantity, unit, pack)
                : formatQuantity(row.stock_quantity)
            }}</span>
            <div v-else-if="pack" class="shelf-inputs">
              <label class="with-unit">
                <input
                  v-model="row.stock_packs"
                  class="input"
                  type="number"
                  min="0"
                  step="1"
                  required
                  :aria-label="`Variant ${index + 1} opening stock, full ${packPlural}`"
                />
                <span class="unit">{{ packPlural }}</span>
              </label>
              <label class="with-unit">
                <input
                  v-model="row.stock_loose"
                  class="input"
                  type="number"
                  min="0"
                  :step="stockStep"
                  :aria-label="`Variant ${index + 1} opening stock, ${looseName} loose`"
                />
                <span class="unit">{{ looseName }} loose</span>
              </label>
            </div>
            <input
              v-else
              v-model="row.stock_quantity"
              class="input"
              type="number"
              min="0"
              :step="stockStep"
              required
              :aria-label="`Variant ${index + 1} opening stock`"
            />
            <p v-if="errorFor(index, 'stock_quantity')" class="field-error">
              {{ errorFor(index, 'stock_quantity') }}
            </p>
          </td>
          <td class="col-min">
            <span class="cell-label">
              Min. stock{{ pack ? ` (${packPlural})` : '' }} <InfoTip term="Minimum stock" />
            </span>
            <input
              v-model="row.min_stock"
              class="input"
              type="number"
              min="0"
              :step="pack ? 'any' : stockStep"
              placeholder="Optional"
              :aria-label="`Variant ${index + 1} minimum stock${pack ? ` in ${packPlural}` : ''}`"
            />
            <p v-if="errorFor(index, 'min_stock')" class="field-error">
              {{ errorFor(index, 'min_stock') }}
            </p>
            <p v-else-if="belowMinimum(row)" class="low">Below the minimum</p>
          </td>
          <td class="num worth">
            <span class="cell-label">Stock worth</span>
            <div>{{ formatMoney(rowWorth(row, pack).cost) }}</div>
            <div class="sells-for">sells for {{ formatMoney(rowWorth(row, pack).selling) }}</div>
          </td>
          <td class="actions">
            <button
              type="button"
              class="btn remove"
              :disabled="rows.length === 1"
              :aria-label="`Remove variant ${index + 1}`"
              title="Remove variant"
              @click="remove(index)"
            >
              ✕
            </button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>

  <p v-if="hasSavedRows" class="hint stock-note">
    Stock of saved variants changes only through Stock in, or Correct stock on the product page, so
    every change is recorded. A new variant takes its opening stock here.
  </p>

  <div class="footer">
    <button type="button" class="btn" @click="add">Add variant</button>
    <p class="totals">
      Total stock <strong>{{ formatStock(totals.stock, unit, pack) }}</strong> · worth
      <strong>{{ formatMoney(totals.cost) }}</strong> at cost,
      <strong>{{ formatMoney(totals.selling) }}</strong> at selling price
    </p>
  </div>
</template>

<style scoped>
.intro {
  margin-bottom: 0.75rem;
}

.variants th,
.variants td {
  padding: 0.6rem 0.5rem;
}

.variants th:first-child,
.variants td:first-child {
  padding-left: 1rem;
}

.variants th:last-child,
.variants td:last-child {
  padding-right: 1rem;
}

.variants td {
  vertical-align: top;
  white-space: normal;
}

.variants .field-error {
  max-width: 12rem;
}

.col-name {
  min-width: 8rem;
}

.col-sku {
  min-width: 7rem;
}

.col-price {
  min-width: 6.5rem;
}

.col-stock,
.col-min {
  min-width: 6rem;
}

.low {
  margin-top: 0.25rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--color-danger);
}

/* Lined up with the text inside the inputs beside it. */
.stock-fixed {
  display: block;
  padding: 0.55rem 0;
  color: var(--color-heading);
}

.stock-note {
  margin-top: 0.5rem;
}

.num {
  text-align: right;
}

.variants td.worth {
  padding-top: 0.9rem;
  white-space: nowrap;
}

.remove {
  padding: 0.4rem 0.65rem;
  line-height: 1.2;
}

.sells-for {
  font-size: 0.85rem;
  opacity: 0.75;
}

.footer {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin-top: 0.75rem;
}

.totals strong {
  font-weight: 600;
  color: var(--color-heading);
}

.hint {
  font-size: 0.85rem;
  opacity: 0.8;
}

/* Narrower on smaller laptops and landscape tablets, so every column fits. */
@media (max-width: 1100px) {
  .variants th,
  .variants td {
    padding-inline: 0.35rem;
  }

  .col-name {
    min-width: 6.5rem;
  }

  .col-sku {
    min-width: 5rem;
  }

  .col-price {
    min-width: 5rem;
  }

  .col-stock,
  .col-min {
    min-width: 4.5rem;
  }

  .variants td.worth {
    white-space: normal;
  }
}

/* On tablets and phones each variant is a card (see .stack), so the cells lose their spacing. */
@media (max-width: 1000px) {
  .variants td,
  .variants td:first-child,
  .variants td:last-child,
  .variants td.worth {
    padding: 0;
  }

  .col-name,
  .col-sku,
  .col-price,
  .col-stock,
  .col-min {
    min-width: 0;
  }

  .variants .field-error {
    max-width: none;
  }
}

/* Opening stock of a product bought in packs: full packs, then what is loose. */
.shelf-inputs {
  display: grid;
  gap: 0.35rem;
  min-width: 9rem;
}

.shelf-inputs .with-unit {
  display: flex;
  align-items: center;
  gap: 0.35rem;
}

.shelf-inputs .unit {
  flex: none;
  font-size: 0.85rem;
  opacity: 0.8;
}
</style>
