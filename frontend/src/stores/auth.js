import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { apiRequest } from '@/services/api'

const TOKEN_KEY = 'admin_token'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem(TOKEN_KEY))
  const user = ref(null)
  const isAuthenticated = computed(() => token.value !== null)

  function clearSession() {
    token.value = null
    user.value = null
    localStorage.removeItem(TOKEN_KEY)
  }

  async function login(email, password) {
    const data = await apiRequest('/admin/login', { method: 'POST', body: { email, password } })

    token.value = data.token
    user.value = data.user
    localStorage.setItem(TOKEN_KEY, data.token)
  }

  // Loads the admin for a token kept from an earlier visit.
  async function restoreSession() {
    try {
      user.value = await apiRequest('/admin/me', { token: token.value })
    } catch (error) {
      // Only a rejected token ends the session; an unreachable server should not log the admin out.
      if (error.status === 401 || error.status === 403) {
        clearSession()
      }
    }
  }

  async function logout() {
    try {
      await apiRequest('/admin/logout', { method: 'POST', token: token.value })
    } catch {
      // The session still ends on this device when the server cannot be told.
    } finally {
      clearSession()
    }
  }

  return { token, user, isAuthenticated, login, restoreSession, logout }
})
