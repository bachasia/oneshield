<template>
  <AppLayout :title="`Transaction #${transaction.id}`">
    <div class="max-w-2xl">
      <Link href="/transactions" class="text-sm text-indigo-600 hover:text-indigo-700 mb-6 inline-block">
        &larr; Back to Transactions
      </Link>

      <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
        <div class="flex items-start justify-between mb-6">
          <div>
            <h2 class="text-xl font-semibold text-gray-900 dark:text-white">Transaction #{{ transaction.id }}</h2>
            <p class="text-sm text-gray-500 mt-1">{{ formatDate(transaction.created_at) }}</p>
          </div>
          <span :class="statusClass(transaction.status)" class="px-3 py-1 rounded-full text-sm font-medium capitalize">
            {{ transaction.status }}
          </span>
        </div>

        <dl class="grid grid-cols-2 gap-4">
          <div>
            <dt class="text-xs text-gray-500 font-medium uppercase">Order ID</dt>
            <dd class="mt-1 font-mono text-sm">{{ transaction.order_id }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500 font-medium uppercase">Amount</dt>
            <dd class="mt-1 font-semibold">{{ transaction.currency }} {{ Number(transaction.amount).toFixed(2) }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500 font-medium uppercase">Gateway</dt>
            <dd class="mt-1 capitalize">{{ transaction.gateway }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500 font-medium uppercase">Gateway Transaction ID</dt>
            <dd class="mt-1 font-mono text-xs">{{ transaction.gateway_transaction_id ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500 font-medium uppercase">Mesh Site</dt>
            <dd class="mt-1">{{ transaction.site?.name }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-500 font-medium uppercase">Money Site</dt>
            <dd class="mt-1 text-sm">{{ transaction.money_site_domain }}</dd>
          </div>
        </dl>

        <div v-if="transaction.raw_response" class="mt-6">
          <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Raw Response</h3>
          <pre class="bg-gray-100 dark:bg-gray-700 rounded-lg p-4 text-xs overflow-auto max-h-48">{{ JSON.stringify(transaction.raw_response, null, 2) }}</pre>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

defineProps({ transaction: Object });

function statusClass(status) {
  return {
    pending: 'bg-yellow-100 text-yellow-800',
    completed: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
    refunded: 'bg-gray-100 text-gray-800',
  }[status] || 'bg-gray-100 text-gray-600';
}

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleString('en-US', {
    dateStyle: 'long', timeStyle: 'short',
  });
}
</script>
