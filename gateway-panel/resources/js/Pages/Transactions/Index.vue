<template>
  <AppLayout title="Transactions">

    <!-- Filter bar -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-5">
      <div class="flex flex-wrap items-center gap-3">
        <!-- Gateway filter -->
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
          <select v-model="f.gateway" @change="applyFilters" class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 min-w-[130px]">
            <option value="">All Gateways</option>
            <option value="paypal">PayPal</option>
            <option value="stripe">Stripe</option>
            <option value="airwallex">Airwallex</option>
          </select>
        </div>

        <!-- Status filter -->
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/></svg>
          <select v-model="f.status" @change="applyFilters" class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 min-w-[130px]">
            <option value="">All Status</option>
            <option value="pending">Pending</option>
            <option value="completed">Completed</option>
            <option value="failed">Failed</option>
            <option value="refunded">Refunded</option>
          </select>
        </div>

        <!-- Date range -->
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 012.25-2.25h13.5A2.25 2.25 0 0121 7.5v11.25m-18 0A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75m-18 0v-7.5A2.25 2.25 0 015.25 9h13.5A2.25 2.25 0 0121 11.25v7.5"/></svg>
          <input v-model="f.date_from" @change="applyFilters" type="date" placeholder="From" class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
          <span class="text-gray-400 text-sm">–</span>
          <input v-model="f.date_to" @change="applyFilters" type="date" placeholder="To" class="px-3 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500" />
        </div>

        <!-- Spacer + Export -->
        <div class="ml-auto flex items-center gap-2">
          <button @click="resetFilters" class="text-xs text-gray-500 hover:text-gray-700 px-3 py-2 rounded-lg hover:bg-gray-100 transition-colors">
            Clear filters
          </button>
          <a :href="exportUrl" class="flex items-center gap-2 px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 font-medium transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
            Export CSV
          </a>
        </div>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-50 border-b border-gray-200">
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">#</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Order ID</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Customer</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Amount</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Gateway</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Status</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Site</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Domain</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Date</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="transactions.data.length === 0">
              <td colspan="9" class="px-5 py-14 text-center">
                <div class="flex flex-col items-center gap-2 text-gray-400">
                  <svg class="w-10 h-10 text-gray-200" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                  <span class="text-sm font-medium text-gray-500">No transactions found</span>
                  <span class="text-xs">Try adjusting your filters</span>
                </div>
              </td>
            </tr>
            <tr
              v-for="tx in transactions.data"
              :key="tx.id"
              class="hover:bg-gray-50/70 cursor-pointer transition-colors"
              @click="$inertia.visit(`/transactions/${tx.id}`)"
            >
              <td class="px-5 py-3 text-xs text-gray-400 font-mono">#{{ tx.id }}</td>
              <td class="px-5 py-3 font-mono text-xs text-gray-700 font-medium">{{ tx.order_id }}</td>
              <td class="px-5 py-3">
                <div class="text-sm text-gray-800 font-medium leading-tight">{{ customerName(tx) }}</div>
                <div v-if="tx.billing_data?.email" class="text-xs text-gray-400 mt-0.5">{{ tx.billing_data.email }}</div>
              </td>
              <td class="px-5 py-3 font-semibold text-gray-900">
                <span class="text-xs text-gray-400 mr-1">{{ tx.currency }}</span>{{ Number(tx.amount).toFixed(2) }}
              </td>
              <td class="px-5 py-3">
                <span
                  class="inline-flex items-center gap-1.5 text-xs font-medium px-2.5 py-1 rounded-full capitalize"
                  :class="gatewayClass(tx.gateway)"
                >
                  <span class="w-1.5 h-1.5 rounded-full" :class="gatewaydot(tx.gateway)" />
                  {{ tx.gateway }}
                </span>
              </td>
              <td class="px-5 py-3">
                <span
                  class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-xs font-medium capitalize"
                  :class="statusClass(tx.status)"
                >
                  <span class="w-1.5 h-1.5 rounded-full" :class="statusDot(tx.status)" />
                  {{ tx.status }}
                </span>
              </td>
              <td class="px-5 py-3 text-gray-700 text-xs">{{ tx.site?.name ?? '—' }}</td>
              <td class="px-5 py-3 text-xs text-gray-400">{{ tx.money_site_domain }}</td>
              <td class="px-5 py-3 text-xs text-gray-400 whitespace-nowrap">{{ formatDate(tx.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="transactions.last_page > 1" class="px-5 py-3 border-t border-gray-100 flex gap-1 flex-wrap">
        <Link
          v-for="link in transactions.links"
          :key="link.label"
          :href="link.url || ''"
          v-html="link.label"
          class="px-3 py-1.5 rounded-lg text-xs font-medium"
          :class="link.active
            ? 'bg-indigo-600 text-white'
            : link.url
              ? 'text-gray-600 hover:bg-gray-100 border border-gray-200'
              : 'text-gray-300 cursor-not-allowed'"
        />
      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({ transactions: Object, filters: Object });

const f = ref({ ...props.filters });

const exportUrl = computed(() => '/transactions/export/csv?' + new URLSearchParams(f.value).toString());

function applyFilters() {
  router.get('/transactions', f.value, { preserveState: true });
}

function resetFilters() {
  f.value = { gateway: '', status: '', date_from: '', date_to: '' };
  applyFilters();
}

function customerName(tx) {
  const b = tx.billing_data;
  if (!b) return '—';
  return [b.first_name, b.last_name].filter(Boolean).join(' ') || '—';
}
function gatewayClass(g) {
  return {
    paypal:    'bg-blue-50 text-blue-700',
    stripe:    'bg-indigo-50 text-indigo-700',
    airwallex: 'bg-rose-50 text-rose-700',
  }[g] ?? 'bg-gray-100 text-gray-600';
}
function gatewaydot(g) {
  return { paypal: 'bg-blue-500', stripe: 'bg-indigo-500', airwallex: 'bg-rose-500' }[g] ?? 'bg-gray-400';
}
function statusClass(s) {
  return {
    pending:   'bg-yellow-50 text-yellow-700',
    completed: 'bg-green-50 text-green-700',
    failed:    'bg-red-50 text-red-700',
    refunded:  'bg-gray-100 text-gray-600',
  }[s] ?? 'bg-gray-100 text-gray-600';
}
function statusDot(s) {
  return { pending: 'bg-yellow-500', completed: 'bg-green-500', failed: 'bg-red-500', refunded: 'bg-gray-400' }[s] ?? 'bg-gray-400';
}
function formatDate(d) {
  return new Date(d).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
}
</script>
