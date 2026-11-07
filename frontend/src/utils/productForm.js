import { costPerPack, costPerUnit, joinStock, splitStock } from '@/utils/packs'

let nextKey = 1

// The brand choice meaning "a brand that is not in the list yet"; its name is typed instead.
export const NEW_BRAND = 'new'

function round(value, places) {
  const factor = 10 ** places

  return Math.round(Number(value) * factor) / factor
}

function filled(value) {
  return value !== null && value !== undefined && String(value).trim() !== ''
}

// A row in the variants editor. `key` only exists in the browser, to keep rows apart. Values
// are in the shopkeeper's units: with a pack (`pack`, such as a 90 m coil), the purchase price
// is a coil's, the minimum stock is in coils and a new row's opening stock is coils plus metres.
// `stock_quantity` is always in loose units.
export function variantRow(variant = {}, pack = null) {
  const size = pack?.size ?? null
  const shelf = size ? splitStock(variant.stock_quantity ?? 0, size) : { packs: 0, loose: 0 }

  return {
    key: nextKey++,
    id: variant.id ?? null,
    name: variant.name ?? '',
    sku: variant.sku ?? '',
    purchase_price: filled(variant.purchase_price)
      ? size
        ? costPerPack(variant.purchase_price, size)
        : round(variant.purchase_price, 2)
      : '',
    selling_price: variant.selling_price ?? '',
    pack_price: variant.pack_price ?? '',
    mrp: variant.mrp ?? '',
    stock_quantity: variant.stock_quantity ?? 0,
    stock_packs: shelf.packs,
    stock_loose: shelf.loose,
    min_stock: filled(variant.min_stock)
      ? size
        ? round(variant.min_stock / size, 3)
        : variant.min_stock
      : '',
  }
}

// Ticking "bought in packs" turns what is typed into the shopkeeper's pack units: a cost per
// metre becomes a coil's, the minimum is counted in coils and opening stock in coils plus metres.
export function rowsToPacks(rows, size) {
  for (const row of rows) {
    if (filled(row.purchase_price)) {
      row.purchase_price = costPerPack(row.purchase_price, size)
    }

    if (filled(row.min_stock)) {
      row.min_stock = round(row.min_stock / size, 3)
    }

    if (!row.id) {
      const shelf = splitStock(row.stock_quantity, size)
      row.stock_packs = shelf.packs
      row.stock_loose = shelf.loose
    }
  }
}

// Unticking it turns them back into loose units.
export function rowsToLoose(rows, size) {
  for (const row of rows) {
    if (filled(row.purchase_price)) {
      row.purchase_price = round(Number(row.purchase_price) / size, 2)
    }

    if (filled(row.min_stock)) {
      row.min_stock = round(row.min_stock * size, 3)
    }

    if (!row.id) {
      row.stock_quantity = joinStock(row.stock_packs, row.stock_loose, size)
    }
  }
}

// A changed pack size keeps what each loose unit costs and how much stock and minimum there
// is: a ₹1,000 coil of 90 m becomes ₹1,111.11 as a coil of 100 m.
export function rowsResized(rows, from, to) {
  for (const row of rows) {
    if (filled(row.purchase_price)) {
      row.purchase_price = round((Number(row.purchase_price) * to) / from, 2)
    }

    if (filled(row.min_stock)) {
      row.min_stock = round((Number(row.min_stock) * from) / to, 3)
    }

    if (!row.id) {
      const shelf = splitStock(joinStock(row.stock_packs, row.stock_loose, from), to)
      row.stock_packs = shelf.packs
      row.stock_loose = shelf.loose
    }
  }
}

// A row's stock in loose units: a new row's opening stock, or a saved row's current stock.
export function rowStock(row, pack = null) {
  if (!row.id && pack) {
    return joinStock(row.stock_packs, row.stock_loose, pack.size)
  }

  return Number(row.stock_quantity) || 0
}

function blankToNull(value) {
  const trimmed = typeof value === 'string' ? value.trim() : value

  return trimmed === '' || trimmed === undefined ? null : trimmed
}

function numberOrNull(value) {
  const present = blankToNull(value)

  return present === null ? null : Number(present)
}

// The body the API expects when creating or updating a product. The API counts everything in
// loose units, so with a pack the shopkeeper's coil prices and counts are turned into metres.
export function productPayload(form, rows, pack = null) {
  const addingBrand = form.brand_id === NEW_BRAND
  const size = pack?.size ?? null

  return {
    name: form.name.trim(),
    slug: blankToNull(form.slug),
    category_id: numberOrNull(form.category_id),
    brand_id: addingBrand ? null : numberOrNull(form.brand_id),
    new_brand_name: addingBrand ? blankToNull(form.new_brand_name) : null,
    unit_id: numberOrNull(form.unit_id),
    pack_unit_id: size ? numberOrNull(form.pack_unit_id) : null,
    pack_size: size,
    description: blankToNull(form.description),
    hsn_code: blankToNull(form.hsn_code),
    gst_rate: numberOrNull(form.gst_rate),
    location_area: form.location_area,
    location_floor: numberOrNull(form.location_floor),
    location_rack: blankToNull(form.location_rack)?.toUpperCase() ?? null,
    location_shelf: numberOrNull(form.location_shelf),
    is_active: form.is_active,
    variants: rows.map((row) => {
      const purchase = numberOrNull(row.purchase_price)
      const minimum = numberOrNull(row.min_stock)
      const variant = {
        name: blankToNull(row.name),
        sku: blankToNull(row.sku),
        purchase_price: size && purchase !== null ? costPerUnit(purchase, size) : purchase,
        selling_price: numberOrNull(row.selling_price),
        pack_price: size ? numberOrNull(row.pack_price) : null,
        mrp: numberOrNull(row.mrp),
        min_stock: size && minimum !== null ? round(minimum * size, 3) : minimum,
      }

      // Existing variants are matched by id, and their stock is left alone (it changes through
      // Stock in and corrections). Rows without an id become new variants with opening stock.
      if (row.id) {
        return { id: row.id, ...variant }
      }

      return {
        ...variant,
        stock_quantity: size
          ? joinStock(row.stock_packs, row.stock_loose, size)
          : numberOrNull(row.stock_quantity),
      }
    }),
  }
}

// An image in the form: one already saved, or a newly chosen file shown from a local preview URL.
export function savedImageItem(image) {
  return { key: nextKey++, id: image.id, url: image.url, file: null }
}

export function fileImageItem(file) {
  return { key: nextKey++, id: null, url: URL.createObjectURL(file), file }
}

// What saving the images involves: saved images that were removed, and new files to upload.
export function imageChanges(savedImages, items) {
  const keptIds = new Set(items.filter((item) => item.id).map((item) => item.id))

  return {
    deleteIds: savedImages.map((image) => image.id).filter((id) => !keptIds.has(id)),
    files: items.filter((item) => item.file).map((item) => item.file),
  }
}

// The image ids in display order, once the new files have ids (given in upload order).
export function imageOrder(items, uploadedIds) {
  let next = 0

  return items.map((item) => (item.file ? uploadedIds[next++] : item.id))
}

// Whether a stock is below the minimum the shop wants to keep. No minimum means no warning.
export function isBelowMinimum(stock, minimum) {
  const least = blankToNull(minimum)

  return least !== null && Number(stock) < Number(least)
}

// What one editor row's stock is worth, for the live totals. With a pack the purchase price
// typed is a pack's, so it is shared out over the loose units in it.
export function rowWorth(row, pack = null) {
  const stock = rowStock(row, pack)
  const cost = (Number(row.purchase_price) || 0) / (pack?.size ?? 1)

  return {
    stock,
    cost: stock * cost,
    selling: stock * (Number(row.selling_price) || 0),
  }
}
