<script setup>
import { computed } from 'vue'
import { formatMoney, formatQuantity } from '@/utils/format'
import { rowWorth, variantRow } from '@/utils/productForm'
import InfoTip from '@/components/InfoTip.vue'

const rows = defineModel({ type: Array, required: true })

const props = defineProps({
  // The product's unit; it decides whether stock may have decimals.
  unit: { type: Object, default: null },
  // Validation messages keyed the way the API sends them, such as "variants.0.selling_price".
  errors: { type: Object, default: () => ({}) },
})

const stockStep = computed(() => (props.unit?.allow_decimal ? '0.001' : '1'))

const totals = computed(() =>
  rows.value.reduce(
    (sum, row) => {
      const worth = rowWorth(row)

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
  rows.value.push(variantRow())
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
    <table class="data-table variants">
      <thead>
        <tr>
          <th scope="col">Variant</th>
          <th scope="col">SKU <InfoTip term="SKU" /></th>
          <th scope="col">Purchase price (₹)</th>
          <th scope="col">Selling price (₹)</th>
          <th scope="col">MRP (₹) <InfoTip term="MRP" /></th>
          <th scope="col">Stock{{ unit ? ` (${unit.short_name})` : '' }}</th>
          <th scope="col" class="num">Stock worth</th>
          <th scope="col"><span class="visually-hidden">Remove</span></th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="(row, index) in rows" :key="row.key">
          <td class="col-name">
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
            <input
              v-model="row.purchase_price"
              class="input"
              type="number"
              min="0"
              step="0.01"
              required
              :aria-label="`Variant ${index + 1} purchase price`"
            />
            <p v-if="errorFor(index, 'purchase_price')" class="field-error">
              {{ errorFor(index, 'purchase_price') }}
            </p>
          </td>
          <td class="col-price">
            <input
              v-model="row.selling_price"
              class="input"
              type="number"
              min="0"
              step="0.01"
              required
              :aria-label="`Variant ${index + 1} selling price`"
            />
            <p v-if="errorFor(index, 'selling_price')" class="field-error">
              {{ errorFor(index, 'selling_price') }}
            </p>
          </td>
          <td class="col-price">
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
            <input
              v-model="row.stock_quantity"
              class="input"
              type="number"
              min="0"
              :step="stockStep"
              required
              :aria-label="`Variant ${index + 1} stock`"
            />
            <p v-if="errorFor(index, 'stock_quantity')" class="field-error">
              {{ errorFor(index, 'stock_quantity') }}
            </p>
          </td>
          <td class="num worth">
            <div>{{ formatMoney(rowWorth(row).cost) }}</div>
            <div class="sells-for">sells for {{ formatMoney(rowWorth(row).selling) }}</div>
          </td>
          <td>
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

  <div class="footer">
    <button type="button" class="btn" @click="add">Add variant</button>
    <p class="totals">
      Total stock <strong>{{ formatQuantity(totals.stock, unit) }}</strong> · worth
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

.col-stock {
  min-width: 6rem;
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
</style>
