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
            <span :class="statusClass(currentStatus)" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold capitalize">
              <span class="w-1.5 h-1.5 rounded-full" :class="statusDot(currentStatus)"></span>
              {{ currentStatus }}
            </span>
          </div>
        </div>

        <!-- Amount highlight + Refund button -->
        <div class="px-6 py-4 bg-gray-50/60 border-b border-gray-100 flex items-center justify-between gap-4">
          <div>
            <p class="text-xs font-medium text-gray-500 uppercase tracking-wide mb-0.5">Amount</p>
            <p class="text-3xl font-bold text-gray-900">
              {{ String(transaction.currency || '').toUpperCase() }}
              <span class="tabular-nums">{{ Number(transaction.amount).toFixed(2) }}</span>
            </p>
          </div>

          <!-- Refund button — only for completed Stripe transactions -->
          <button
            v-if="canRefund"
            @click="showRefundModal = true"
            :disabled="refunding"
            class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold border transition-colors cursor-pointer
                   text-red-600 border-red-200 bg-red-50 hover:bg-red-600 hover:text-white hover:border-red-600
                   disabled:opacity-50 disabled:cursor-not-allowed"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/>
            </svg>
            {{ refunding ? 'Refunding…' : 'Refund' }}
          </button>

          <!-- Refunded label (after refund) -->
          <span v-if="currentStatus === 'refunded'" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl text-sm font-semibold bg-gray-100 text-gray-500">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/>
            </svg>
            Refunded
          </span>
        </div>

        <!-- Error / success notice -->
        <div v-if="refundError" class="mx-6 mt-4 px-4 py-3 rounded-xl bg-red-50 border border-red-200 text-sm text-red-700">
          {{ refundError }}
        </div>
        <div v-if="refundSuccess" class="mx-6 mt-4 px-4 py-3 rounded-xl bg-green-50 border border-green-200 text-sm text-green-700">
          Refund processed successfully. Refund ID: <span class="font-mono">{{ refundSuccess }}</span>
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

    <!-- Refund Confirmation Modal -->
    <Teleport to="body">
      <div
        v-if="showRefundModal"
        class="fixed inset-0 z-50 flex items-center justify-center p-4"
        @click.self="showRefundModal = false"
      >
        <!-- Backdrop -->
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm"></div>

        <!-- Dialog -->
        <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-sm p-6 z-10">
          <!-- Icon -->
          <div class="w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mx-auto mb-4">
            <svg class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"/>
            </svg>
          </div>

          <h3 class="text-center text-base font-bold text-gray-900 mb-1">Confirm Refund</h3>
          <p class="text-center text-sm text-gray-500 mb-5">
            Refund <strong>{{ String(transaction.currency || '').toUpperCase() }} {{ Number(transaction.amount).toFixed(2) }}</strong>
            to the customer? This action cannot be undone.
          </p>

          <div class="flex gap-3">
            <button
              @click="showRefundModal = false"
              class="flex-1 px-4 py-2.5 rounded-xl border border-gray-200 text-sm font-medium text-gray-600 hover:bg-gray-50 transition-colors cursor-pointer"
            >
              Cancel
            </button>
            <button
              @click="submitRefund"
              :disabled="refunding"
              class="flex-1 px-4 py-2.5 rounded-xl text-sm font-semibold text-white bg-red-600 hover:bg-red-700 transition-colors cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
            >
              {{ refunding ? 'Processing…' : 'Yes, Refund' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import axios from 'axios';

const props = defineProps({ transaction: Object });

// ── Refund state ──────────────────────────────────────────────────────────────
const currentStatus  = ref(props.transaction.status);
const showRefundModal = ref(false);
const refunding      = ref(false);
const refundError    = ref('');
const refundSuccess  = ref('');

const canRefund = computed(() =>
  currentStatus.value === 'completed' && props.transaction.gateway === 'stripe'
);

async function submitRefund() {
  refunding.value  = true;
  refundError.value  = '';
  refundSuccess.value = '';

  try {
    const res = await axios.post(
      `/transactions/${props.transaction.id}/refund`,
      {},
      { headers: { 'X-XSRF-TOKEN': getCsrfToken() } }
    );
    currentStatus.value  = 'refunded';
    refundSuccess.value  = res.data.refund_id || 'OK';
  } catch (err) {
    refundError.value = err.response?.data?.error ?? 'Refund failed. Please try again.';
  } finally {
    refunding.value    = false;
    showRefundModal.value = false;
  }
}

function getCsrfToken() {
  const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
  return match ? decodeURIComponent(match[1]) : '';
}

// ── Billing / address helpers ─────────────────────────────────────────────────
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

// ── Badge helpers ─────────────────────────────────────────────────────────────
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
    paypal: 'bg-amber-50 text-amber-700',
    stripe: 'bg-indigo-50 text-indigo-700',
  }[gateway] ?? 'bg-gray-100 text-gray-600';
}

function gatewayDot(gateway) {
  return {
    paypal: 'bg-amber-500',
    stripe: 'bg-indigo-500',
  }[gateway] ?? 'bg-gray-400';
}

function formatDate(dateStr) {
  return new Date(dateStr).toLocaleString('en-US', {
    dateStyle: 'long',
    timeStyle: 'short',
  });
}
</script>
