<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 flex items-center justify-center p-4">
    <div class="w-full max-w-md">
      <div class="text-center mb-8">
        <h1 class="text-3xl font-bold text-indigo-600">OneShield</h1>
        <p class="text-gray-500 mt-2">Create your admin account</p>
      </div>

      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-sm border border-gray-200 dark:border-gray-700 p-8">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-white mb-6">Initial Setup</h2>

        <form @submit.prevent="submit">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
              <input
                v-model="form.name"
                type="text"
                placeholder="Your name"
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"
              />
              <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Email</label>
              <input
                v-model="form.email"
                type="email"
                placeholder="admin@example.com"
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"
              />
              <p v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                Tenant ID
                <span class="text-gray-400 font-normal">(subdomain, e.g. "acme")</span>
              </label>
              <div class="flex">
                <input
                  v-model="form.tenant_id"
                  type="text"
                  placeholder="acme"
                  class="flex-1 px-3 py-2 border border-r-0 border-gray-300 dark:border-gray-600 rounded-l-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"
                />
                <span class="px-3 py-2 bg-gray-100 dark:bg-gray-600 border border-gray-300 dark:border-gray-600 rounded-r-lg text-sm text-gray-500">.oneshield.io</span>
              </div>
              <p v-if="form.errors.tenant_id" class="text-red-500 text-xs mt-1">{{ form.errors.tenant_id }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Password</label>
              <input
                v-model="form.password"
                type="password"
                placeholder="Minimum 8 characters"
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"
              />
              <p v-if="form.errors.password" class="text-red-500 text-xs mt-1">{{ form.errors.password }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Confirm Password</label>
              <input
                v-model="form.password_confirmation"
                type="password"
                placeholder="Repeat password"
                class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700 dark:text-white"
              />
            </div>
          </div>

          <button
            type="submit"
            :disabled="form.processing"
            class="mt-6 w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white py-2.5 px-4 rounded-lg text-sm font-medium transition-colors"
          >
            {{ form.processing ? 'Creating...' : 'Create Admin Account' }}
          </button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({
  name: '',
  email: '',
  tenant_id: '',
  password: '',
  password_confirmation: '',
});

function submit() {
  form.post('/account/admin');
}
</script>
