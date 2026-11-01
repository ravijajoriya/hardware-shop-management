// Where a product is kept: shop or godown, then floor, rack and shelf. The backend builds and
// saves the location code; `locationCode` mirrors it so the form can show the code while typing.

export const AREAS = [
  { value: 'shop', label: 'Shop' },
  { value: 'godown', label: 'Godown' },
]

export const MAX_FLOOR = 20

export const MAX_SHELF = 99

function isSet(value) {
  return value !== null && value !== undefined && value !== ''
}

// 0 → "Ground floor", 1 → "1st floor", 2 → "2nd floor", 11 → "11th floor".
export function floorName(floor) {
  const n = Number(floor)

  if (n === 0) {
    return 'Ground floor'
  }

  const lastTwo = n % 100
  const suffix =
    lastTwo >= 11 && lastTwo <= 13 ? 'th' : ({ 1: 'st', 2: 'nd', 3: 'rd' }[n % 10] ?? 'th')

  return `${n}${suffix} floor`
}

// SHOP-GF-R3-S2: SHOP or GODOWN, the floor (GF = ground, 1F, 2F …), R + rack, S + shelf.
export function locationCode({ area, floor, rack, shelf }) {
  if (!area) {
    return ''
  }

  const parts = [area.toUpperCase()]

  if (isSet(floor)) {
    parts.push(Number(floor) === 0 ? 'GF' : `${Number(floor)}F`)
  }

  if (isSet(rack)) {
    parts.push(`R${String(rack).trim().toUpperCase()}`)
  }

  if (isSet(shelf)) {
    parts.push(`S${Number(shelf)}`)
  }

  return parts.join('-')
}

// The same place in words: "Shop · Ground floor · Rack 3 · Shelf 2".
export function locationLabel({ area, floor, rack, shelf }) {
  const parts = [AREAS.find((option) => option.value === area)?.label ?? '']

  if (isSet(floor)) {
    parts.push(floorName(floor))
  }

  if (isSet(rack)) {
    parts.push(`Rack ${String(rack).trim().toUpperCase()}`)
  }

  if (isSet(shelf)) {
    parts.push(`Shelf ${Number(shelf)}`)
  }

  return parts.filter(Boolean).join(' · ')
}
