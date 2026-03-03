<template>
  <AppLayout title="Dashboard">
    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <StatCard
        label="Total Sites"
        :value="stats.total_sites"
        color="indigo"
      />
      <StatCard
        label="Active Sites"
        :value="stats.active_sites"
        color="green"
      />
      <StatCard
        label="Transactions Today"
        :value="stats.today_transactions"
        color="blue"
      />
      <StatCard
        label="Total Revenue"
        :value="'$' + Number(stats.total_revenue).toFixed(2)"
        color="purple"
      />
    </div>

    <!-- Recent Transactions -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
      <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Transactions</h2>
        <Link href="/transactions" class="text-sm text-indigo-600 hover:text-indigo-700">View all</Link>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
              <th class="pb-3 font-medium">Order ID</th>
              <th class="pb-3 font-medium">Amount</th>
              <th class="pb-3 font-medium">Gateway</th>
              <th class="pb-3 font-medium">Status</th>
              <th class="pb-3 font-medium">Site</th>
              <th class="pb-3 font-medium">Date</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            <tr v-if="recent_transactions.length === 0">
              <td colspan="6" class="py-8 text-center text-gray-400">No transactions yet</td>
            </tr>
            <tr v-for="tx in recent_transactions" :key="tx.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
              <td class="py-3 font-mono text-xs">{{ tx.order_id }}</td>
              <td class="py-3 font-medium">{{ tx.currency }} {{ Number(tx.amount).toFixed(2) }}</td>
              <td class="py-3 capitalize">{{ tx.gateway }}</td>
              <td class="py-3">
                <StatusBadge :status="tx.status" />
              </td>
              <td class="py-3 text-gray-600 dark:text-gray-400">{{ tx.site?.name }}</td>
              <td class="py-3 text-gray-500 text-xs">{{ formatDate(tx.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

// Inline simple components
const StatCard = {
  props: ['label', 'value', 'color'],
  template: `
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6">
      <p class="text-sm text-gray-500 dark:text-gray-400">{{ label }}</p>
      <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ value }}</p>
    </div>
  `,
};

const StatusBadge = {
  props: ['status'],
  template: `
    <span :class="{
      'bg-yellow-100 text-yellow-800': status === 'pending',
      'bg-green-100 text-green-800': status === 'completed',
      'bg-red-100 text-red-800': status === 'failed',
      'bg-gray-100 text-gray-800': status === 'refunded',
    }" class="px-2 py-0.5 rounded-full text-xs font-medium capitalize">{{ status }}</span>
  `,
};

defineProps({
  stats: Object,
  recent_transactions: Array,
});

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleDateString('en-US', {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}
</script>
