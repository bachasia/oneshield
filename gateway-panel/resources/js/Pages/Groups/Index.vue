<template>
  <AppLayout title="Groups">
    <div class="flex justify-end mb-6">
      <button @click="showModal = true" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
        + New Group
      </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <div v-if="groups.length === 0" class="col-span-full text-center py-12 text-gray-400">
        No groups yet. Groups help you organize mesh sites for routing.
      </div>

      <div
        v-for="group in groups"
        :key="group.id"
        class="bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 p-6"
      >
        <div class="flex items-start justify-between mb-2">
          <h3 class="font-semibold text-gray-900 dark:text-white">{{ group.name }}</h3>
          <div class="flex gap-2">
            <button @click="editGroup(group)" class="text-xs text-indigo-600 hover:text-indigo-700">Edit</button>
            <button @click="confirmDelete(group)" class="text-xs text-red-500 hover:text-red-700">Delete</button>
          </div>
        </div>
        <p v-if="group.description" class="text-sm text-gray-500 mb-3">{{ group.description }}</p>
        <p class="text-sm text-gray-500">
          <span class="font-medium text-gray-700 dark:text-gray-300">{{ group.mesh_sites_count }}</span> sites
        </p>
        <p class="text-xs text-gray-400 mt-1">Group ID: <code>{{ group.id }}</code></p>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="showModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4">
      <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl w-full max-w-md p-6">
        <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-5">
          {{ editingGroup ? 'Edit Group' : 'New Group' }}
        </h3>

        <form @submit.prevent="submitGroup" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Name</label>
            <input v-model="groupForm.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm" />
            <p v-if="groupForm.errors.name" class="text-red-500 text-xs mt-1">{{ groupForm.errors.name }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">Description</label>
            <textarea v-model="groupForm.description" rows="3" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm resize-none" />
          </div>

          <div class="flex gap-3 pt-2">
            <button type="button" @click="closeModal" class="flex-1 px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">
              Cancel
            </button>
            <button type="submit" :disabled="groupForm.processing" class="flex-1 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white px-4 py-2 rounded-lg text-sm font-medium">
              {{ editingGroup ? 'Save' : 'Create' }}
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
const showModal = ref(false);
const editingGroup = ref(null);

const groupForm = useForm({ name: '', description: '' });

function editGroup(group) {
  editingGroup.value = group;
  groupForm.name = group.name;
  groupForm.description = group.description || '';
  showModal.value = true;
}

function closeModal() {
  showModal.value = false;
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
