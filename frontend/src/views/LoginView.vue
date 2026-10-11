<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const router = useRouter()

const email = ref('')
const password = ref('')
const error = ref('')
const submitting = ref(false)

async function submit() {
  error.value = ''
  submitting.value = true

  try {
    await auth.login(email.value, password.value)
    await router.replace({ name: 'dashboard' })
  } catch (e) {
    error.value = e.message
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <main class="login">
    <form class="card" @submit.prevent="submit">
      <h1>Admin login</h1>
      <p class="subtitle">Hardware Shop Management</p>

      <p v-if="error" class="error" role="alert">{{ error }}</p>

      <label for="email">Email</label>
      <input id="email" v-model="email" type="email" autocomplete="username" required autofocus />

      <label for="password">Password</label>
      <input
        id="password"
        v-model="password"
        type="password"
        autocomplete="current-password"
        required
      />

      <button type="submit" :disabled="submitting">
        {{ submitting ? 'Logging in…' : 'Log in' }}
      </button>
    </form>
  </main>
</template>

<style scoped>
.login {
  min-height: 100vh;
  display: grid;
  place-items: center;
  padding: 1.5rem;
}

.card {
  width: 100%;
  max-width: 380px;
  padding: 2rem;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

h1 {
  font-size: 1.5rem;
  font-weight: 600;
  color: var(--color-heading);
}

.subtitle {
  margin-bottom: 1.5rem;
}

.error {
  margin-bottom: 1rem;
  padding: 0.6rem 0.75rem;
  border: 1px solid hsla(0, 70%, 50%, 0.4);
  border-radius: 6px;
  background: hsla(0, 70%, 50%, 0.1);
  color: hsl(0, 70%, 45%);
}

label {
  display: block;
  margin-bottom: 0.25rem;
  font-weight: 500;
}

input {
  display: block;
  width: 100%;
  margin-bottom: 1rem;
  padding: 0.6rem 0.75rem;
  font: inherit;
  color: var(--color-text);
  background: var(--color-background);
  border: 1px solid var(--color-border-hover);
  border-radius: 6px;
}

input:focus {
  outline: 2px solid hsla(160, 100%, 37%, 0.6);
  outline-offset: 1px;
}

button {
  width: 100%;
  margin-top: 0.5rem;
  padding: 0.65rem 1rem;
  font: inherit;
  font-weight: 600;
  color: var(--vt-c-white);
  background: hsla(160, 100%, 32%, 1);
  border: 0;
  border-radius: 6px;
  cursor: pointer;
}

button:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

@media (prefers-color-scheme: dark) {
  .error {
    color: hsl(0, 80%, 72%);
  }
}
</style>
