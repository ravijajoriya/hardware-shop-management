// Browsers and systems by a telltale part of the user agent, most specific first: Edge and
// Opera also say "Chrome", Chrome says "Safari", and an iPhone says "Mac OS X".
const BROWSERS = [
  [/Edg(e|A|iOS)?\//, 'Edge'],
  [/OPR\/|Opera/, 'Opera'],
  [/SamsungBrowser/, 'Samsung Internet'],
  [/Firefox\/|FxiOS/, 'Firefox'],
  [/Chrome\/|CriOS/, 'Chrome'],
  [/Safari\//, 'Safari'],
]

const SYSTEMS = [
  [/Android/, 'Android'],
  [/iPad/, 'iPad'],
  [/iPhone|iPod/, 'iPhone'],
  [/Windows/, 'Windows'],
  [/CrOS/, 'Chromebook'],
  [/Macintosh|Mac OS X/, 'Mac'],
  [/Linux/, 'Linux'],
]

function match(list, userAgent) {
  return list.find(([pattern]) => pattern.test(userAgent))?.[1] ?? null
}

// "Chrome on Mac", from the browser's user agent; null when there is none to read.
export function deviceName(userAgent) {
  if (!userAgent) {
    return null
  }

  const browser = match(BROWSERS, userAgent)
  const system = match(SYSTEMS, userAgent)

  if (!browser && !system) {
    return 'Unknown device'
  }

  return [browser ?? 'A browser', system ? `on ${system}` : null].filter(Boolean).join(' ')
}

const ROUTES = {
  bill: (id) => ({ name: 'bill-show', params: { id } }),
  product: (id) => ({ name: 'product-show', params: { id } }),
  customer: (id) => ({ name: 'customer-show', params: { id } }),
  stock_entry: (id) => ({ name: 'stock-entry-show', params: { id } }),
  // Categories and brands have no page of their own, so their list opens instead.
  category: () => ({ name: 'categories' }),
  brand: () => ({ name: 'brands' }),
}

// Where to open what an entry is about, or null when there is nothing left to open.
export function subjectRoute(subject) {
  if (!subject?.exists) {
    return null
  }

  return ROUTES[subject.type]?.(subject.id) ?? null
}
