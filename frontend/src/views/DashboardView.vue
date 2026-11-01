<script setup>
import { useAuthStore } from '@/stores/auth'
import WhereIsIt from '@/components/WhereIsIt.vue'

const auth = useAuthStore()
</script>

<template>
  <h1 class="page-title">Dashboard</h1>
  <p v-if="auth.user">You are logged in as {{ auth.user.email }}.</p>

  <WhereIsIt />
</template>
