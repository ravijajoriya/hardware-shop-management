const API_URL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api'

export class ApiError extends Error {
  // `errors` holds the per-field messages of a validation failure: { field: [message, ...] }.
  constructor(message, status, errors = {}) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.errors = errors
  }
}

export async function apiRequest(path, { method = 'GET', body, token } = {}) {
  const headers = { Accept: 'application/json' }
  const options = { method, headers }

  if (body instanceof FormData) {
    // The browser sets the multipart Content-Type itself, boundary included.
    options.body = body
  } else if (body !== undefined) {
    headers['Content-Type'] = 'application/json'
    options.body = JSON.stringify(body)
  }

  if (token) {
    headers.Authorization = `Bearer ${token}`
  }

  let response

  try {
    response = await fetch(`${API_URL}${path}`, options)
  } catch {
    throw new ApiError('Could not reach the server. Check that the API is running.', 0)
  }

  const data = response.status === 204 ? null : await response.json().catch(() => null)

  if (!response.ok) {
    throw new ApiError(
      data?.message ?? 'Something went wrong. Please try again.',
      response.status,
      data?.errors,
    )
  }

  return data
}
