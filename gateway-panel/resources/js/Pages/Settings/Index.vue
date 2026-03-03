<template>
  <AppLayout title="Settings">
    <div class="max-w-2xl space-y-6">

      <!-- Token Secret -->
      <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <!-- Section header -->
        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
          <div class="w-9 h-9 rounded-xl bg-indigo-50 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/>
            </svg>
          </div>
          <div>
            <h2 class="text-sm font-semibold text-gray-900 leading-none">Token Secret</h2>
            <p class="text-xs text-gray-500 mt-0.5">Used to authenticate API requests from your WordPress plugins</p>
          </div>
        </div>

        <div class="px-6 py-5">
          <!-- Token display -->
          <div class="flex items-center gap-2 mb-4">
            <code class="flex-1 bg-gray-50 border border-gray-200 px-3 py-2.5 rounded-lg text-sm font-mono break-all text-gray-700 leading-relaxed">
              {{ showToken ? token_secret : '••••••••••••••••••••••••••••••••' }}
            </code>
            <div class="flex flex-col gap-1">
              <button
                @click="showToken = !showToken"
                class="w-8 h-8 rounded-lg border border-gray-200 flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:border-indigo-200 hover:bg-indigo-50 transition-colors"
                :title="showToken ? 'Hide token' : 'Show token'"
              >
                <svg v-if="!showToken" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>
              </button>
              <button
                @click="copyToken"
                class="w-8 h-8 rounded-lg border border-gray-200 flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:border-indigo-200 hover:bg-indigo-50 transition-colors"
                title="Copy token"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" /></svg>
              </button>
            </div>
          </div>

          <!-- Danger zone -->
          <div class="flex items-start gap-3 p-4 bg-red-50 border border-red-100 rounded-xl">
            <svg class="w-5 h-5 text-red-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
            <div class="flex-1">
              <p class="text-sm font-medium text-red-800">Regenerate Token</p>
              <p class="text-xs text-red-600 mt-0.5">All plugins will need to be updated with the new token. This cannot be undone.</p>
            </div>
            <form @submit.prevent="regenerate">
              <button
                type="submit"
                :disabled="regenForm.processing"
                class="px-3 py-1.5 bg-white hover:bg-red-600 hover:text-white text-red-700 border border-red-300 hover:border-red-600 rounded-lg text-sm font-medium disabled:opacity-50 transition-colors whitespace-nowrap"
                onclick="return confirm('Regenerate token? All plugins will need to be updated with the new token.')"
              >
                {{ regenForm.processing ? 'Regenerating…' : 'Regenerate' }}
              </button>
            </form>
          </div>
        </div>
      </div>

      <!-- Webhook URLs -->
      <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <!-- Section header -->
        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
          <div class="w-9 h-9 rounded-xl bg-violet-50 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-violet-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/>
            </svg>
          </div>
          <div>
            <h2 class="text-sm font-semibold text-gray-900 leading-none">Webhook URLs</h2>
            <p class="text-xs text-gray-500 mt-0.5">
              Configure in your PayPal/Stripe dashboard. Replace
              <code class="bg-gray-100 px-1 rounded font-mono">{site_id}</code>
              with the actual site ID.
            </p>
          </div>
        </div>

        <div class="px-6 py-5 space-y-4">
          <div v-for="(url, gateway) in webhook_urls" :key="gateway">
            <!-- Gateway label with icon -->
            <div class="flex items-center gap-2 mb-1.5">
              <!-- PayPal icon -->
              <svg v-if="gateway === 'paypal'" class="w-4 h-4 text-blue-600" viewBox="0 0 24 24" fill="currentColor">
                <path d="M7.076 21.337H2.47a.641.641 0 01-.633-.74L4.944 1.355a.642.642 0 01.632-.546h8.012c2.98 0 5.094 1.007 5.882 3.048.345.894.42 1.82.224 2.784l-.01.048v.413l.244.138a3.485 3.485 0 011.502 1.74c.308.778.37 1.67.18 2.65-.221 1.142-.65 2.135-1.274 2.948-.576.757-1.305 1.337-2.167 1.726-.832.376-1.797.565-2.87.565h-.686a1.922 1.922 0 00-1.898 1.622l-.153.811-.57 3.614-.026.135a.642.642 0 01-.633.546H7.076zm9.97-14.67c-.022.145-.048.293-.077.447-.983 5.05-4.349 6.797-8.648 6.797H6.19a1.06 1.06 0 00-1.048.896L3.98 22h3.097l.777-4.915h2.473c4.302 0 7.67-1.747 8.654-6.797.4-2.048-.071-3.554-.935-4.621z"/>
              </svg>
              <!-- Stripe icon -->
              <svg v-else-if="gateway === 'stripe'" class="w-4 h-4 text-violet-600" viewBox="0 0 24 24" fill="currentColor">
                <path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-6.99-2.109l-.9 5.555C5.175 22.99 8.385 24 11.714 24c2.641 0 4.843-.624 6.328-1.813 1.664-1.305 2.525-3.236 2.525-5.732 0-4.128-2.524-5.851-6.591-7.305z"/>
              </svg>
              <!-- Generic icon -->
              <svg v-else class="w-4 h-4 text-gray-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/>
              </svg>
              <label class="text-xs font-semibold text-gray-600 uppercase tracking-wide">{{ gateway }}</label>
            </div>
            <div class="flex items-center gap-2">
              <code class="flex-1 bg-gray-50 border border-gray-200 px-3 py-2 rounded-lg text-xs font-mono break-all text-gray-600">{{ url }}</code>
              <button
                @click="copyText(url)"
                class="w-8 h-8 rounded-lg border border-gray-200 flex items-center justify-center text-gray-400 hover:text-indigo-600 hover:border-indigo-200 hover:bg-indigo-50 transition-colors flex-shrink-0"
                title="Copy URL"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" /></svg>
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

const showToken = ref(false);
const regenForm = useForm({});

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
