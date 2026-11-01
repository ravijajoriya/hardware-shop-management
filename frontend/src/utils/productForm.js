let nextKey = 1

// The brand choice meaning "a brand that is not in the list yet"; its name is typed instead.
export const NEW_BRAND = 'new'

// A row in the variants editor. `key` only exists in the browser, to keep rows apart.
export function variantRow(variant = {}) {
  return {
    key: nextKey++,
    id: variant.id ?? null,
    name: variant.name ?? '',
    sku: variant.sku ?? '',
    purchase_price: variant.purchase_price ?? '',
    selling_price: variant.selling_price ?? '',
    mrp: variant.mrp ?? '',
    stock_quantity: variant.stock_quantity ?? 0,
  }
}

function blankToNull(value) {
  const trimmed = typeof value === 'string' ? value.trim() : value

  return trimmed === '' || trimmed === undefined ? null : trimmed
}

function numberOrNull(value) {
  const present = blankToNull(value)

  return present === null ? null : Number(present)
}

// The body the API expects when creating or updating a product.
export function productPayload(form, rows) {
  const addingBrand = form.brand_id === NEW_BRAND

  return {
    name: form.name.trim(),
    slug: blankToNull(form.slug),
    category_id: numberOrNull(form.category_id),
    brand_id: addingBrand ? null : numberOrNull(form.brand_id),
    new_brand_name: addingBrand ? blankToNull(form.new_brand_name) : null,
    unit_id: numberOrNull(form.unit_id),
    description: blankToNull(form.description),
    hsn_code: blankToNull(form.hsn_code),
    gst_rate: numberOrNull(form.gst_rate),
    location_area: form.location_area,
    location_floor: numberOrNull(form.location_floor),
    location_rack: blankToNull(form.location_rack)?.toUpperCase() ?? null,
    location_shelf: numberOrNull(form.location_shelf),
    is_active: form.is_active,
    variants: rows.map((row) => {
      const variant = {
        name: blankToNull(row.name),
        sku: blankToNull(row.sku),
        purchase_price: numberOrNull(row.purchase_price),
        selling_price: numberOrNull(row.selling_price),
        mrp: numberOrNull(row.mrp),
        stock_quantity: numberOrNull(row.stock_quantity),
      }

      // Existing variants are matched by id; rows without one become new variants.
      return row.id ? { id: row.id, ...variant } : variant
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

// What one editor row's stock is worth, for the live totals.
export function rowWorth(row) {
  const stock = Number(row.stock_quantity) || 0

  return {
    stock,
    cost: stock * (Number(row.purchase_price) || 0),
    selling: stock * (Number(row.selling_price) || 0),
  }
}
