<template>
  <AppLayout title="Transactions">
    <!-- Filters -->
    <div class="flex flex-wrap gap-3 mb-6">
      <select v-model="f.gateway" @change="applyFilters" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white">
        <option value="">All Gateways</option>
        <option value="paypal">PayPal</option>
        <option value="stripe">Stripe</option>
      </select>
      <select v-model="f.status" @change="applyFilters" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white">
        <option value="">All Status</option>
        <option value="pending">Pending</option>
        <option value="completed">Completed</option>
        <option value="failed">Failed</option>
        <option value="refunded">Refunded</option>
      </select>
      <input v-model="f.date_from" @change="applyFilters" type="date" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white" />
      <input v-model="f.date_to" @change="applyFilters" type="date" class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white" />

      <a :href="exportUrl" class="ml-auto px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 dark:text-gray-300 dark:border-gray-600 dark:hover:bg-gray-700">
        Export CSV
      </a>
    </div>

    <!-- Table -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
              <th class="px-6 py-4 font-medium">ID</th>
              <th class="px-6 py-4 font-medium">Order</th>
              <th class="px-6 py-4 font-medium">Amount</th>
              <th class="px-6 py-4 font-medium">Gateway</th>
              <th class="px-6 py-4 font-medium">Status</th>
              <th class="px-6 py-4 font-medium">Site</th>
              <th class="px-6 py-4 font-medium">Domain</th>
              <th class="px-6 py-4 font-medium">Date</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            <tr v-if="transactions.data.length === 0">
              <td colspan="8" class="px-6 py-12 text-center text-gray-400">No transactions found</td>
            </tr>
            <tr
              v-for="tx in transactions.data"
              :key="tx.id"
              class="hover:bg-gray-50 dark:hover:bg-gray-700/50 cursor-pointer"
              @click="$inertia.visit(`/transactions/${tx.id}`)"
            >
              <td class="px-6 py-3 text-xs text-gray-400">#{{ tx.id }}</td>
              <td class="px-6 py-3 font-mono text-xs">{{ tx.order_id }}</td>
              <td class="px-6 py-3 font-medium">{{ tx.currency }} {{ Number(tx.amount).toFixed(2) }}</td>
              <td class="px-6 py-3 capitalize">{{ tx.gateway }}</td>
              <td class="px-6 py-3">
                <span :class="statusClass(tx.status)" class="px-2 py-0.5 rounded-full text-xs font-medium capitalize">{{ tx.status }}</span>
              </td>
              <td class="px-6 py-3 text-gray-600 dark:text-gray-400">{{ tx.site?.name }}</td>
              <td class="px-6 py-3 text-xs text-gray-500">{{ tx.money_site_domain }}</td>
              <td class="px-6 py-3 text-xs text-gray-500">{{ formatDate(tx.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="transactions.last_page > 1" class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-2 flex-wrap">
        <Link
          v-for="link in transactions.links"
          :key="link.label"
          :href="link.url || ''"
          v-html="link.label"
          class="px-3 py-1 rounded text-sm"
          :class="link.active ? 'bg-indigo-600 text-white' : link.url ? 'text-gray-600 hover:bg-gray-100' : 'text-gray-300 cursor-not-allowed'"
        />
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
  transactions: Object,
  filters: Object,
});

const f = ref({ ...props.filters });

const exportUrl = computed(() => {
  const params = new URLSearchParams({ ...f.value }).toString();
  return `/transactions/export/csv?${params}`;
});

function applyFilters() {
  router.get('/transactions', f.value, { preserveState: true });
}

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
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}
</script>
