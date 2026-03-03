<template>
  <AdminLayout title="Dashboard">

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">
      <div
        v-for="card in statCards"
        :key="card.label"
        class="bg-slate-900 border border-slate-800 rounded-2xl p-5"
      >
        <p class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider mb-1.5">{{ card.label }}</p>
        <p class="text-2xl font-bold" :class="card.color">{{ card.value }}</p>
        <p v-if="card.sub" class="text-[10px] text-slate-600 mt-0.5">{{ card.sub }}</p>
      </div>
    </div>

    <!-- Recent Tenants -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl">
      <div class="flex items-center justify-between px-6 py-4 border-b border-slate-800">
        <h2 class="text-sm font-semibold text-white">Recent Tenants</h2>
        <Link href="/admin/tenants" class="text-xs text-indigo-400 hover:text-indigo-300 transition-colors">
          View all &rarr;
        </Link>
      </div>

      <div class="divide-y divide-slate-800">
        <div v-if="recentTenants.length === 0" class="px-6 py-8 text-center text-slate-500 text-sm">
          No tenants yet. <Link href="/admin/tenants/create" class="text-indigo-400 hover:underline">Create the first one</Link>.
        </div>
        <div
          v-for="tenant in recentTenants"
          :key="tenant.id"
          class="flex items-center gap-4 px-6 py-4 hover:bg-slate-800/50 transition-colors"
        >
          <!-- Avatar -->
          <div class="w-9 h-9 rounded-full bg-indigo-500/20 border border-indigo-500/30 flex items-center justify-center flex-shrink-0">
            <span class="text-xs font-bold text-indigo-400">{{ tenant.name[0].toUpperCase() }}</span>
          </div>

          <!-- Info -->
          <div class="flex-1 min-w-0">
            <p class="text-sm font-medium text-white truncate">{{ tenant.name }}</p>
            <p class="text-xs text-slate-500 truncate">{{ tenant.tenant_id }}.oneshieldx.com</p>
          </div>

          <!-- Plan badge -->
          <span class="text-[10px] font-bold px-2 py-0.5 rounded-full shrink-0" :class="planBadgeClass(tenant.plan?.name)">
            {{ tenant.plan?.label ?? 'No plan' }}
          </span>

          <!-- Status badge -->
          <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full shrink-0" :class="statusBadgeClass(tenant.subscription_status)">
            {{ tenant.subscription_status }}
          </span>

          <!-- Date -->
          <p class="text-xs text-slate-600 shrink-0">{{ tenant.created_at }}</p>

          <!-- Link -->
          <Link
            :href="`/admin/tenants/${tenant.id}`"
            class="text-slate-500 hover:text-indigo-400 transition-colors shrink-0"
          >
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
            </svg>
          </Link>
        </div>
      </div>
    </div>

  </AdminLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
  stats: { type: Object, required: true },
  recentTenants: { type: Array, default: () => [] },
});

const statCards = computed(() => [
  {
    label: 'Total Tenants',
    value: props.stats.total_tenants,
    color: 'text-white',
  },
  {
    label: 'Active',
    value: props.stats.active,
    color: 'text-green-400',
  },
  {
    label: 'Trial',
    value: props.stats.trial,
    color: 'text-amber-400',
  },
  {
    label: 'Suspended',
    value: props.stats.suspended,
    color: 'text-red-400',
  },
  {
    label: 'MRR',
    value: '$' + Number(props.stats.mrr).toLocaleString(),
    color: 'text-indigo-400',
    sub: 'Monthly recurring',
  },
]);

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
  if (s === 'active') return 'bg-green-500/20 text-green-400 border border-green-500/30';
  if (s === 'trial')  return 'bg-amber-500/20 text-amber-400 border border-amber-500/30';
  if (s === 'suspended') return 'bg-red-500/20 text-red-400 border border-red-500/30';
  if (s === 'expired')   return 'bg-slate-700 text-slate-400 border border-slate-600';
  return 'bg-slate-700 text-slate-400 border border-slate-600';
}
</script>
