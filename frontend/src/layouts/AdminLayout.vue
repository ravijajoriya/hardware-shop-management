<script setup>
import { ref, watch } from 'vue'
import { RouterLink, RouterView, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const loggingOut = ref(false)

// Covers the Log out button as well as a token the server stops accepting.
watch(
  () => auth.isAuthenticated,
  (isAuthenticated) => {
    if (!isAuthenticated) {
      router.replace({ name: 'login' })
    }
  },
)

async function logout() {
  loggingOut.value = true

  await auth.logout()
}
</script>

<template>
  <div class="admin">
    <header class="topbar">
      <span class="brand">Hardware Shop Management</span>

      <nav class="nav" aria-label="Main">
        <RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink>
        <RouterLink :to="{ name: 'categories' }">Categories</RouterLink>
        <RouterLink :to="{ name: 'brands' }">Brands</RouterLink>
      </nav>

      <div class="account">
        <span v-if="auth.user">{{ auth.user.name }}</span>
        <button type="button" class="btn" :disabled="loggingOut" @click="logout">
          {{ loggingOut ? 'Logging out…' : 'Log out' }}
        </button>
      </div>
    </header>

    <main class="content">
      <RouterView />
    </main>
  </div>
</template>

<style scoped>
.topbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem 1.5rem;
  padding: 0.75rem 1.5rem;
  background: var(--color-background-soft);
  border-bottom: 1px solid var(--color-border);
}

.brand {
  font-weight: 600;
  color: var(--color-heading);
}

.nav {
  display: flex;
  gap: 0.25rem;
}

.nav a {
  padding: 0.35rem 0.75rem;
  color: var(--color-text);
  border-radius: 6px;
}

.nav a.router-link-exact-active {
  font-weight: 600;
  color: var(--color-heading);
  background: var(--color-background-mute);
}

.account {
  display: flex;
  align-items: center;
  gap: 1rem;
  margin-left: auto;
}

.content {
  max-width: 1100px;
  margin: 0 auto;
  padding: 2rem 1.5rem;
}
</style>
