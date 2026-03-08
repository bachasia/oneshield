<template>
  <AdminLayout :title="tenant.name">

    <!-- Header -->
    <div class="flex items-start justify-between mb-6">
      <div class="flex items-center gap-3">
        <Link href="/admin/tenants" class="text-slate-500 hover:text-white transition-colors">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
          </svg>
        </Link>
        <div class="flex items-center gap-3">
          <div class="w-10 h-10 rounded-full bg-indigo-500/20 border border-indigo-500/30 flex items-center justify-center">
            <span class="text-sm font-bold text-indigo-400">{{ tenant.name[0].toUpperCase() }}</span>
          </div>
          <div>
            <h1 class="text-xl font-bold text-white">{{ tenant.name }}</h1>
            <p class="text-sm text-slate-500">{{ tenant.email }}</p>
          </div>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="flex items-center gap-2">
        <a
          :href="tenant.subdomain_url"
          target="_blank"
          class="flex items-center gap-1.5 text-xs text-slate-400 hover:text-white bg-slate-800 hover:bg-slate-700 border border-slate-700 px-3 py-2 rounded-xl transition-colors"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/>
          </svg>
          Visit site
        </a>
        <Link
          :href="`/admin/tenants/${tenant.id}/impersonate`"
          method="post"
          as="button"
          class="flex items-center gap-1.5 text-xs text-amber-400 hover:text-amber-300 bg-amber-500/10 hover:bg-amber-500/20 border border-amber-500/30 px-3 py-2 rounded-xl transition-colors"
        >
          <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/>
          </svg>
          Login as tenant
        </Link>
      </div>
    </div>

    <div class="grid grid-cols-3 gap-6">

      <!-- Left column: Stats + Subscription + History -->
      <div class="col-span-2 space-y-5">

        <!-- Stats -->
        <div class="grid grid-cols-3 gap-4">
          <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mb-1">Shield Sites</p>
            <p class="text-2xl font-bold text-white">{{ stats.shield_sites }}</p>
          </div>
          <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mb-1">Total Transactions</p>
            <p class="text-2xl font-bold text-white">{{ stats.transactions_total.toLocaleString() }}</p>
          </div>
          <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4">
            <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mb-1">Volume (30d)</p>
            <p class="text-2xl font-bold text-indigo-400">${{ Number(stats.volume_30d).toLocaleString() }}</p>
          </div>
        </div>

        <!-- Subscription History -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl">
          <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
            <h2 class="text-sm font-semibold text-white">Subscription History</h2>
            <span class="text-[10px] text-slate-500">Newest first</span>
          </div>
          <div class="divide-y divide-slate-800">
            <div v-if="history.length === 0" class="px-6 py-8 text-center text-slate-500 text-sm">
              No subscription history.
            </div>
            <div v-for="record in history" :key="record.id" class="px-6 py-4 flex items-start gap-4">
              <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 mb-1">
                  <span class="text-sm font-medium text-white">{{ record.plan }}</span>
                  <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" :class="statusBadgeClass(record.status)">
                    {{ record.status }}
                  </span>
                </div>
                <p v-if="record.notes" class="text-xs text-slate-500 mb-1 italic">{{ record.notes }}</p>
                <p class="text-[11px] text-slate-600">
                  By {{ record.created_by }} &bull; {{ record.created_at }}
                  <span v-if="record.expires_at"> &bull; Expires {{ record.expires_at }}</span>
                </p>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- Right column: Current sub + Change plan + Suspend -->
      <div class="space-y-5">

        <!-- Current Subscription Card -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
          <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-4">Current Subscription</h2>

          <div class="space-y-2.5">
            <div class="flex items-center justify-between">
              <span class="text-xs text-slate-500">Plan</span>
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="planBadgeClass(tenant.plan?.name)">
                {{ tenant.plan?.label ?? 'None' }}
              </span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-xs text-slate-500">Status</span>
              <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" :class="statusBadgeClass(tenant.subscription_status)">
                {{ tenant.subscription_status }}
              </span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-xs text-slate-500">Expires</span>
              <span class="text-xs text-white">{{ tenant.expires_at ?? 'Never' }}</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-xs text-slate-500">Sites Used</span>
              <span class="text-xs text-white">
                {{ tenant.sites_used }} / {{ tenant.plan?.max_shield_sites >= 999 ? '∞' : (tenant.plan?.max_shield_sites ?? '?') }}
              </span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-xs text-slate-500">Joined</span>
              <span class="text-xs text-white">{{ tenant.created_at }}</span>
            </div>
            <div class="flex items-center justify-between">
              <span class="text-xs text-slate-500">Subdomain</span>
              <code class="text-[10px] text-slate-400 bg-slate-800 px-2 py-0.5 rounded">{{ tenant.tenant_id }}</code>
            </div>
          </div>
        </div>

        <!-- Edit Profile -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
          <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-4">Edit Profile</h2>

          <form @submit.prevent="updateProfile" class="space-y-3">
            <div>
              <label class="block text-[11px] text-slate-500 mb-1.5">Name</label>
              <input
                v-model="profileForm.name"
                type="text"
                required
                class="w-full bg-slate-800 border border-slate-700 text-white text-sm px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label class="block text-[11px] text-slate-500 mb-1.5">Email</label>
              <input
                v-model="profileForm.email"
                type="email"
                required
                class="w-full bg-slate-800 border border-slate-700 text-white text-sm px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label class="block text-[11px] text-slate-500 mb-1.5">New Password <span class="text-slate-600">(leave blank to keep)</span></label>
              <input
                v-model="profileForm.password"
                type="password"
                autocomplete="new-password"
                placeholder="••••••••"
                class="w-full bg-slate-800 border border-slate-700 text-white placeholder-slate-600 text-sm px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div v-if="profileForm.password">
              <label class="block text-[11px] text-slate-500 mb-1.5">Confirm Password</label>
              <input
                v-model="profileForm.password_confirmation"
                type="password"
                autocomplete="new-password"
                placeholder="••••••••"
                class="w-full bg-slate-800 border border-slate-700 text-white placeholder-slate-600 text-sm px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <button
              type="submit"
              :disabled="profileProcessing"
              class="w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-sm font-semibold py-2 rounded-xl transition-colors"
            >
              {{ profileProcessing ? 'Saving...' : 'Save Changes' }}
            </button>
          </form>
        </div>

        <!-- Change Plan -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-5">
          <h2 class="text-xs font-semibold text-slate-500 uppercase tracking-wider mb-4">Change Plan</h2>

          <form @submit.prevent="updateSubscription" class="space-y-3">
            <div>
              <label class="block text-[11px] text-slate-500 mb-1.5">Plan</label>
              <select
                v-model="subForm.plan_id"
                class="w-full bg-slate-800 border border-slate-700 text-white text-sm px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
              >
                <option v-for="p in plans" :key="p.id" :value="p.id">{{ p.label }}</option>
              </select>
            </div>
            <div>
              <label class="block text-[11px] text-slate-500 mb-1.5">Status</label>
              <select
                v-model="subForm.status"
                class="w-full bg-slate-800 border border-slate-700 text-white text-sm px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
              >
                <option value="trial">Trial</option>
                <option value="active">Active</option>
                <option value="suspended">Suspended</option>
                <option value="expired">Expired</option>
              </select>
            </div>
            <div>
              <label class="block text-[11px] text-slate-500 mb-1.5">Expires At (optional)</label>
              <input
                v-model="subForm.expires_at"
                type="date"
                class="w-full bg-slate-800 border border-slate-700 text-white text-sm px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
              />
            </div>
            <div>
              <label class="block text-[11px] text-slate-500 mb-1.5">Notes (optional)</label>
              <textarea
                v-model="subForm.notes"
                rows="2"
                placeholder="Reason for change..."
                class="w-full bg-slate-800 border border-slate-700 text-white placeholder-slate-600 text-sm px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"
              />
            </div>
            <button
              type="submit"
              :disabled="subProcessing"
              class="w-full bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-sm font-semibold py-2 rounded-xl transition-colors"
            >
              {{ subProcessing ? 'Saving...' : 'Update Subscription' }}
            </button>
          </form>
        </div>

        <!-- Danger Zone -->
        <div class="bg-slate-900 border border-red-900/40 rounded-2xl p-5">
          <h2 class="text-xs font-semibold text-red-500/80 uppercase tracking-wider mb-4">Danger Zone</h2>
          <div class="space-y-2">
            <Link
              v-if="tenant.subscription_status?.toLowerCase() !== 'suspended'"
              :href="`/admin/tenants/${tenant.id}/suspend`"
              method="patch"
              as="button"
              @click.prevent="confirmSuspend"
              class="w-full text-sm text-red-400 hover:text-red-300 bg-red-500/10 hover:bg-red-500/20 border border-red-500/30 font-medium py-2 rounded-xl transition-colors text-center block"
            >
              Suspend Tenant
            </Link>
            <Link
              v-else
              :href="`/admin/tenants/${tenant.id}/unsuspend`"
              method="patch"
              as="button"
              class="w-full text-sm text-green-400 hover:text-green-300 bg-green-500/10 hover:bg-green-500/20 border border-green-500/30 font-medium py-2 rounded-xl transition-colors text-center block"
            >
              Reactivate Tenant
            </Link>
          </div>
        </div>

      </div>
    </div>

    <!-- Suspend Confirm Modal -->
    <div v-if="showSuspendModal" class="fixed inset-0 bg-black/70 flex items-center justify-center z-50 p-4">
      <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 max-w-sm w-full shadow-2xl">
        <h3 class="text-base font-bold text-white mb-2">Suspend Tenant?</h3>
        <p class="text-sm text-slate-400 mb-5">
          This will immediately block <strong class="text-white">{{ tenant.name }}</strong> from accessing their panel. They will see a subscription suspended message.
        </p>
        <div class="flex gap-3">
          <Link
            :href="`/admin/tenants/${tenant.id}/suspend`"
            method="patch"
            as="button"
            class="flex-1 bg-red-600 hover:bg-red-700 text-white text-sm font-semibold py-2.5 rounded-xl transition-colors text-center"
            @click="showSuspendModal = false"
          >
            Yes, Suspend
          </Link>
          <button
            @click="showSuspendModal = false"
            class="flex-1 bg-slate-800 hover:bg-slate-700 text-slate-300 text-sm font-medium py-2.5 rounded-xl transition-colors"
          >
            Cancel
          </button>
        </div>
      </div>
    </div>

  </AdminLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
  tenant:  { type: Object, required: true },
  history: { type: Array, default: () => [] },
  plans:   { type: Array, default: () => [] },
  stats:   { type: Object, required: true },
});

// Profile form
const profileForm = reactive({
  name:                  props.tenant.name,
  email:                 props.tenant.email,
  password:              '',
  password_confirmation: '',
});

const profileProcessing = ref(false);

function updateProfile() {
  profileProcessing.value = true;
  router.patch(`/admin/tenants/${props.tenant.id}/profile`, profileForm, {
    onSuccess: () => {
      profileForm.password              = '';
      profileForm.password_confirmation = '';
    },
    onFinish: () => { profileProcessing.value = false; },
  });
}

// Subscription form pre-filled with current values
const subForm = reactive({
  plan_id:    props.tenant.plan ? props.plans.find(p => p.name === props.tenant.plan.name)?.id ?? props.plans[0]?.id : props.plans[0]?.id,
  status:     props.tenant.subscription_status?.toLowerCase() ?? 'active',
  expires_at: props.tenant.expires_at ?? '',
  notes:      '',
});

const subProcessing  = ref(false);
const showSuspendModal = ref(false);

function updateSubscription() {
  subProcessing.value = true;
  router.patch(`/admin/tenants/${props.tenant.id}/subscription`, subForm, {
    onFinish: () => { subProcessing.value = false; },
  });
}

function confirmSuspend() {
  showSuspendModal.value = true;
}

function planBadgeClass(name) {
  return {
    pro:        'bg-indigo-500/20 text-indigo-400 border border-indigo-500/30',
    start:      'bg-amber-500/20 text-amber-400 border border-amber-500/30',
    enterprise: 'bg-purple-500/20 text-purple-400 border border-purple-500/30',
    trial:      'bg-slate-700 text-slate-400 border border-slate-600',
  }[name] ?? 'bg-slate-700 text-slate-400 border border-slate-600';
}

function statusBadgeClass(status) {
  const s = status?.toLowerCase();
  if (s === 'active')    return 'bg-green-500/20 text-green-400 border border-green-500/30';
  if (s === 'trial')     return 'bg-amber-500/20 text-amber-400 border border-amber-500/30';
  if (s === 'suspended') return 'bg-red-500/20 text-red-400 border border-red-500/30';
  if (s === 'expired')   return 'bg-slate-700 text-slate-400 border border-slate-600';
  return 'bg-slate-700 text-slate-400 border border-slate-600';
}
</script>
