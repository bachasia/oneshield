<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-indigo-600">OneShield</h1>
        <p class="text-gray-500 mt-2">Payment Gateway Panel</p>
      </div>

      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-8">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Sign In</h2>

        <form @submit.prevent="submit">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
              <input
                v-model="form.email"
                type="email"
                autocomplete="email"
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"
              />
              <p v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password</label>
              <input
                v-model="form.password"
                type="password"
                autocomplete="current-password"
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"
              />
            </div>

            <div class="flex items-center">
              <input v-model="form.remember" type="checkbox" id="remember" class="rounded text-indigo-600" />
              <label for="remember" class="ml-2 text-sm text-gray-600 dark:text-gray-400">Remember me</label>
            </div>
          </div>

          <button
            type="submit"
            :disabled="form.processing"
            class="mt-6 w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white py-2.5 px-4 rounded-lg text-sm font-medium transition-colors"
          >
            {{ form.processing ? 'Signing in...' : 'Sign In' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
  email: '',
  password: '',
  remember: false,
});

function submit() {
  form.post('/login');
}
</script>
