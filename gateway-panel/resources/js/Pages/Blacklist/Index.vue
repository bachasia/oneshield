<template>
  <AppLayout title="Blacklist">

    <!-- Page Header -->
    <div class="mb-6">
      <h1 class="text-xl font-semibold text-gray-800">Blacklist</h1>
      <p class="text-sm text-gray-500 mt-0.5">Block known test-buyers from using OneShield payment methods</p>
    </div>

    <!-- System Blacklist Toggle -->
    <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4 flex items-center justify-between gap-4">
      <div>
        <p class="text-sm font-semibold text-gray-700">Use system default blacklist</p>
        <p class="text-xs text-gray-400 mt-0.5">Include globally managed entries maintained by OneShieldX</p>
      </div>
      <button
        type="button"
        role="switch"
        :aria-checked="useSystemBlacklist"
        @click="toggleSystem"
        :disabled="toggling"
        class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 disabled:opacity-50"
        :class="useSystemBlacklist ? 'bg-indigo-600' : 'bg-gray-200'"
      >
        <span
          class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
          :class="useSystemBlacklist ? 'translate-x-6' : 'translate-x-1'"
        />
      </button>
    </div>

    <form @submit.prevent="submit">

      <!-- Blacklist Protection Section -->
      <div class="bg-white rounded-xl border border-gray-200 p-5 mb-4">
        <div class="flex items-center gap-3 mb-4">
          <div class="w-9 h-9 rounded-lg bg-red-50 flex items-center justify-center shrink-0">
            <svg class="w-5 h-5 text-red-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/>
            </svg>
          </div>
          <div>
            <p class="text-sm font-semibold text-gray-700">Blacklist Protection</p>
            <p class="text-xs text-gray-400 mt-0.5">Action when a buyer's email or address is blacklisted — applies to all your shield sites</p>
          </div>
        </div>

        <!-- Action radios -->
        <div class="flex gap-6 mb-3">
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="radio" v-model="form.blacklist_action" value="hide" class="text-indigo-600" />
            <span class="text-sm text-gray-700">Hide payment methods</span>
          </label>
          <label class="flex items-center gap-2 cursor-pointer">
            <input type="radio" v-model="form.blacklist_action" value="trap" class="text-indigo-600" />
            <span class="text-sm text-gray-700">Route to trap shield</span>
          </label>
        </div>

        <!-- Trap shield dropdown -->
        <div v-if="form.blacklist_action === 'trap'">
          <label class="block text-xs font-medium text-gray-600 mb-1.5">Trap Shield Site</label>
          <select
            v-model="form.trap_shield_id"
            class="w-full max-w-sm px-3 py-2.5 border border-gray-300 rounded-lg text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none cursor-pointer"
          >
            <option :value="null">— Select a shield site —</option>
            <option v-for="s in shields" :key="s.id" :value="s.id">
              #{{ s.id }} — {{ s.name }}
            </option>
          </select>
          <p v-if="form.errors.trap_shield_id" class="text-xs text-red-500 mt-1">{{ form.errors.trap_shield_id }}</p>
          <p class="text-[11px] text-gray-400 mt-1.5">Blacklisted buyers will be routed to this shield site for payment processing.</p>
        </div>

        <p v-else class="text-xs text-gray-400">Blacklisted buyers will not see any OneShield payment methods at checkout.</p>
      </div>

      <!-- Customer Information -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 mb-4">
        <h2 class="text-sm font-semibold text-gray-700 mb-4">Customer information</h2>

        <div>
          <label class="block text-xs font-medium text-gray-600 mb-1.5">
            Blacklist emails
            <span class="text-gray-400 font-normal ml-1">— one per line</span>
          </label>
          <textarea
            v-model="form.emails"
            rows="8"
            placeholder="test@example.com&#10;fraud@domain.com"
            class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y"
          />
        </div>
      </div>

      <!-- Customer Address -->
      <div class="bg-white rounded-xl border border-gray-200 p-6 mb-6">
        <h2 class="text-sm font-semibold text-gray-700 mb-4">Customer Address</h2>

        <div class="space-y-5">
          <!-- Cities -->
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1.5">
              Blacklist cities
              <span class="text-gray-400 font-normal ml-1">— one per line</span>
            </label>
            <textarea
              v-model="form.cities"
              rows="4"
              placeholder="new york&#10;los angeles"
              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y"
            />
          </div>

          <!-- States -->
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1.5">
              Blacklist states
              <span class="text-gray-400 font-normal ml-1">— one per line (abbreviation or full name)</span>
            </label>
            <textarea
              v-model="form.states"
              rows="4"
              placeholder="ca&#10;ny&#10;texas"
              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y"
            />
          </div>

          <!-- Zipcodes -->
          <div>
            <label class="block text-xs font-medium text-gray-600 mb-1.5">
              Blacklist zipcodes
              <span class="text-gray-400 font-normal ml-1">— one per line</span>
            </label>
            <textarea
              v-model="form.zipcodes"
              rows="4"
              placeholder="90210&#10;10001"
              class="w-full px-3 py-2.5 border border-gray-300 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-y"
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
          Save Blacklist
        </button>
      </div>

    </form>

  </AppLayout>
</template>

<script setup>
import { ref } from 'vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import axios from 'axios';

const props = defineProps({
  emails:               { type: String,  default: '' },
  cities:               { type: String,  default: '' },
  states:               { type: String,  default: '' },
  zipcodes:             { type: String,  default: '' },
  use_system_blacklist: { type: Boolean, default: true },
  blacklist_action:     { type: String,  default: 'hide' },
  trap_shield_id:       { type: Number,  default: null },
  shields:              { type: Array,   default: () => [] },
});

const form = useForm({
  emails:           props.emails,
  cities:           props.cities,
  states:           props.states,
  zipcodes:         props.zipcodes,
  blacklist_action: props.blacklist_action,
  trap_shield_id:   props.trap_shield_id,
});

const useSystemBlacklist = ref(props.use_system_blacklist);
const toggling = ref(false);

async function toggleSystem() {
  toggling.value = true;
  const newValue = !useSystemBlacklist.value;
  try {
    await axios.patch('/blacklist/toggle-system', { use_system_blacklist: newValue });
    useSystemBlacklist.value = newValue;
  } catch {
    // revert on failure — no change
  } finally {
    toggling.value = false;
  }
}

function submit() {
  form.post('/blacklist/save');
}
</script>
