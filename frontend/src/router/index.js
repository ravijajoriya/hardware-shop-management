import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import AdminLayout from '../layouts/AdminLayout.vue'
import ActivityLogView from '../views/ActivityLogView.vue'
import BillDetailView from '../views/BillDetailView.vue'
import BillFormView from '../views/BillFormView.vue'
import BillsView from '../views/BillsView.vue'
import BrandsView from '../views/BrandsView.vue'
import CategoriesView from '../views/CategoriesView.vue'
import CustomerDetailView from '../views/CustomerDetailView.vue'
import CustomersView from '../views/CustomersView.vue'
import DashboardView from '../views/DashboardView.vue'
import LoginView from '../views/LoginView.vue'
import ProductDetailView from '../views/ProductDetailView.vue'
import ProductFormView from '../views/ProductFormView.vue'
import ProductsView from '../views/ProductsView.vue'
import StockEntriesView from '../views/StockEntriesView.vue'
import StockEntryDetailView from '../views/StockEntryDetailView.vue'
import StockEntryFormView from '../views/StockEntryFormView.vue'

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
          path: 'bills',
          name: 'bills',
          component: BillsView,
          meta: { section: 'bills' },
        },
        {
          path: 'bills/new',
          name: 'bill-create',
          component: BillFormView,
          meta: { section: 'bills' },
        },
        {
          path: 'bills/:id',
          name: 'bill-show',
          component: BillDetailView,
          props: true,
          meta: { section: 'bills' },
        },
        {
          path: 'customers',
          name: 'customers',
          component: CustomersView,
          meta: { section: 'customers' },
        },
        {
          path: 'customers/:id',
          name: 'customer-show',
          component: CustomerDetailView,
          props: true,
          meta: { section: 'customers' },
        },
        {
          path: 'stock-in',
          name: 'stock-entries',
          component: StockEntriesView,
          meta: { section: 'stock' },
        },
        {
          path: 'stock-in/new',
          name: 'stock-entry-create',
          component: StockEntryFormView,
          meta: { section: 'stock' },
        },
        {
          path: 'stock-in/:id',
          name: 'stock-entry-show',
          component: StockEntryDetailView,
          props: true,
          meta: { section: 'stock' },
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
        {
          path: 'activity',
          name: 'activity',
          component: ActivityLogView,
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
