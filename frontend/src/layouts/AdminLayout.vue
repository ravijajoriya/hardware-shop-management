<script setup>
import { ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth = useAuthStore()
const route = useRoute()
const router = useRouter()

const loggingOut = ref(false)

// On tablets and phones the menu folds away behind a button, and folds again on each new page.
const menuOpen = ref(false)

watch(
  () => route.fullPath,
  () => {
    menuOpen.value = false
  },
)

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
    <header class="topbar no-print">
      <div class="bar">
        <span class="brand">Hardware Shop Management</span>
        <button
          type="button"
          class="btn menu-button"
          aria-controls="main-menu"
          :aria-expanded="menuOpen"
          @click="menuOpen = !menuOpen"
        >
          {{ menuOpen ? 'Close' : 'Menu' }}
        </button>
      </div>

      <div id="main-menu" class="menu" :class="{ open: menuOpen }" @keydown.esc="menuOpen = false">
        <nav class="nav" aria-label="Main">
          <RouterLink :to="{ name: 'dashboard' }">Dashboard</RouterLink>
          <RouterLink :to="{ name: 'bills' }" :class="{ active: route.meta.section === 'bills' }">
            Bills
          </RouterLink>
          <RouterLink
            :to="{ name: 'customers' }"
            :class="{ active: route.meta.section === 'customers' }"
          >
            Customers
          </RouterLink>
          <RouterLink
            :to="{ name: 'stock-entries' }"
            :class="{ active: route.meta.section === 'stock' }"
          >
            Stock in
          </RouterLink>
          <RouterLink
            :to="{ name: 'products' }"
            :class="{ active: route.meta.section === 'products' }"
          >
            Products
          </RouterLink>
          <RouterLink :to="{ name: 'categories' }">Categories</RouterLink>
          <RouterLink :to="{ name: 'brands' }">Brands</RouterLink>
          <RouterLink :to="{ name: 'reports' }">Reports</RouterLink>
          <RouterLink :to="{ name: 'activity' }">Activity log</RouterLink>
        </nav>

        <div class="account">
          <span v-if="auth.user">{{ auth.user.name }}</span>
          <button type="button" class="btn" :disabled="loggingOut" @click="logout">
            {{ loggingOut ? 'Logging out…' : 'Log out' }}
          </button>
        </div>
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

/* On a wide screen the name, the menu and the account sit in one bar, with no Menu button. */
.bar,
.menu {
  display: contents;
}

.menu-button {
  display: none;
}

.nav {
  display: flex;
  flex-wrap: wrap;
  gap: 0.25rem;
}

.nav a {
  padding: 0.35rem 0.75rem;
  color: var(--color-text);
  border-radius: 6px;
}

/* "active" also marks a section while on its other pages, such as Products on the product form. */
.nav a.router-link-exact-active,
.nav a.active {
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
  max-width: 1200px;
  margin: 0 auto;
  padding: 2rem 1.5rem;
}

@media (max-width: 1000px) {
  .topbar {
    display: block;
    padding: 0.6rem 1rem;
  }

  .bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
  }

  .menu-button {
    display: inline-flex;
  }

  .menu {
    display: none;
  }

  .menu.open {
    display: block;
    padding-top: 0.75rem;
  }

  .nav {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(9rem, 1fr));
  }

  .nav a {
    padding: 0.6rem 0.75rem;
  }

  .account {
    justify-content: space-between;
    margin-top: 0.75rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--color-border);
  }
}

@media (max-width: 480px) {
  .content {
    padding: 1.25rem 1rem;
  }
}

@media print {
  .content {
    max-width: none;
    padding: 0;
  }
}
</style>
