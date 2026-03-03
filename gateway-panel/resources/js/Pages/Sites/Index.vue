<template>
  <AppLayout title="Payment Sites">
    <div class="flex items-center justify-between mb-6">
      <div class="flex gap-3">
        <!-- Filter by group -->
        <select
          v-model="filterGroup"
          @change="applyFilters"
          class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white"
        >
          <option value="">All Groups</option>
          <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
        </select>

        <!-- Filter by status -->
        <select
          v-model="filterStatus"
          @change="applyFilters"
          class="px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-lg text-sm dark:bg-gray-700 dark:text-white"
        >
          <option value="">All Status</option>
          <option value="active">Active</option>
          <option value="inactive">Inactive</option>
        </select>
      </div>

      <button
        @click="showAddModal = true"
        class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium"
      >
        + Add Site
      </button>
    </div>

    <!-- Sites Table -->
    <div class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
              <th class="px-6 py-4 font-medium">Site</th>
              <th class="px-6 py-4 font-medium">Group</th>
              <th class="px-6 py-4 font-medium">Gateways</th>
              <th class="px-6 py-4 font-medium">Status</th>
              <th class="px-6 py-4 font-medium">Last Active</th>
              <th class="px-6 py-4 font-medium">Actions</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
            <tr v-if="sites.data.length === 0">
              <td colspan="6" class="px-6 py-12 text-center text-gray-400">
                No mesh sites connected yet. Click "Add Site" to get started.
              </td>
            </tr>
            <tr v-for="site in sites.data" :key="site.id" class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
              <td class="px-6 py-4">
                <div class="font-medium text-gray-900 dark:text-white">{{ site.name }}</div>
                <div class="text-xs text-gray-500 mt-0.5">{{ site.url }}</div>
              </td>
              <td class="px-6 py-4 text-gray-600 dark:text-gray-400">
                {{ site.group?.name ?? '—' }}
              </td>
              <td class="px-6 py-4">
                <div class="flex gap-1">
                  <span v-if="site.paypal_client_id" class="px-2 py-0.5 bg-blue-100 text-blue-700 rounded text-xs">PayPal</span>
                  <span v-if="site.stripe_public_key" class="px-2 py-0.5 bg-purple-100 text-purple-700 rounded text-xs">Stripe</span>
                </div>
              </td>
              <td class="px-6 py-4">
                <!-- Toggle switch -->
                <button
                  @click="toggleSite(site)"
                  class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors"
                  :class="site.is_active ? 'bg-indigo-600' : 'bg-gray-300'"
                >
                  <span
                    class="inline-block h-3.5 w-3.5 transform rounded-full bg-white transition-transform"
                    :class="site.is_active ? 'translate-x-4.5' : 'translate-x-0.5'"
                  />
                </button>
              </td>
              <td class="px-6 py-4 text-xs text-gray-500">
                {{ site.last_heartbeat_at ? formatRelative(site.last_heartbeat_at) : 'Never' }}
              </td>
              <td class="px-6 py-4">
                <button
                  @click="editSite(site)"
                  class="text-indigo-600 hover:text-indigo-700 text-xs font-medium mr-3"
                >Settings</button>
                <button
                  @click="confirmDelete(site)"
                  class="text-red-500 hover:text-red-700 text-xs font-medium"
                >Delete</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Pagination -->
      <div v-if="sites.last_page > 1" class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex gap-2">
        <Link
          v-for="link in sites.links"
          :key="link.label"
          :href="link.url || ''"
          v-html="link.label"
          class="px-3 py-1 rounded text-sm"
          :class="link.active
            ? 'bg-indigo-600 text-white'
            : link.url ? 'text-gray-600 hover:bg-gray-100' : 'text-gray-300 cursor-not-allowed'"
        />
      </div>
    </div>

    <!-- Add/Edit Site Modal -->
    <div v-if="showAddModal || editingSite" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-lg p-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-5">
          {{ editingSite ? 'Site Settings' : 'Add Mesh Site' }}
        </h3>

        <form @submit.prevent="submitSite" class="space-y-4">
          <div v-if="!editingSite">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Site Name</label>
            <input v-model="siteForm.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500" />
          </div>

          <div v-if="!editingSite">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Site URL</label>
            <input v-model="siteForm.url" type="url" placeholder="https://mesh-site.com" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Group</label>
            <select v-model="siteForm.group_id" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 dark:bg-gray-700">
              <option :value="null">No Group</option>
              <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
            </select>
          </div>

          <!-- PayPal Section -->
          <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4">
            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">PayPal</h4>
            <div class="space-y-2">
              <input v-model="siteForm.paypal_client_id" type="password" placeholder="Client ID" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
              <input v-model="siteForm.paypal_secret" type="password" placeholder="Secret" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
              <select v-model="siteForm.paypal_mode" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm dark:bg-gray-700">
                <option value="sandbox">Sandbox</option>
                <option value="live">Live</option>
              </select>
            </div>
          </div>

          <!-- Stripe Section -->
          <div class="border border-gray-200 dark:border-gray-600 rounded-lg p-4">
            <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">Stripe</h4>
            <div class="space-y-2">
              <input v-model="siteForm.stripe_public_key" type="password" placeholder="Publishable Key (pk_...)" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
              <input v-model="siteForm.stripe_secret_key" type="password" placeholder="Secret Key (sk_...)" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
              <select v-model="siteForm.stripe_mode" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm dark:bg-gray-700">
                <option value="test">Test</option>
                <option value="live">Live</option>
              </select>
            </div>
          </div>

          <div class="flex gap-3 pt-2">
            <button type="button" @click="closeModal" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
              Cancel
            </button>
            <button type="submit" :disabled="siteForm.processing" class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-sm font-medium">
              {{ editingSite ? 'Save Changes' : 'Add Site' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
  sites: Object,
  groups: Array,
  filters: Object,
});

const filterGroup = ref(props.filters.group_id || '');
const filterStatus = ref(props.filters.status || '');
const showAddModal = ref(false);
const editingSite = ref(null);

const siteForm = useForm({
  name: '',
  url: '',
  group_id: null,
  paypal_client_id: '',
  paypal_secret: '',
  paypal_mode: 'sandbox',
  stripe_public_key: '',
  stripe_secret_key: '',
  stripe_mode: 'test',
});

function applyFilters() {
  router.get('/sites', { group_id: filterGroup.value, status: filterStatus.value }, { preserveState: true });
}

function editSite(site) {
  editingSite.value = site;
  siteForm.group_id = site.group_id;
  siteForm.paypal_mode = site.paypal_mode;
  siteForm.stripe_mode = site.stripe_mode;
}

function closeModal() {
  showAddModal.value = false;
  editingSite.value = null;
  siteForm.reset();
}

function submitSite() {
  if (editingSite.value) {
    siteForm.patch(`/sites/${editingSite.value.id}`, { onSuccess: closeModal });
  } else {
    siteForm.post('/sites', { onSuccess: closeModal });
  }
}

function toggleSite(site) {
  router.patch(`/sites/${site.id}/toggle`);
}

function confirmDelete(site) {
  if (confirm(`Remove site "${site.name}"?`)) {
    router.delete(`/sites/${site.id}`);
  }
}

function formatRelative(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr)) / 60000);
  if (diff < 1) return 'Just now';
  if (diff < 60) return `${diff}m ago`;
  if (diff < 1440) return `${Math.floor(diff / 60)}h ago`;
  return `${Math.floor(diff / 1440)}d ago`;
}
</script>
