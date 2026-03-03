<template>
  <AppLayout title="Dashboard">

    <!-- Welcome bar -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h2 class="text-lg font-semibold text-gray-800">Welcome back, {{ auth.user?.name }} 👋</h2>
        <p class="text-sm text-gray-500 mt-0.5">Here's what's happening with your payment network today.</p>
      </div>
      <Link href="/sites" class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-2 rounded-lg font-medium transition-colors">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Manage Sites
      </Link>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">

      <div class="bg-white rounded-xl border border-gray-200 p-5 flex items-start gap-4 hover:shadow-sm transition-shadow">
        <div class="w-11 h-11 rounded-xl bg-indigo-50 flex items-center justify-center flex-shrink-0">
          <svg class="w-6 h-6 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/>
          </svg>
        </div>
        <div>
          <p class="text-xs text-gray-500 font-medium">Total Sites</p>
          <p class="text-2xl font-bold text-gray-900 mt-0.5">{{ stats.total_sites }}</p>
          <p class="text-xs text-gray-400 mt-0.5">Shield payment sites</p>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 p-5 flex items-start gap-4 hover:shadow-sm transition-shadow">
        <div class="w-11 h-11 rounded-xl bg-green-50 flex items-center justify-center flex-shrink-0">
          <svg class="w-6 h-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
          </svg>
        </div>
        <div>
          <p class="text-xs text-gray-500 font-medium">Active Sites</p>
          <p class="text-2xl font-bold text-gray-900 mt-0.5">{{ stats.active_sites }}</p>
          <p class="text-xs text-green-500 mt-0.5 font-medium">● Online & healthy</p>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-gray-200 p-5 flex items-start gap-4 hover:shadow-sm transition-shadow">
        <div class="w-11 h-11 rounded-xl bg-amber-50 flex items-center justify-center flex-shrink-0">
          <svg class="w-6 h-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
          </svg>
        </div>
        <div>
          <p class="text-xs text-gray-500 font-medium">Transactions Today</p>
          <p class="text-2xl font-bold text-gray-900 mt-0.5">{{ stats.today_transactions }}</p>
          <p class="text-xs text-gray-400 mt-0.5">Since midnight</p>
        </div>
      </div>

      <div class="bg-white rounded-xl border border-indigo-100 bg-gradient-to-br from-indigo-500 to-indigo-700 p-5 flex items-start gap-4 hover:shadow-sm transition-shadow">
        <div class="w-11 h-11 rounded-xl bg-white/20 flex items-center justify-center flex-shrink-0">
          <svg class="w-6 h-6 text-white" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
        </div>
        <div>
          <p class="text-xs text-white/70 font-medium">Total Revenue</p>
          <p class="text-2xl font-bold text-white mt-0.5">${{ Number(stats.total_revenue).toFixed(2) }}</p>
          <p class="text-xs text-white/60 mt-0.5">All time</p>
        </div>
      </div>

    </div>

    <!-- Recent Transactions -->
    <div class="bg-white rounded-xl border border-gray-200">
      <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
        <div class="flex items-center gap-2">
          <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
          <h2 class="text-sm font-semibold text-gray-800">Recent Transactions</h2>
        </div>
        <Link href="/transactions" class="text-xs text-indigo-600 hover:text-indigo-700 font-medium flex items-center gap-1">
          View all
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
        </Link>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left border-b border-gray-100">
              <th class="px-6 py-3 text-xs font-semibold text-gray-400 uppercase tracking-wide">Order</th>
              <th class="px-6 py-3 text-xs font-semibold text-gray-400 uppercase tracking-wide">Amount</th>
              <th class="px-6 py-3 text-xs font-semibold text-gray-400 uppercase tracking-wide">Gateway</th>
              <th class="px-6 py-3 text-xs font-semibold text-gray-400 uppercase tracking-wide">Status</th>
              <th class="px-6 py-3 text-xs font-semibold text-gray-400 uppercase tracking-wide">Site</th>
              <th class="px-6 py-3 text-xs font-semibold text-gray-400 uppercase tracking-wide">Date</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-50">
            <tr v-if="recent_transactions.length === 0">
              <td colspan="6" class="px-6 py-12 text-center">
                <div class="flex flex-col items-center gap-2 text-gray-400">
                  <svg class="w-10 h-10 text-gray-200" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>
                  <span class="text-sm">No transactions yet</span>
                </div>
              </td>
            </tr>
            <tr v-for="tx in recent_transactions" :key="tx.id" class="hover:bg-gray-50/60 transition-colors">
              <td class="px-6 py-3 font-mono text-xs text-gray-600">{{ tx.order_id }}</td>
              <td class="px-6 py-3 font-semibold text-gray-900">{{ tx.currency }} {{ Number(tx.amount).toFixed(2) }}</td>
              <td class="px-6 py-3">
                <GatewayBadge :gateway="tx.gateway" />
              </td>
              <td class="px-6 py-3">
                <StatusBadge :status="tx.status" />
              </td>
              <td class="px-6 py-3 text-gray-600 text-xs">{{ tx.site?.name }}</td>
              <td class="px-6 py-3 text-gray-400 text-xs">{{ formatDate(tx.created_at) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const auth = computed(() => page.props.auth);

const GatewayBadge = {
  props: ['gateway'],
  template: `
    <span class="inline-flex items-center gap-1 text-xs font-medium px-2 py-0.5 rounded-full capitalize"
      :class="{
        'bg-blue-50 text-blue-700':   gateway === 'paypal',
        'bg-indigo-50 text-indigo-700': gateway === 'stripe',
        'bg-rose-50 text-rose-700':   gateway === 'airwallex',
        'bg-gray-100 text-gray-600':  !['paypal','stripe','airwallex'].includes(gateway),
      }">
      <span v-if="gateway==='paypal'" class="font-bold text-blue-500">P</span>
      <span v-else-if="gateway==='stripe'" class="font-bold text-indigo-500">S</span>
      <span v-else-if="gateway==='airwallex'" class="font-bold text-rose-500">A</span>
      {{ gateway }}
    </span>
  `,
};

const StatusBadge = {
  props: ['status'],
  template: `
    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium capitalize"
      :class="{
        'bg-yellow-100 text-yellow-700': status === 'pending',
        'bg-green-100 text-green-700':   status === 'completed',
        'bg-red-100 text-red-700':       status === 'failed',
        'bg-gray-100 text-gray-600':     status === 'refunded',
      }">
      <span class="w-1.5 h-1.5 rounded-full"
        :class="{
          'bg-yellow-500': status === 'pending',
          'bg-green-500':  status === 'completed',
          'bg-red-500':    status === 'failed',
          'bg-gray-400':   status === 'refunded',
        }"/>
      {{ status }}
    </span>
  `,
};

defineProps({ stats: Object, recent_transactions: Array });

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleDateString('en-US', {
    month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
  });
}
</script>
