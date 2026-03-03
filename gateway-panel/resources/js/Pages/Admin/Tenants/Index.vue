<template>
  <AdminLayout title="Tenants">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <h1 class="text-xl font-bold text-white">Tenants</h1>
        <p class="text-sm text-slate-500 mt-0.5">{{ tenants.total }} registered tenants</p>
      </div>
      <Link
        href="/admin/tenants/create"
        class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-xl transition-colors shadow-sm"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/>
        </svg>
        New Tenant
      </Link>
    </div>

    <!-- Filters -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-4 mb-4">
      <div class="flex flex-wrap gap-3">
        <input
          v-model="search"
          type="text"
          placeholder="Search name, email, tenant ID..."
          class="flex-1 min-w-[200px] bg-slate-800 border border-slate-700 text-white placeholder-slate-500 text-sm px-4 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
          @keyup.enter="applyFilters"
        />
        <select
          v-model="planFilter"
          class="bg-slate-800 border border-slate-700 text-white text-sm px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <option value="">All Plans</option>
          <option v-for="p in plans" :key="p.id" :value="p.name">{{ p.label }}</option>
        </select>
        <select
          v-model="statusFilter"
          class="bg-slate-800 border border-slate-700 text-white text-sm px-3 py-2 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <option value="">All Statuses</option>
          <option value="trial">Trial</option>
          <option value="active">Active</option>
          <option value="suspended">Suspended</option>
          <option value="expired">Expired</option>
        </select>
        <button
          @click="applyFilters"
          class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold px-4 py-2 rounded-xl transition-colors"
        >
          Filter
        </button>
        <button
          v-if="hasFilters"
          @click="clearFilters"
          class="bg-slate-800 hover:bg-slate-700 text-slate-400 text-sm px-4 py-2 rounded-xl transition-colors"
        >
          Clear
        </button>
      </div>
    </div>

    <!-- Table -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl overflow-hidden">
      <table class="w-full">
        <thead>
          <tr class="border-b border-slate-800">
            <th class="px-6 py-3.5 text-left text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Tenant</th>
            <th class="px-6 py-3.5 text-left text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Subdomain</th>
            <th class="px-6 py-3.5 text-left text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Plan</th>
            <th class="px-6 py-3.5 text-left text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Status</th>
            <th class="px-6 py-3.5 text-left text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Sites</th>
            <th class="px-6 py-3.5 text-left text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Expires</th>
            <th class="px-6 py-3.5 text-left text-[11px] font-semibold text-slate-500 uppercase tracking-wider">Joined</th>
            <th class="px-6 py-3.5"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800">
          <tr v-if="tenants.data.length === 0">
            <td colspan="8" class="px-6 py-10 text-center text-slate-500 text-sm">
              No tenants found.
            </td>
          </tr>
          <tr
            v-for="tenant in tenants.data"
            :key="tenant.id"
            class="hover:bg-slate-800/50 transition-colors"
          >
            <td class="px-6 py-4">
              <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-full bg-indigo-500/20 border border-indigo-500/30 flex items-center justify-center flex-shrink-0">
                  <span class="text-xs font-bold text-indigo-400">{{ tenant.name[0].toUpperCase() }}</span>
                </div>
                <div>
                  <p class="text-sm font-medium text-white">{{ tenant.name }}</p>
                  <p class="text-xs text-slate-500">{{ tenant.email }}</p>
                </div>
              </div>
            </td>
            <td class="px-6 py-4">
              <code class="text-xs text-slate-400 bg-slate-800 px-2 py-1 rounded-lg">{{ tenant.tenant_id }}</code>
            </td>
            <td class="px-6 py-4">
              <span class="text-[10px] font-bold px-2 py-0.5 rounded-full" :class="planBadgeClass(tenant.plan?.name)">
                {{ tenant.plan?.label ?? 'None' }}
              </span>
            </td>
            <td class="px-6 py-4">
              <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full" :class="statusBadgeClass(tenant.subscription_status)">
                {{ tenant.subscription_status }}
              </span>
            </td>
            <td class="px-6 py-4 text-sm text-slate-400">
              {{ tenant.sites_used }}
              <span class="text-slate-600">
                / {{ tenant.plan?.max_shield_sites >= 999 ? '∞' : (tenant.plan?.max_shield_sites ?? '?') }}
              </span>
            </td>
            <td class="px-6 py-4 text-xs text-slate-500">
              {{ tenant.expires_at ?? '—' }}
            </td>
            <td class="px-6 py-4 text-xs text-slate-600">
              {{ tenant.created_at }}
            </td>
            <td class="px-6 py-4 text-right">
              <Link
                :href="`/admin/tenants/${tenant.id}`"
                class="text-xs text-indigo-400 hover:text-indigo-300 font-medium transition-colors"
              >
                View &rarr;
              </Link>
            </td>
          </tr>
        </tbody>
      </table>

      <!-- Pagination -->
      <div v-if="tenants.last_page > 1" class="border-t border-slate-800 px-6 py-4 flex items-center justify-between">
        <p class="text-xs text-slate-500">
          Showing {{ tenants.from }}–{{ tenants.to }} of {{ tenants.total }}
        </p>
        <div class="flex gap-2">
          <Link
            v-for="link in tenants.links"
            :key="link.label"
            :href="link.url ?? '#'"
            :class="[
              'px-3 py-1.5 rounded-lg text-xs font-medium transition-colors',
              link.active
                ? 'bg-indigo-600 text-white'
                : link.url
                  ? 'text-slate-400 hover:bg-slate-800 hover:text-white'
                  : 'text-slate-700 cursor-not-allowed',
            ]"
            v-html="link.label"
            preserve-scroll
          />
        </div>
      </div>
    </div>

  </AdminLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
  tenants: { type: Object, required: true },
  plans:   { type: Array, default: () => [] },
  filters: { type: Object, default: () => ({}) },
});

const search       = ref(props.filters.search ?? '');
const planFilter   = ref(props.filters.plan ?? '');
const statusFilter = ref(props.filters.status ?? '');

const hasFilters = computed(() => search.value || planFilter.value || statusFilter.value);

function applyFilters() {
  router.get('/admin/tenants', {
    search: search.value || undefined,
    plan:   planFilter.value || undefined,
    status: statusFilter.value || undefined,
  }, { preserveState: true, replace: true });
}

function clearFilters() {
  search.value = '';
  planFilter.value = '';
  statusFilter.value = '';
  router.get('/admin/tenants', {}, { preserveState: false });
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
