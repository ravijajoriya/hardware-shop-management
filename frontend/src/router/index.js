import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AdminLayout from '../layouts/AdminLayout.vue'
import BrandsView from '../views/BrandsView.vue'
import CategoriesView from '../views/CategoriesView.vue'
import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'
import ProductDetailView from '../views/ProductDetailView.vue'
import ProductFormView from '../views/ProductFormView.vue'
import ProductsView from '../views/ProductsView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/',
      component: AdminLayout,
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: DashboardView,
        },
        {
          path: 'products',
          name: 'products',
          component: ProductsView,
          meta: { section: 'products' },
        },
        {
          path: 'products/new',
          name: 'product-create',
          component: ProductFormView,
          meta: { section: 'products' },
        },
        {
          path: 'products/:id',
          name: 'product-show',
          component: ProductDetailView,
          props: true,
          meta: { section: 'products' },
        },
        {
          path: 'products/:id/edit',
          name: 'product-edit',
          component: ProductFormView,
          props: true,
          meta: { section: 'products' },
        },
        {
          path: 'categories',
          name: 'categories',
          component: CategoriesView,
        },
        {
          path: 'brands',
          name: 'brands',
          component: BrandsView,
        },
      ],
    },
    {
      path: '/login',
      name: 'login',
      component: LoginView,
      meta: { guestOnly: true },
    },
  ],
})

router.beforeEach(async (to) => {
  const auth = useAuthStore()

  if (auth.isAuthenticated && !auth.user) {
    await auth.restoreSession()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { name: 'login' }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { name: 'dashboard' }
  }
})

export default router
