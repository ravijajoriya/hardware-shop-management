// "?search=ham&page=2" from list filters, leaving out the ones that are not set.
export function toQueryString(filters = {}) {
  const query = new URLSearchParams()

  for (const [key, value] of Object.entries(filters)) {
    if (value !== '' && value !== null && value !== undefined) {
      query.set(key, value)
    }
  }

  const search = query.toString()

  return search ? `?${search}` : ''
}
