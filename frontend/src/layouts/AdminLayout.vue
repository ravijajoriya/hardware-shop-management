<script setup>
import { computed, nextTick, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useMediaQuery } from '@/composables/useMediaQuery'
import { useScrollLock } from '@/composables/useScrollLock'

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

// On a phone the menu is a panel that slides in from the left, over the page.
const isPhone = useMediaQuery('(max-width: 640px)')
const drawerOpen = computed(() => menuOpen.value && isPhone.value)

const menuButton = ref(null)
const drawerClose = ref(null)

// While the panel is open the page behind it stays still, and focus moves into the panel
// and back to the menu button when it closes.
useScrollLock(drawerOpen)

watch(drawerOpen, async (open, wasOpen) => {
  await nextTick()

  if (open) {
    drawerClose.value?.focus()
  } else if (wasOpen) {
    menuButton.value?.focus()
  }
})

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
      <div class="bar" :inert="drawerOpen || undefined">
        <span class="brand">Hardware Shop Management</span>
        <button
          ref="menuButton"
          type="button"
          class="btn menu-button"
          aria-controls="main-menu"
          :aria-expanded="menuOpen"
          @click="menuOpen = !menuOpen"
        >
          <svg class="menu-icon" viewBox="0 0 24 24" aria-hidden="true">
            <path v-if="menuOpen" d="M6 6l12 12M18 6L6 18" />
            <path v-else d="M4 7h16M4 12h16M4 17h16" />
          </svg>
          <span class="menu-text">{{ menuOpen ? 'Close' : 'Menu' }}</span>
        </button>
        <RouterLink
          v-if="auth.user"
          :to="{ name: 'account' }"
          class="bar-user"
          title="Your account and password"
        >
          {{ auth.user.name }}
        </RouterLink>
      </div>

      <div id="main-menu" class="menu" :class="{ open: menuOpen }" @keydown.esc="menuOpen = false">
        <div class="drawer-head">
          <span class="drawer-brand">Hardware Shop Management</span>
          <button
            ref="drawerClose"
            type="button"
            class="btn drawer-close"
            aria-label="Close menu"
            @click="menuOpen = false"
          >
            <svg class="menu-icon" viewBox="0 0 24 24" aria-hidden="true">
              <path d="M6 6l12 12M18 6L6 18" />
            </svg>
          </button>
        </div>

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
          <RouterLink
            v-if="auth.user"
            :to="{ name: 'account' }"
            class="account-name"
            title="Your account and password"
          >
            {{ auth.user.name }}
          </RouterLink>
          <button type="button" class="btn" :disabled="loggingOut" @click="logout">
            {{ loggingOut ? 'Logging out…' : 'Log out' }}
          </button>
        </div>
      </div>

      <div v-if="drawerOpen" class="menu-backdrop" aria-hidden="true" @click="menuOpen = false" />
    </header>

    <main class="content" :inert="drawerOpen || undefined">
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

.menu-button,
.bar-user,
.menu-icon,
.drawer-head {
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

/* The admin's name opens their account page, where the password is changed. */
.account-name,
.bar-user {
  color: var(--color-heading);
  border-radius: 6px;
}

.account-name.router-link-exact-active,
.bar-user.router-link-exact-active {
  font-weight: 600;
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

/*
 * On a phone: the menu button is three lines in the top-left corner (a cross while open),
 * the shop name beside it, and the admin's name in the top-right corner.
 */
@media (max-width: 640px) {
  .bar {
    justify-content: flex-start;
    gap: 0.6rem;
  }

  .menu-button {
    order: -1;
    width: 2.5rem;
    height: 2.5rem;
    padding: 0;
    justify-content: center;
    align-items: center;
    flex: none;
  }

  .menu-icon {
    display: block;
    width: 1.4rem;
    height: 1.4rem;
    fill: none;
    stroke: currentColor;
    stroke-width: 2;
    stroke-linecap: round;
  }

  .menu-text {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
  }

  .brand {
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  .bar-user {
    display: block;
    flex: none;
    max-width: 35%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--color-heading);
  }

  /* The menu: a panel that slides in from the left over a dimmed page. */
  .menu {
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 50;
    display: flex;
    flex-direction: column;
    width: min(18rem, 85vw);
    background: var(--color-background-soft);
    border-right: 1px solid var(--color-border);
    box-shadow: 4px 0 24px rgba(0, 0, 0, 0.35);
    transform: translateX(-100%);
    visibility: hidden;
    transition:
      transform 0.25s ease,
      visibility 0s linear 0.25s;
  }

  .menu.open {
    display: flex;
    padding-top: 0;
    transform: none;
    visibility: visible;
    transition: transform 0.25s ease;
  }

  .drawer-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.6rem 0.75rem 0.6rem 1rem;
    border-bottom: 1px solid var(--color-border);
  }

  .drawer-brand {
    font-weight: 600;
    color: var(--color-heading);
  }

  .drawer-close {
    flex: none;
    justify-content: center;
    align-items: center;
    width: 2.5rem;
    height: 2.5rem;
    padding: 0;
  }

  .drawer-close .menu-icon {
    display: block;
  }

  .nav {
    display: flex;
    flex: 1;
    flex-direction: column;
    gap: 0.15rem;
    padding: 0.75rem;
    overflow-y: auto;
  }

  .nav a {
    padding: 0.75rem 0.85rem;
  }

  .account {
    justify-content: space-between;
    margin: 0;
    padding: 0.85rem 1rem;
  }

  .account-name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: var(--color-heading);
  }

  .menu-backdrop {
    position: fixed;
    inset: 0;
    z-index: 40;
    background: rgba(0, 0, 0, 0.5);
    animation: fade-in 0.25s ease;
  }
}

@keyframes fade-in {
  from {
    opacity: 0;
  }
}

@media (prefers-reduced-motion: reduce) {
  .menu,
  .menu.open {
    transition: none;
  }

  .menu-backdrop {
    animation: none;
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
