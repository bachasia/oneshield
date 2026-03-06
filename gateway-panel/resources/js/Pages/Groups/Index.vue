<template>
  <AppLayout title="Groups">

    <!-- Header -->
    <div class="flex items-center justify-between mb-6">
      <div>
        <p class="text-sm text-gray-500">Organize Shield Sites into routing groups</p>
      </div>
      <button
        @click="openCreate"
        class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors cursor-pointer"
      >
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
        New Group
      </button>
    </div>

    <!-- Empty state -->
    <div v-if="groups.length === 0" class="bg-white rounded-xl border border-gray-200 border-dashed py-16 text-center">
      <div class="flex flex-col items-center gap-3 text-gray-400">
        <div class="w-14 h-14 rounded-2xl bg-gray-100 flex items-center justify-center">
          <svg class="w-7 h-7 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="1" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/>
          </svg>
        </div>
        <div>
          <p class="font-medium text-gray-600">No groups yet</p>
          <p class="text-sm mt-1">Groups let you route payments to specific sets of Shield Sites.</p>
        </div>
        <button @click="openCreate" class="mt-2 text-sm text-indigo-600 hover:text-indigo-700 font-medium cursor-pointer">Create your first group →</button>
      </div>
    </div>

    <!-- Group Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div
        v-for="(group, idx) in groups"
        :key="group.id"
        class="bg-white rounded-xl border border-gray-200 p-5 hover:shadow-sm transition-all group/card"
      >
        <!-- Card header -->
        <div class="flex items-start gap-3 mb-3">
          <!-- Colored folder icon cycling through palette -->
          <div
            class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
            :class="groupColors[idx % groupColors.length].bg"
          >
            <svg class="w-5 h-5" :class="groupColors[idx % groupColors.length].icon" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/>
            </svg>
          </div>
          <div class="flex-1 min-w-0">
            <h3 class="font-semibold text-gray-900 truncate">{{ group.name }}</h3>
            <p v-if="group.description" class="text-xs text-gray-500 mt-0.5 line-clamp-2">{{ group.description }}</p>
          </div>
          <!-- Actions (show on hover) -->
          <div class="flex gap-1 opacity-0 group-hover/card:opacity-100 transition-opacity">
            <button
              @click="editGroup(group)"
              class="p-1.5 rounded-lg hover:bg-indigo-50 text-gray-400 hover:text-indigo-600 transition-colors cursor-pointer"
              title="Edit"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10"/></svg>
            </button>
            <button
              @click="confirmDelete(group)"
              class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500 transition-colors cursor-pointer"
              title="Delete"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
            </button>
          </div>
        </div>

        <!-- Footer stats -->
        <div class="flex items-center justify-between pt-3 border-t border-gray-100">
          <div class="flex items-center gap-1.5 text-sm text-gray-600">
            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>
            <span class="font-semibold text-gray-800">{{ group.shield_sites_count }}</span>
            <span class="text-gray-500">sites</span>
          </div>
          <code class="text-[10px] font-mono text-gray-400 bg-gray-50 px-1.5 py-0.5 rounded border border-gray-100">ID: {{ group.id }}</code>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="showModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-md overflow-hidden">
        <!-- Modal header -->
        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
          <div class="w-8 h-8 rounded-lg bg-indigo-50 flex items-center justify-center">
            <svg class="w-4 h-4 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>
          </div>
          <h3 class="text-sm font-semibold text-gray-900">{{ editingGroup ? 'Edit Group' : 'Create New Group' }}</h3>
          <button @click="closeModal" class="ml-auto text-gray-400 hover:text-gray-600 cursor-pointer transition-colors">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
          </button>
        </div>

        <form @submit.prevent="submitGroup" class="px-6 py-5 space-y-4">
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Group Name <span class="text-red-500">*</span></label>
            <input
              v-model="groupForm.name"
              type="text"
              placeholder="e.g. DTC, EU Stores, High Volume..."
              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 focus:outline-none"
            />
            <p v-if="groupForm.errors.name" class="text-red-500 text-xs mt-1">{{ groupForm.errors.name }}</p>
          </div>
          <div>
            <label class="block text-xs font-semibold text-gray-700 mb-1.5">Description <span class="text-gray-400 font-normal">(optional)</span></label>
            <textarea
              v-model="groupForm.description"
              rows="3"
              placeholder="What is this group used for?"
              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm resize-none focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 focus:outline-none"
            />
          </div>
          <div class="flex gap-3 pt-1">
            <button type="button" @click="closeModal" class="flex-1 px-4 py-2.5 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50 font-medium transition-colors cursor-pointer">
              Cancel
            </button>
            <button type="submit" :disabled="groupForm.processing" class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white px-4 py-2.5 rounded-lg text-sm font-medium transition-colors cursor-pointer disabled:cursor-not-allowed">
              {{ editingGroup ? 'Save Changes' : 'Create Group' }}
            </button>
          </div>
        </form>
      </div>
    </div>

  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ groups: Array });

const groupColors = [
  { bg: 'bg-indigo-50',  icon: 'text-indigo-500' },
  { bg: 'bg-emerald-50', icon: 'text-emerald-500' },
  { bg: 'bg-amber-50',   icon: 'text-amber-500' },
  { bg: 'bg-rose-50',    icon: 'text-rose-500' },
  { bg: 'bg-sky-50',     icon: 'text-sky-500' },
  { bg: 'bg-violet-50',  icon: 'text-violet-500' },
];

const showModal    = ref(false);
const editingGroup = ref(null);
const groupForm    = useForm({ name: '', description: '' });

function openCreate() {
  editingGroup.value = null;
  groupForm.reset();
  showModal.value = true;
}

function editGroup(group) {
  editingGroup.value    = group;
  groupForm.name        = group.name;
  groupForm.description = group.description || '';
  showModal.value       = true;
}

function closeModal() {
  showModal.value    = false;
  editingGroup.value = null;
  groupForm.reset();
}

function submitGroup() {
  if (editingGroup.value) {
    groupForm.patch(`/groups/${editingGroup.value.id}`, { onSuccess: closeModal });
  } else {
    groupForm.post('/groups', { onSuccess: closeModal });
  }
}

function confirmDelete(group) {
  if (confirm(`Delete group "${group.name}"? Sites in this group will be unassigned.`)) {
    router.delete(`/groups/${group.id}`);
  }
}
</script>
