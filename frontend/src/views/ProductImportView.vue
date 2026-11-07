<script setup>
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useProductStore } from '@/stores/products'
import { downloadFile } from '@/utils/files'
import InfoTip from '@/components/InfoTip.vue'

// Adding many products at once from an Excel sheet: get the template, fill it in, check the
// file (nothing is saved), then import what is ready.
const store = useProductStore()

const templateBusy = ref(false)
const templateError = ref('')

const fileInput = ref(null)
const file = ref(null)
const checking = ref(false)
const importing = ref(false)
const error = ref('')

// What the last check found, and what the import added.
const report = ref(null)
const done = ref(null)

const hasProblems = computed(() => (report.value?.problems.length ?? 0) > 0)
const readyCount = computed(() => report.value?.ready.products ?? 0)

function plural(count, one, many = `${one}s`) {
  return `${count} ${count === 1 ? one : many}`
}

async function downloadTemplate() {
  templateBusy.value = true
  templateError.value = ''

  try {
    downloadFile(await store.fetchImportTemplate())
  } catch (e) {
    templateError.value = e.message
  } finally {
    templateBusy.value = false
  }
}

function errorOf(e) {
  return e.errors?.file?.[0] ?? Object.values(e.errors ?? {})[0]?.[0] ?? e.message
}

async function choose(event) {
  const [chosen] = event.target.files

  // Picking the same file again (after fixing it) still counts as a new choice.
  event.target.value = ''

  if (!chosen) {
    return
  }

  file.value = chosen
  report.value = null
  done.value = null
  await check()
}

async function check() {
  checking.value = true
  error.value = ''
  report.value = null

  try {
    report.value = await store.checkImport(file.value)
  } catch (e) {
    error.value = errorOf(e)
  } finally {
    checking.value = false
  }
}

async function runImport() {
  importing.value = true
  error.value = ''

  try {
    done.value = await store.importProducts(file.value, hasProblems.value)
    report.value = null
    file.value = null
  } catch (e) {
    error.value = errorOf(e)
  } finally {
    importing.value = false
  }
}

function chooseFile() {
  fileInput.value.click()
}

function rowsText(rows) {
  return rows.length === 1 ? `Row ${rows[0]}` : `Rows ${rows.join(', ')}`
}

const doneText = computed(() => {
  if (!done.value) {
    return ''
  }

  const { products, sizes, categories, brands, existing, left_out_rows: leftOut } = done.value
  const parts = [`Added ${plural(products, 'product')}`]

  if (sizes > products) {
    parts[0] += ` (${plural(sizes, 'size')})`
  }

  const added = [
    categories ? plural(categories, 'new category', 'new categories') : null,
    brands ? plural(brands, 'new brand') : null,
  ].filter(Boolean)

  if (added.length) {
    parts.push(`with ${added.join(' and ')}`)
  }

  const left = [
    existing ? `${existing} already in the app` : null,
    leftOut ? `${plural(leftOut, 'row')} with problems` : null,
  ].filter(Boolean)

  if (left.length) {
    parts.push(`left out ${left.join(' and ')}`)
  }

  return `${parts.join(', ')}.`
})
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">Import products</h1>
    <RouterLink class="btn" :to="{ name: 'products' }">Back to products</RouterLink>
  </div>

  <p class="intro">
    Add many products at once from an Excel sheet instead of typing each one. Nothing is saved until
    you have checked the file and chosen to import it.
  </p>

  <div v-if="done" class="alert-success done" role="status">
    <span>{{ doneText }}</span>
    <span class="done-links">
      <RouterLink :to="{ name: 'products' }">See the products</RouterLink>
      <button type="button" class="link-button" @click="done = null">Import another file</button>
    </span>
  </div>

  <section class="step" aria-labelledby="step-template">
    <h2 id="step-template">1. Get the template</h2>
    <p>
      It has the columns to fill in, an example sheet, and a Help sheet listing your units,
      categories and brands. A product in several sizes or colours gets one row for each, with the
      same product name.
    </p>
    <p class="columns">
      <strong>Needed:</strong> Product name, Category, Unit, Purchase price
      <InfoTip term="Purchase price" />, Selling price, Opening stock.
    </p>
    <p class="columns">
      <strong>Optional:</strong> Size or variant, Brand, Item code <InfoTip term="SKU" />, MRP
      <InfoTip term="MRP" />, Minimum stock <InfoTip term="Minimum stock" />, Shop or godown, Floor,
      Rack and Shelf <InfoTip term="Location code" />, HSN code <InfoTip term="HSN" />, GST rate
      <InfoTip term="GST" />, Description, Active.
    </p>
    <p class="columns">
      <strong>Bought in packs and sold loose,</strong> such as wire in 90 m coils sold by the metre?
      Fill in Bought in (coil) and Pack size (90). Then write what one coil costs, what one metre
      sells for, a Pack price for a full coil if it differs, the full coils you have in Opening
      stock and any loose metres in Loose stock.
    </p>
    <p v-if="templateError" class="alert-error" role="alert">{{ templateError }}</p>
    <button type="button" class="btn" :disabled="templateBusy" @click="downloadTemplate">
      {{ templateBusy ? 'Preparing…' : 'Download template' }}
    </button>
  </section>

  <section class="step" aria-labelledby="step-file">
    <h2 id="step-file">2. Upload your file</h2>
    <p>An Excel file (.xlsx or .xls) or a CSV, with up to 2,000 rows.</p>
    <div class="picker">
      <button type="button" class="btn" :disabled="checking || importing" @click="chooseFile">
        {{ file ? 'Choose another file' : 'Choose file' }}
      </button>
      <span class="file-name">{{ file ? file.name : 'No file chosen' }}</span>
      <input
        ref="fileInput"
        class="visually-hidden"
        type="file"
        accept=".xlsx,.xls,.csv"
        aria-label="Excel file of products"
        @change="choose"
      />
    </div>
    <p v-if="checking" class="checking">Checking the file…</p>
    <p v-if="error" class="alert-error" role="alert">{{ error }}</p>
  </section>

  <section v-if="report" class="step" aria-labelledby="step-check">
    <h2 id="step-check">3. Check and import</h2>

    <div class="stats">
      <div class="stat">
        <span class="stat-label">Ready to add</span>
        <span class="stat-value">{{ plural(report.ready.products, 'product') }}</span>
        <span v-if="report.ready.sizes > report.ready.products" class="stat-note">
          {{ plural(report.ready.sizes, 'size') }}
        </span>
      </div>
      <div class="stat">
        <span class="stat-label">Already in the app</span>
        <span class="stat-value">{{ report.existing.length }}</span>
        <span class="stat-note">Left out; nothing about them changes</span>
      </div>
      <div class="stat" :class="{ 'stat-problems': hasProblems }">
        <span class="stat-label">Rows with problems</span>
        <span class="stat-value">{{ report.problem_rows }}</span>
        <span v-if="report.left_out_rows > report.problem_rows" class="stat-note">
          {{ plural(report.left_out_rows, 'row') }} left out with their product
        </span>
      </div>
    </div>

    <p v-if="report.new_categories.length || report.new_brands.length" class="new-names">
      <template v-if="report.new_categories.length">
        <strong>New categories to add:</strong> {{ report.new_categories.join(', ') }}.
      </template>
      <template v-if="report.new_brands.length">
        <strong>New brands to add:</strong> {{ report.new_brands.join(', ') }}.
      </template>
    </p>

    <p v-if="report.ignored_columns.length" class="ignored">
      These columns are not used: {{ report.ignored_columns.join(', ') }}.
    </p>

    <div class="actions">
      <template v-if="readyCount > 0">
        <button type="button" class="btn btn-primary" :disabled="importing" @click="runImport">
          {{
            importing
              ? 'Importing…'
              : hasProblems
                ? `Import the ${plural(readyCount, 'ready product')}`
                : `Import ${plural(readyCount, 'product')}`
          }}
        </button>
        <span v-if="hasProblems" class="or">
          or fix the rows below and
          <button type="button" class="link-button" @click="chooseFile">
            choose the file again
          </button>
        </span>
      </template>
      <p v-else class="nothing">
        Nothing in this file can be added yet.
        <template v-if="hasProblems">
          Fix the rows below and
          <button type="button" class="link-button" @click="chooseFile">
            choose the file again</button
          >.
        </template>
      </p>
    </div>

    <div v-if="hasProblems" class="block">
      <h3>Problems to fix</h3>
      <div class="table-wrap">
        <table class="data-table stack problems">
          <thead>
            <tr>
              <th scope="col" class="num">Row</th>
              <th scope="col">Column</th>
              <th scope="col">Problem</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(problem, index) in report.problems" :key="index">
              <td class="num p-row">Row {{ problem.row }}</td>
              <td class="p-column">{{ problem.column }}</td>
              <td class="p-message">{{ problem.message }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div v-if="report.existing.length" class="block">
      <h3>Already in the app</h3>
      <ul class="existing">
        <li v-for="product in report.existing" :key="product.rows[0]">
          <span class="rows">{{ rowsText(product.rows) }}</span> {{ product.name }}
        </li>
      </ul>
    </div>
  </section>
</template>

<style scoped>
.intro {
  margin: -0.5rem 0 1.25rem;
  opacity: 0.85;
}

.done {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem 1rem;
  margin-bottom: 1.25rem;
}

.done-links {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem 1rem;
  font-weight: 600;
}

.step {
  margin-bottom: 1.25rem;
  padding: 1.25rem 1.5rem;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.step h2 {
  margin-bottom: 0.6rem;
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--color-heading);
}

.step > p {
  margin-bottom: 0.75rem;
}

.columns {
  font-size: 0.9rem;
}

.columns strong,
.new-names strong {
  color: var(--color-heading);
}

.picker {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
}

.file-name {
  min-width: 0;
  overflow-wrap: anywhere;
  opacity: 0.85;
}

.checking {
  margin-top: 0.75rem;
}

.alert-error {
  margin-top: 0.75rem;
}

.stats {
  margin-bottom: 1rem;
}

.stat-problems .stat-value {
  color: var(--color-danger);
}

.new-names,
.ignored {
  font-size: 0.9rem;
}

.new-names {
  margin-bottom: 0.5rem;
}

.ignored {
  margin-bottom: 0.5rem;
  opacity: 0.8;
}

.actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 1rem;
  margin: 1rem 0 0.5rem;
}

.or,
.nothing {
  font-size: 0.9rem;
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

.block {
  margin-top: 1.25rem;
}

.block h3 {
  margin-bottom: 0.6rem;
  font-size: 1rem;
  font-weight: 600;
  color: var(--color-heading);
}

.problems td.p-message {
  white-space: normal;
}

.p-row {
  font-weight: 600;
}

.existing {
  margin: 0;
  padding-left: 1.1rem;
}

.rows {
  margin-right: 0.35rem;
  font-weight: 600;
}

/* On a phone each problem is a short card: the row and column on one line, the problem below. */
@media (max-width: 640px) {
  .step {
    padding: 1rem;
  }

  .data-table.stack.problems tr {
    grid-template-columns: auto minmax(0, 1fr);
    grid-template-areas:
      'row column'
      'message message';
    gap: 0.15rem 0.75rem;
    padding: 0.6rem 0.9rem;
  }

  .data-table.stack td.p-row {
    grid-area: row;
  }

  .data-table.stack td.p-column {
    grid-area: column;
    opacity: 0.8;
  }

  .data-table.stack td.p-message {
    grid-area: message;
  }
}
</style>
