import { formatQuantity } from '@/utils/format'

// Products bought in packs and sold loose, such as wire bought in 90 m coils and sold by the
// metre. Stock is counted in the loose unit (metres); these turn it into what the shopkeeper
// sees on the shelf ("5 coils + 85 m") and back, so nobody has to convert.

const SAME_PLURAL = new Set(['gross'])

// "coil" and "coils", from a unit name such as "Coil", "Box" or "Gross".
export function packWord(unit, count = 1) {
  const word = unit.name.toLowerCase()

  if (count === 1 || SAME_PLURAL.has(word)) {
    return word
  }

  return /(s|x|ch|sh)$/.test(word) ? `${word}es` : `${word}s`
}

// "2 coils", "1 bag".
export function formatPacks(count, unit) {
  return `${formatQuantity(count)} ${packWord(unit, count)}`
}

function round(value, places) {
  const factor = 10 ** places

  return Math.round(value * factor) / factor
}

// Stock as it sits on the shelf: "5 coils + 85 m", "6 coils", "40 m". Without a pack, or when
// nothing is left, it is the plain quantity in the loose unit.
export function formatStock(quantity, unit, pack = null) {
  const amount = Number(quantity) || 0

  if (!pack || amount <= 0) {
    return formatQuantity(amount, unit)
  }

  const { packs, loose } = splitStock(amount, pack.size)
  const parts = []

  if (packs > 0) {
    parts.push(formatPacks(packs, pack.unit))
  }

  if (loose > 0) {
    parts.push(formatQuantity(loose, unit))
  }

  return parts.join(' + ')
}

// Full packs and what is left loose: 535 m in 90 m coils is 5 coils and 85 m.
export function splitStock(quantity, size) {
  const amount = Number(quantity) || 0
  const packs = Math.floor(round(amount / size, 6))

  return { packs, loose: round(amount - packs * size, 3) }
}

// Back to the loose unit: 5 coils and 85 m are 535 m.
export function joinStock(packs, loose, size) {
  return round((Number(packs) || 0) * size + (Number(loose) || 0), 3)
}

// What a full pack sells for: its own price, or else the loose price times the pack size.
export function fullPackPrice(variant, pack) {
  if (
    variant.pack_price !== null &&
    variant.pack_price !== undefined &&
    variant.pack_price !== ''
  ) {
    return Number(variant.pack_price)
  }

  return round(Number(variant.selling_price) * pack.size, 2)
}

// A cost per loose unit as the price of a pack: ₹11.1111 a metre is ₹1,000 a 90 m coil.
export function costPerPack(costPerUnit, size) {
  return round(Number(costPerUnit) * size, 2)
}

// A pack's price as a cost per loose unit, to four decimals so it divides back exactly.
export function costPerUnit(costPerPack, size) {
  return round(Number(costPerPack) / size, 4)
}

// "1 coil = 90 m"
export function packLabel(pack, unit) {
  return `1 ${packWord(pack.unit)} = ${formatQuantity(pack.size, unit)}`
}
