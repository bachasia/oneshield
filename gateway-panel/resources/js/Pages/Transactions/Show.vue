<template>
  <AppLayout :title="`Transaction #${transaction.id}`">
    <div class="max-w-2xl">

      <!-- Back button -->
      <Link
        href="/transactions"
        class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-indigo-600 mb-5 transition-colors group"
      >
        <svg class="w-4 h-4 transition-transform group-hover:-translate-x-0.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/>
        </svg>
        Back to Transactions
      </Link>

      <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">

        <!-- Card header -->
        <div class="flex items-start justify-between px-6 py-5 border-b border-gray-100">
          <div>
            <p class="text-xs font-medium text-gray-400 uppercase tracking-wide mb-1">Transaction</p>
            <h2 class="text-xl font-bold text-gray-900">#{{ transaction.id }}</h2>
            <p class="text-sm text-gray-500 mt-1">{{ formatDate(transaction.created_at) }}</p>
          </div>
          <div class="flex items-center gap-2 mt-1">
            <!-- Gateway badge -->
            <span :class="gatewayClass(transaction.gateway)" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold">
              <span class="w-1.5 h-1.5 rounded-full" :class="gatewayDot(transaction.gateway)"></span>
              {{ transaction.gateway }}
            </span>
            <!-- Status badge -->
            <span :class="statusClass(transaction.status)" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold capitalize">
              <span class="w-1.5 h-1.5 rounded-full" :class="statusDot(transaction.status)"></span>
              {{ transaction.status }}
            </span>
          </div>
        </div>

        <!-- Amount highlight -->
        <div class="px-6 py-4 bg-gray-50/60 border-b border-gray-100">
          <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-0.5">Amount</p>
          <p class="text-3xl font-bold text-gray-900">
            {{ transaction.currency }}
            <span class="tabular-nums">{{ Number(transaction.amount).toFixed(2) }}</span>
          </p>
        </div>

        <!-- Details grid -->
        <dl class="grid grid-cols-2 gap-x-6 gap-y-5 px-6 py-5">
          <div>
            <dt class="text-xs text-gray-400 font-medium uppercase tracking-wide mb-1">Order ID</dt>
            <dd class="font-mono text-sm text-gray-900 break-all">{{ transaction.order_id }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-400 font-medium uppercase tracking-wide mb-1">Gateway Transaction ID</dt>
            <dd class="font-mono text-xs text-gray-600 break-all">{{ transaction.gateway_transaction_id ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-400 font-medium uppercase tracking-wide mb-1">Shield Site</dt>
            <dd class="text-sm text-gray-700 font-medium">{{ transaction.site?.name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-xs text-gray-400 font-medium uppercase tracking-wide mb-1">Money Site</dt>
            <dd class="text-sm text-gray-700">{{ transaction.money_site_domain ?? '—' }}</dd>
          </div>
        </dl>

        <!-- Customer Info -->
        <div v-if="billing" class="border-t border-gray-100 px-6 py-5">
          <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-4">Customer</p>
          <dl class="grid grid-cols-2 gap-x-6 gap-y-4">
            <div v-if="customerName">
              <dt class="text-xs text-gray-400 font-medium uppercase tracking-wide mb-1">Name</dt>
              <dd class="text-sm text-gray-900 font-medium">{{ customerName }}</dd>
            </div>
            <div v-if="billing.email">
              <dt class="text-xs text-gray-400 font-medium uppercase tracking-wide mb-1">Email</dt>
              <dd class="text-sm text-gray-700">{{ billing.email }}</dd>
            </div>
            <div v-if="billing.phone">
              <dt class="text-xs text-gray-400 font-medium uppercase tracking-wide mb-1">Phone</dt>
              <dd class="text-sm text-gray-700">{{ billing.phone }}</dd>
            </div>
            <div v-if="billing.country">
              <dt class="text-xs text-gray-400 font-medium uppercase tracking-wide mb-1">Country</dt>
              <dd class="text-sm text-gray-700">{{ billing.country }}</dd>
            </div>
            <div v-if="billingAddress" class="col-span-2">
              <dt class="text-xs text-gray-400 font-medium uppercase tracking-wide mb-1">Billing Address</dt>
              <dd class="text-sm text-gray-700 whitespace-pre-line">{{ billingAddress }}</dd>
            </div>
          </dl>
        </div>

        <!-- Raw response -->
        <div v-if="transaction.raw_response" class="px-6 pb-6">
          <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">Raw Response</p>
          <pre class="bg-gray-50 border border-gray-200 rounded-xl p-4 text-xs overflow-auto max-h-52 text-gray-600 font-mono leading-relaxed">{{ JSON.stringify(transaction.raw_response, null, 2) }}</pre>
        </div>

      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ transaction: Object });

const billing = computed(() => props.transaction.billing_data ?? null);

const customerName = computed(() => {
  if (!billing.value) return null;
  const first = billing.value.first_name ?? '';
  const last  = billing.value.last_name  ?? '';
  return [first, last].filter(Boolean).join(' ') || null;
});

const billingAddress = computed(() => {
  if (!billing.value) return null;
  const parts = [
    billing.value.address_1,
    billing.value.address_2,
    [billing.value.city, billing.value.state, billing.value.postcode].filter(Boolean).join(', '),
  ].filter(Boolean);
  return parts.length ? parts.join('\n') : null;
});

function statusClass(status) {
  return {
    pending:   'bg-yellow-100 text-yellow-700',
    completed: 'bg-green-100 text-green-700',
    failed:    'bg-red-100 text-red-700',
    refunded:  'bg-gray-100 text-gray-600',
  }[status] ?? 'bg-gray-100 text-gray-600';
}

function statusDot(status) {
  return {
    pending:   'bg-yellow-500',
    completed: 'bg-green-500',
    failed:    'bg-red-500',
    refunded:  'bg-gray-400',
  }[status] ?? 'bg-gray-400';
}

function gatewayClass(gateway) {
  return {
    paypal: 'bg-blue-100 text-blue-700',
    stripe: 'bg-violet-100 text-violet-700',
  }[gateway] ?? 'bg-gray-100 text-gray-600';
}

function gatewayDot(gateway) {
  return {
    paypal: 'bg-blue-500',
    stripe: 'bg-violet-500',
  }[gateway] ?? 'bg-gray-400';
}

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleString('en-US', {
    dateStyle: 'long',
    timeStyle: 'short',
  });
}
</script>
