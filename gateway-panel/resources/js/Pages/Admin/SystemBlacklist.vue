<template>
  <AdminLayout title="System Blacklist">

    <!-- Page Header -->
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-white">System Default Blacklist</h1>
      <p class="text-sm text-slate-400 mt-0.5">Global entries applied to all tenants who have opted in</p>
    </div>

    <form @submit.prevent="submit">

      <!-- Customer Information -->
      <div class="bg-slate-900 rounded-xl border border-slate-800 p-6 mb-4">
        <h2 class="text-sm font-semibold text-slate-300 mb-4">Customer information</h2>

        <div>
          <label class="block text-xs font-medium text-slate-400 mb-1.5">
            Blacklist emails
            <span class="text-slate-500 font-normal ml-1">— one per line</span>
          </label>
          <textarea
            v-model="form.emails"
            rows="8"
            placeholder="test@example.com&#10;fraud@domain.com"
            class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-sm font-mono text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y"
          />
        </div>
      </div>

      <!-- Customer Address -->
      <div class="bg-slate-900 rounded-xl border border-slate-800 p-6 mb-6">
        <h2 class="text-sm font-semibold text-slate-300 mb-4">Customer Address</h2>

        <div class="space-y-5">
          <!-- Cities -->
          <div>
            <label class="block text-xs font-medium text-slate-400 mb-1.5">
              Blacklist cities
              <span class="text-slate-500 font-normal ml-1">— one per line</span>
            </label>
            <textarea
              v-model="form.cities"
              rows="4"
              placeholder="new york&#10;los angeles"
              class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-sm font-mono text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y"
            />
          </div>

          <!-- States -->
          <div>
            <label class="block text-xs font-medium text-slate-400 mb-1.5">
              Blacklist states
              <span class="text-slate-500 font-normal ml-1">— one per line (abbreviation or full name)</span>
            </label>
            <textarea
              v-model="form.states"
              rows="4"
              placeholder="ca&#10;ny&#10;texas"
              class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-sm font-mono text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y"
            />
          </div>

          <!-- Zipcodes -->
          <div>
            <label class="block text-xs font-medium text-slate-400 mb-1.5">
              Blacklist zipcodes
              <span class="text-slate-500 font-normal ml-1">— one per line</span>
            </label>
            <textarea
              v-model="form.zipcodes"
              rows="4"
              placeholder="90210&#10;10001"
              class="w-full px-3 py-2.5 bg-slate-800 border border-slate-700 rounded-lg text-sm font-mono text-slate-200 placeholder-slate-600 focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y"
            />
          </div>
        </div>
      </div>

      <!-- Save button -->
      <div class="flex justify-end">
        <button
          type="submit"
          :disabled="form.processing"
          class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-50 text-white px-5 py-2.5 rounded-lg text-sm font-medium transition-colors cursor-pointer"
        >
          <svg v-if="form.processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
          </svg>
          <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
          </svg>
          Save System Blacklist
        </button>
      </div>

    </form>

  </AdminLayout>
</template>

<script setup>
import AdminLayout from '@/Layouts/AdminLayout.vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
  emails:   { type: String, default: '' },
  cities:   { type: String, default: '' },
  states:   { type: String, default: '' },
  zipcodes: { type: String, default: '' },
});

const form = useForm({
  emails:   props.emails,
  cities:   props.cities,
  states:   props.states,
  zipcodes: props.zipcodes,
});

function submit() {
  form.post('/admin/system-blacklist/save');
}
</script>
