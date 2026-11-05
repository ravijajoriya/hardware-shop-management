<script setup>
import { reactive, ref } from 'vue'
import { useAuthStore } from '@/stores/auth'

const MIN_LENGTH = 10

const auth = useAuthStore()

const form = reactive({ current: '', password: '', again: '' })
const saving = ref(false)
const error = ref('')
const fieldErrors = ref({})
const done = ref('')

async function save() {
  saving.value = true
  error.value = ''
  fieldErrors.value = {}
  done.value = ''

  try {
    const others = await auth.changePassword(form.current, form.password, form.again)

    form.current = form.password = form.again = ''
    done.value =
      others > 0
        ? `Password changed. You were logged out on ${others} other ${others === 1 ? 'device' : 'devices'}.`
        : 'Password changed.'
  } catch (e) {
    fieldErrors.value = e.errors ?? {}

    if (!Object.keys(fieldErrors.value).length) {
      error.value = e.message
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="page-header">
    <h1 class="page-title">Your account</h1>
  </div>

  <dl v-if="auth.user" class="who">
    <dt>Name</dt>
    <dd>{{ auth.user.name }}</dd>
    <dt>Email</dt>
    <dd>{{ auth.user.email }}</dd>
  </dl>

  <form class="card" @submit.prevent="save">
    <h2>Change password</h2>
    <p class="intro">
      This also logs you out on every other phone and computer, so anyone who knew the old password
      is shut out. You stay logged in here.
    </p>

    <p v-if="done" class="alert-success" role="status">{{ done }}</p>
    <p v-if="error" class="alert-error" role="alert">{{ error }}</p>

    <div class="field">
      <label for="current-password">Current password</label>
      <input
        id="current-password"
        v-model="form.current"
        class="input"
        type="password"
        autocomplete="current-password"
        required
      />
      <p v-if="fieldErrors.current_password" class="field-error">
        {{ fieldErrors.current_password[0] }}
      </p>
    </div>

    <div class="field">
      <label for="new-password">New password</label>
      <input
        id="new-password"
        v-model="form.password"
        class="input"
        type="password"
        autocomplete="new-password"
        :minlength="MIN_LENGTH"
        required
      />
      <p v-if="fieldErrors.password" class="field-error">{{ fieldErrors.password[0] }}</p>
      <p v-else class="hint">
        At least {{ MIN_LENGTH }} characters. A few unrelated words make a strong one that is easy
        to remember.
      </p>
    </div>

    <div class="field">
      <label for="new-password-again">New password again</label>
      <input
        id="new-password-again"
        v-model="form.again"
        class="input"
        type="password"
        autocomplete="new-password"
        required
      />
    </div>

    <button type="submit" class="btn btn-primary" :disabled="saving">
      {{ saving ? 'Changing…' : 'Change password' }}
    </button>
  </form>
</template>

<style scoped>
.who {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 0.25rem 1rem;
  max-width: 32rem;
  margin: 0 0 1.25rem;
}

.who dt {
  font-weight: 600;
  color: var(--color-heading);
}

.who dd {
  min-width: 0;
  margin: 0;
  overflow-wrap: anywhere;
}

.card {
  max-width: 32rem;
  padding: 1.25rem 1.5rem;
  background: var(--color-background-soft);
  border: 1px solid var(--color-border);
  border-radius: 8px;
}

.card h2 {
  margin-bottom: 0.4rem;
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--color-heading);
}

.intro {
  margin-bottom: 1rem;
  font-size: 0.9rem;
  opacity: 0.85;
}

.alert-success,
.alert-error {
  margin-bottom: 1rem;
}

@media (max-width: 640px) {
  .card {
    padding: 1rem;
  }
}
</style>
