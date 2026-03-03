<template>
  <AppLayout title="Settings">
    <!-- Copy toast -->
    <transition name="toast">
      <div
        v-if="copyToast"
        class="fixed bottom-6 right-6 z-50 flex items-center gap-2 bg-gray-900 text-white text-sm px-4 py-2.5 rounded-xl shadow-lg"
      >
        <svg class="w-4 h-4 text-green-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/>
        </svg>
        {{ copyToast }}
      </div>
    </transition>

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
              >
                {{ regenForm.processing ? 'Regenerating…' : 'Regenerate' }}
              </button>
            </form>
          </div>
        </div>
      </div>

      <!-- Gateway Tokens -->
      <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <div class="flex items-center justify-between px-6 py-4 border-b border-gray-100">
          <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-amber-50 flex items-center justify-center flex-shrink-0">
              <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"/>
              </svg>
            </div>
            <div>
              <h2 class="text-sm font-semibold text-gray-900 leading-none">Gateway Tokens</h2>
              <p class="text-xs text-gray-500 mt-0.5">API tokens used by Paygates plugin to authenticate with this panel</p>
            </div>
          </div>
          <!-- Create token button -->
          <button
            @click="showCreateToken = true"
            class="flex items-center gap-1.5 px-3 py-1.5 bg-amber-50 hover:bg-amber-100 text-amber-700 border border-amber-200 rounded-lg text-xs font-medium transition-colors"
          >
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
            </svg>
            Create Token
          </button>
        </div>

        <div class="px-6 py-5">

          <!-- New token reveal banner (shown once after creation) -->
          <div v-if="$page.props.flash?.new_token" class="mb-4 p-4 bg-green-50 border border-green-200 rounded-xl">
            <p class="text-sm font-semibold text-green-800 mb-2">Token created — copy it now, it will not be shown again</p>
            <div class="flex items-center gap-2">
              <code class="flex-1 bg-white border border-green-200 px-3 py-2 rounded-lg text-xs font-mono break-all text-gray-800">{{ $page.props.flash.new_token }}</code>
              <button
                @click="copyText($page.props.flash.new_token)"
                class="w-8 h-8 rounded-lg border border-green-200 flex items-center justify-center text-green-600 hover:bg-green-100 transition-colors flex-shrink-0"
                title="Copy token"
              >
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184" /></svg>
              </button>
            </div>
          </div>

          <!-- Empty state -->
          <div v-if="!gateway_tokens || gateway_tokens.length === 0" class="text-center py-6 text-gray-400 text-sm">
            No gateway tokens yet.
          </div>

          <!-- Token list -->
          <div v-else class="divide-y divide-gray-100">
            <div
              v-for="token in gateway_tokens"
              :key="token.id"
              class="flex items-center gap-4 py-3"
            >
              <!-- Status dot -->
              <span
                class="w-2 h-2 rounded-full flex-shrink-0"
                :class="token.is_active ? 'bg-green-500' : 'bg-gray-300'"
                :title="token.is_active ? 'Active' : 'Inactive'"
              />
              <!-- Name + metadata -->
              <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-900 truncate">{{ token.name }}</p>
                <p class="text-xs text-gray-400 mt-0.5">
                  Created {{ formatDate(token.created_at) }}
                  <span v-if="token.last_used_at"> · Last used {{ formatDate(token.last_used_at) }}</span>
                  <span v-else> · Never used</span>
                </p>
              </div>
              <!-- Status badge -->
              <span
                class="text-[10px] font-semibold px-2 py-1 rounded-full"
                :class="token.is_active ? 'bg-green-50 text-green-700' : 'bg-gray-100 text-gray-500'"
              >
                {{ token.is_active ? 'Active' : 'Inactive' }}
              </span>
              <!-- Revoke button (only active tokens can be revoked) -->
              <button
                v-if="token.is_active"
                @click="revokeToken(token)"
                class="text-[10px] font-semibold px-2 py-1 rounded-full bg-red-50 text-red-600 hover:bg-red-100 transition-colors"
              >
                Revoke
              </button>
            </div>
          </div>

          <p class="text-xs text-gray-400 mt-4 leading-relaxed">
            Tokens are used by the Paygates plugin to authenticate API requests. Revoke a token to immediately block access from that integration.
          </p>
        </div>
      </div>

      <!-- Create Token Modal -->
      <div v-if="showCreateToken" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-7 border border-gray-100">
          <h3 class="text-base font-semibold text-gray-900 mb-5">Create Gateway Token</h3>
          <form @submit.prevent="submitCreateToken" class="space-y-4">
            <div>
              <label class="block text-xs font-medium text-gray-700 mb-1">Token Name</label>
              <input
                v-model="createTokenForm.name"
                type="text"
                required
                placeholder="e.g. My WooCommerce Store"
                class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-amber-500 focus:outline-none"
                autofocus
              />
              <p class="text-xs text-gray-400 mt-1.5">Give it a descriptive name so you can identify it later.</p>
            </div>
            <div class="flex gap-3 pt-2">
              <button type="button" @click="showCreateToken = false" class="flex-1 px-4 py-2 border border-gray-300 rounded-xl text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
              <button type="submit" :disabled="createTokenForm.processing" class="flex-1 bg-amber-500 hover:bg-amber-600 text-white px-4 py-2 rounded-xl text-sm font-medium disabled:opacity-50">Create</button>
            </div>
          </form>
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

      <!-- Download Plugins -->
      <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
        <!-- Section header -->
        <div class="flex items-center gap-3 px-6 py-4 border-b border-gray-100">
          <div class="w-9 h-9 rounded-xl bg-emerald-50 flex items-center justify-center flex-shrink-0">
            <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
            </svg>
          </div>
          <div>
            <h2 class="text-sm font-semibold text-gray-900 leading-none">Download Plugins</h2>
            <p class="text-xs text-gray-500 mt-0.5">WordPress plugins to install on your sites</p>
          </div>
        </div>

        <div class="px-6 py-5 space-y-3">
          <div
            v-for="plugin in plugins"
            :key="plugin.key"
            class="flex items-center gap-4 p-4 rounded-xl border border-gray-100 hover:border-indigo-100 hover:bg-indigo-50/30 transition-colors group"
          >
            <!-- Plugin icon -->
            <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0"
              :class="plugin.key === 'connect' ? 'bg-indigo-100' : 'bg-violet-100'"
            >
              <!-- Connect icon: shield -->
              <svg v-if="plugin.key === 'connect'" class="w-5 h-5 text-indigo-600" viewBox="0 0 24 24" fill="currentColor">
                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
              </svg>
              <!-- Paygates icon: credit card -->
              <svg v-else class="w-5 h-5 text-violet-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/>
              </svg>
            </div>

            <!-- Plugin info -->
            <div class="flex-1 min-w-0">
              <div class="flex items-center gap-2">
                <p class="text-sm font-semibold text-gray-900">{{ plugin.name }}</p>
                <span class="inline-flex items-center px-1.5 py-0.5 rounded-md text-[10px] font-medium bg-gray-100 text-gray-600 font-mono">
                  v{{ plugin.version }}
                </span>
              </div>
              <p class="text-xs text-gray-500 mt-0.5">{{ plugin.description }}</p>
            </div>

            <!-- Download button -->
            <a
              :href="plugin.download_url"
              class="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium border transition-colors flex-shrink-0"
              :class="plugin.key === 'connect'
                ? 'text-indigo-700 border-indigo-200 bg-indigo-50 hover:bg-indigo-600 hover:text-white hover:border-indigo-600'
                : 'text-violet-700 border-violet-200 bg-violet-50 hover:bg-violet-600 hover:text-white hover:border-violet-600'"
            >
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/>
              </svg>
              Download
            </a>
          </div>
        </div>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
  token_secret:   String,
  gateway_tokens: Array,
  webhook_urls:   Object,
  plugins:        Array,
});

const showToken        = ref(false);
const regenForm        = useForm({});
const showCreateToken  = ref(false);
const createTokenForm  = useForm({ name: '' });
const copyToast        = ref('');
let   toastTimer       = null;

function regenerate() {
  if (!confirm('Regenerate token? All plugins will need to be updated with the new token.')) return;
  regenForm.post('/settings/regenerate-token');
}

function showCopyFeedback(label = 'Copied!') {
  copyToast.value = label;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { copyToast.value = ''; }, 2000);
}

function copyToken() {
  navigator.clipboard.writeText(props.token_secret).then(() => showCopyFeedback('Token copied!'));
}

function copyText(text) {
  navigator.clipboard.writeText(text).then(() => showCopyFeedback('Copied!'));
}

function submitCreateToken() {
  createTokenForm.post('/settings/tokens', {
    onSuccess: () => {
      showCreateToken.value = false;
      createTokenForm.reset();
    },
  });
}

function revokeToken(token) {
  if (!confirm(`Revoke token "${token.name}"? Any integration using this token will lose access immediately.`)) return;
  router.delete(`/settings/tokens/${token.id}`);
}

function formatDate(dateStr) {
  if (!dateStr) return '—';
  return new Date(dateStr).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
</script>

<style scoped>
.toast-enter-active, .toast-leave-active { transition: opacity 0.2s, transform 0.2s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(8px); }
</style>
