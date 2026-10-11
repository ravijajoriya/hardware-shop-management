<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const loggingOut = ref(false)

async function logout() {
  loggingOut.value = true

  await auth.logout()
  await router.replace({ name: 'login' })
}
</script>

<template>
  <div class="dashboard">
    <header class="topbar">
      <span class="brand">Hardware Shop Management</span>

      <div class="account">
        <span v-if="auth.user">{{ auth.user.name }}</span>
        <button type="button" :disabled="loggingOut" @click="logout">
          {{ loggingOut ? 'Logging out…' : 'Log out' }}
        </button>
      </div>
    </header>

    <main class="content">
      <h1>Dashboard</h1>
      <p v-if="auth.user">You are logged in as {{ auth.user.email }}.</p>
    </main>
  </div>
</template>

<style scoped>
.topbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  padding: 0.75rem 1.5rem;
  background: var(--color-background-soft);
  border-bottom: 1px solid var(--color-border);
}

.brand {
  font-weight: 600;
  color: var(--color-heading);
}

.account {
  display: flex;
  align-items: center;
  gap: 1rem;
}

button {
  padding: 0.4rem 0.9rem;
  font: inherit;
  color: var(--color-text);
  background: var(--color-background);
  border: 1px solid var(--color-border-hover);
  border-radius: 6px;
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.content {
  padding: 2rem 1.5rem;
}

h1 {
  margin-bottom: 0.5rem;
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--color-heading);
}
</style>
