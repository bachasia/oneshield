<template>
  <AppLayout title="Settings">
    <div class="max-w-2xl space-y-6">

      <!-- Token Secret -->
      <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-900 mb-1">Token Secret</h2>
        <p class="text-sm text-gray-500 mb-4">Used to authenticate API requests from your WordPress plugins. Keep this secret.</p>

        <div class="flex items-center gap-3 mb-4">
          <code class="flex-1 bg-gray-50 border border-gray-200 px-3 py-2 rounded-lg text-sm font-mono break-all text-gray-700">
            {{ showToken ? token_secret : '••••••••••••••••••••••••••••••••' }}
          </code>
          <button @click="showToken = !showToken" class="text-gray-400 hover:text-gray-600 p-1 transition-colors">
            <svg v-if="!showToken" xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
            <svg v-else xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
          </button>
          <button @click="copyToken" class="text-gray-400 hover:text-indigo-600 p-1 transition-colors">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" /></svg>
          </button>
        </div>

        <form @submit.prevent="regenerate">
          <button
            type="submit"
            :disabled="regenForm.processing"
            class="px-4 py-2 bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 rounded-lg text-sm font-medium disabled:opacity-50 transition-colors"
            onclick="return confirm('Regenerate token? All plugins will need to be updated with the new token.')"
          >
            Regenerate Token
          </button>
        </form>
      </div>

      <!-- Webhook URLs -->
      <div class="bg-white rounded-2xl border border-gray-200 p-6">
        <h2 class="text-base font-semibold text-gray-900 mb-1">Webhook URLs</h2>
        <p class="text-sm text-gray-500 mb-4">Configure these URLs in your PayPal/Stripe dashboard. Replace <code class="bg-gray-100 px-1 rounded text-xs font-mono">{site_id}</code> with the actual site ID.</p>

        <div class="space-y-3">
          <div v-for="(url, gateway) in webhook_urls" :key="gateway">
            <label class="block text-xs font-medium text-gray-500 uppercase tracking-wide mb-1">{{ gateway }}</label>
            <div class="flex items-center gap-2">
              <code class="flex-1 bg-gray-50 border border-gray-200 px-3 py-2 rounded-lg text-xs font-mono break-all text-gray-600">{{ url }}</code>
              <button @click="copyText(url)" class="text-gray-400 hover:text-indigo-600 transition-colors">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" /></svg>
              </button>
            </div>
          </div>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
  token_secret:   String,
  gateway_tokens: Array,
  webhook_urls:   Object,
});

const showToken  = ref(false);
const regenForm  = useForm({});

function regenerate() {
  regenForm.post('/settings/regenerate-token');
}

function copyToken() {
  navigator.clipboard.writeText(props.token_secret);
}

function copyText(text) {
  navigator.clipboard.writeText(text);
}
</script>
