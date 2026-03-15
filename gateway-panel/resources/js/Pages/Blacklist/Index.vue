<template>
  <AppLayout title="Blacklist">

    <!-- Page Header -->
    <div class="mb-1">
      <h1 class="text-xl font-semibold text-gray-800">Blacklist</h1>
      <p class="text-sm text-gray-500 mt-0.5">Block known test-buyers from using OneShield payment methods</p>
    </div>

    <!-- Stats bar -->
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mt-4 mb-5">
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Total</p>
        <p class="text-2xl font-bold text-gray-800 mt-0.5">{{ stats.total }}</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Emails</p>
        <p class="text-2xl font-bold text-indigo-600 mt-0.5">{{ stats.emails }}</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Addresses</p>
        <p class="text-2xl font-bold text-indigo-600 mt-0.5">{{ stats.addresses }}</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide">PgPrints</p>
        <p class="text-2xl font-bold text-amber-600 mt-0.5">{{ stats.pgprints }}</p>
      </div>
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-3">
        <p class="text-[10px] font-semibold text-gray-400 uppercase tracking-wide">Custom</p>
        <p class="text-2xl font-bold text-emerald-600 mt-0.5">{{ stats.custom }}</p>
      </div>
    </div>

    <!-- Last import notice -->
    <div v-if="lastImport" class="mb-4 text-xs text-gray-400">
      pgprints last imported: {{ lastImport }}
    </div>

    <!-- Filter tabs + Add form row -->
    <div class="flex flex-wrap items-center gap-3 mb-4">
      <!-- Filter tabs -->
      <div class="flex bg-white border border-gray-200 rounded-lg overflow-hidden text-sm">
        <button
          v-for="tab in tabs"
          :key="tab.value"
          @click="applyFilter(tab)"
          class="px-4 py-2 font-medium transition-colors cursor-pointer"
          :class="activeTab === tab.value
            ? 'bg-indigo-600 text-white'
            : 'text-gray-600 hover:bg-gray-50'"
        >
          {{ tab.label }}
        </button>
      </div>

      <!-- Add entry button -->
      <button
        @click="showAddForm = !showAddForm"
        class="ml-auto flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors cursor-pointer"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        Add Entry
      </button>
    </div>

    <!-- Add entry form -->
    <div v-if="showAddForm" class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
      <h3 class="text-sm font-semibold text-gray-800 mb-4">Add Custom Entry</h3>
      <form @submit.prevent="submitAdd" class="flex flex-wrap gap-3 items-end">
        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1">Type</label>
          <select v-model="addForm.type" class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500">
            <option value="email">Email</option>
            <option value="address">Address</option>
          </select>
        </div>
        <div class="flex-1 min-w-[220px]">
          <label class="block text-xs font-medium text-gray-600 mb-1">Value</label>
          <input
            v-model="addForm.value"
            type="text"
            :placeholder="addForm.type === 'email' ? 'test@example.com' : '123 Main St, Springfield'"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
            required
          />
        </div>
        <div class="flex-1 min-w-[160px]">
          <label class="block text-xs font-medium text-gray-600 mb-1">Notes <span class="text-gray-400 font-normal">(optional)</span></label>
          <input
            v-model="addForm.notes"
            type="text"
            placeholder="e.g. Frequent chargebacker"
            class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
          />
        </div>
        <div class="flex gap-2">
          <button type="button" @click="showAddForm = false" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-600 hover:bg-gray-50 cursor-pointer">Cancel</button>
          <button type="submit" :disabled="addForm.processing" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white rounded-lg text-sm font-medium cursor-pointer">Add</button>
        </div>
      </form>
      <p v-if="addForm.errors.value" class="text-red-500 text-xs mt-2">{{ addForm.errors.value }}</p>
    </div>

    <!-- Table -->
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="bg-gray-50 border-b border-gray-200">
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Type</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Value</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Source</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Notes</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">Added</th>
              <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide w-16"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-if="entries.data.length === 0">
              <td colspan="6" class="px-5 py-14 text-center">
                <div class="flex flex-col items-center gap-2 text-gray-400">
                  <svg class="w-10 h-10 text-gray-200" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                  <span class="text-sm font-medium text-gray-500">No blacklist entries</span>
                  <span class="text-xs">Import pgprints or add custom entries above</span>
                </div>
              </td>
            </tr>
            <tr v-for="entry in entries.data" :key="entry.id" class="hover:bg-gray-50 transition-colors">
              <!-- Type badge -->
              <td class="px-5 py-3">
                <span
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="entry.type === 'email' ? 'bg-blue-50 text-blue-700' : 'bg-purple-50 text-purple-700'"
                >
                  {{ entry.type === 'email' ? 'Email' : 'Address' }}
                </span>
              </td>
              <!-- Value -->
              <td class="px-5 py-3 font-mono text-xs text-gray-700 max-w-xs truncate">{{ entry.value }}</td>
              <!-- Source badge -->
              <td class="px-5 py-3">
                <span
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="entry.source === 'pgprints' ? 'bg-amber-50 text-amber-700' : 'bg-emerald-50 text-emerald-700'"
                >
                  <svg v-if="entry.source === 'pgprints'" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                  {{ entry.source === 'pgprints' ? 'pgprints' : 'custom' }}
                </span>
              </td>
              <!-- Notes -->
              <td class="px-5 py-3 text-xs text-gray-500">{{ entry.notes ?? '—' }}</td>
              <!-- Date -->
              <td class="px-5 py-3 text-xs text-gray-400">{{ formatDate(entry.created_at) }}</td>
              <!-- Delete (custom only) -->
              <td class="px-5 py-3 text-right">
                <button
                  v-if="entry.source === 'custom'"
                  @click="deleteEntry(entry)"
                  class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500 transition-colors cursor-pointer"
                  title="Delete"
                >
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                </button>
                <span v-else class="p-1.5 text-gray-200" title="pgprints entries are read-only">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="entries.last_page > 1" class="px-5 py-3 border-t border-gray-100 flex items-center justify-between text-xs text-gray-500">
        <span>Showing {{ entries.from }}–{{ entries.to }} of {{ entries.total }}</span>
        <div class="flex gap-1">
          <Link
            v-for="link in entries.links"
            :key="link.label"
            :href="link.url ?? '#'"
            class="px-2.5 py-1 rounded border text-xs transition-colors"
            :class="link.active
              ? 'bg-indigo-600 text-white border-indigo-600'
              : link.url
                ? 'border-gray-300 text-gray-600 hover:bg-gray-50'
                : 'border-gray-200 text-gray-300 cursor-not-allowed'"
            v-html="link.label"
          />
        </div>
      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
  entries:    Object,
  stats:      Object,
  lastImport: String,
  filters:    Object,
});

// ── Filter tabs ───────────────────────────────────────────────────────────

const tabs = [
  { label: 'All',      value: '',        params: {} },
  { label: 'Email',    value: 'email',   params: { type: 'email' } },
  { label: 'Address',  value: 'address', params: { type: 'address' } },
  { label: 'PgPrints', value: 'pgprints',params: { source: 'pgprints' } },
  { label: 'Custom',   value: 'custom',  params: { source: 'custom' } },
];

const activeTab = ref(props.filters?.source || props.filters?.type || '');

function applyFilter(tab) {
  activeTab.value = tab.value;
  router.get('/blacklist', tab.params, { preserveState: true, replace: true });
}

// ── Add form ──────────────────────────────────────────────────────────────

const showAddForm = ref(false);
const addForm = useForm({ type: 'email', value: '', notes: '' });

function submitAdd() {
  addForm.post('/blacklist', {
    onSuccess: () => {
      addForm.reset();
      showAddForm.value = false;
    },
  });
}

// ── Delete ────────────────────────────────────────────────────────────────

function deleteEntry(entry) {
  if (!confirm(`Remove "${entry.value}" from blacklist?`)) return;
  router.delete(`/blacklist/${entry.id}`);
}

// ── Helpers ───────────────────────────────────────────────────────────────

function formatDate(iso) {
  if (!iso) return '—';
  return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
}
</script>
