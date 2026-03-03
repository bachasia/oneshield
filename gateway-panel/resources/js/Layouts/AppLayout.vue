<template>
  <div class="min-h-screen bg-gray-50 flex">

    <!-- ── Sidebar ─────────────────────────────────────────────── -->
    <aside class="w-60 bg-white border-r border-gray-200 flex flex-col shrink-0">

      <!-- Brand -->
      <div class="h-16 flex items-center gap-2.5 px-5 border-b border-gray-200">
        <!-- Shield logo -->
        <div class="w-8 h-8 bg-indigo-600 rounded-lg flex items-center justify-center shadow-sm">
          <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
          </svg>
        </div>
        <div>
          <span class="text-base font-bold text-gray-900 leading-none">OneShield</span>
          <p class="text-[10px] text-gray-400 leading-none mt-0.5">Payment Gateway</p>
        </div>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 px-3 py-4 space-y-0.5">
        <p class="px-3 pt-1 pb-2 text-[10px] font-semibold text-gray-400 uppercase tracking-wider">Menu</p>
        <Link
          v-for="item in navigation"
          :key="item.name"
          :href="item.href"
          class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all group"
          :class="isActive(item.href)
            ? 'bg-indigo-600 text-white shadow-sm'
            : 'text-gray-600 hover:bg-gray-100 hover:text-gray-900'"
        >
          <span
            class="w-5 h-5 flex-shrink-0 transition-colors"
            :class="isActive(item.href) ? 'text-white' : 'text-gray-400 group-hover:text-gray-600'"
            v-html="item.icon"
          />
          <span>{{ item.name }}</span>
          <!-- Active indicator dot -->
          <span v-if="isActive(item.href)" class="ml-auto w-1.5 h-1.5 rounded-full bg-white/60" />
        </Link>
      </nav>

      <!-- Token Secret -->
      <div class="mx-3 mb-3 p-3 bg-gray-50 rounded-xl border border-gray-200">
        <div class="flex items-center gap-1.5 mb-1.5">
          <svg class="w-3.5 h-3.5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/>
          </svg>
          <p class="text-[10px] font-semibold text-gray-500 uppercase tracking-wide">Token Secret</p>
        </div>
        <div class="flex items-center gap-1.5">
          <code class="text-[10px] bg-white border border-gray-200 px-2 py-1 rounded flex-1 truncate font-mono text-gray-600">
            {{ showToken ? auth.user.token_secret : '••••••••••••••' }}
          </code>
          <button @click="showToken = !showToken" class="text-gray-400 hover:text-indigo-500 transition-colors p-0.5">
            <svg v-if="!showToken" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            <svg v-else class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.45 10.45 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.523 10.523 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88"/></svg>
          </button>
          <button @click="copyToken" class="text-gray-400 hover:text-indigo-500 transition-colors p-0.5">
            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.666 3.888A2.25 2.25 0 0013.5 2.25h-3c-1.03 0-1.9.693-2.166 1.638m7.332 0c.055.194.084.4.084.612v0a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v0c0-.212.03-.418.084-.612m7.332 0c.646.049 1.288.11 1.927.184 1.1.128 1.907 1.077 1.907 2.185V19.5a2.25 2.25 0 01-2.25 2.25H6.75A2.25 2.25 0 014.5 19.5V6.257c0-1.108.806-2.057 1.907-2.185a48.208 48.208 0 011.927-.184"/></svg>
          </button>
        </div>
      </div>

      <!-- User profile footer -->
      <div class="px-3 pb-4 flex items-center gap-2.5">
        <div class="w-8 h-8 rounded-full bg-indigo-100 flex items-center justify-center flex-shrink-0">
          <span class="text-xs font-bold text-indigo-600">{{ userInitial }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-xs font-semibold text-gray-800 truncate">{{ auth.user.name }}</p>
          <p class="text-[10px] text-gray-400 truncate">{{ auth.user.email }}</p>
        </div>
        <Link
          href="/logout"
          method="post"
          as="button"
          title="Logout"
          class="text-gray-400 hover:text-red-500 transition-colors"
        >
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75"/>
          </svg>
        </Link>
      </div>
    </aside>

    <!-- ── Main ───────────────────────────────────────────────── -->
    <div class="flex-1 flex flex-col min-w-0">

      <!-- Top bar -->
      <header class="h-14 bg-white border-b border-gray-200 flex items-center justify-between px-6 shrink-0">
        <!-- Page title + breadcrumb -->
        <div class="flex items-center gap-2 text-sm">
          <span class="text-gray-400">OneShield</span>
          <svg class="w-3.5 h-3.5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
          <span class="font-semibold text-gray-700">{{ title }}</span>
        </div>

        <!-- Right: docs link + notifications placeholder -->
        <div class="flex items-center gap-3">
          <a href="https://github.com/bachasia/oneshield" target="_blank" class="text-gray-400 hover:text-gray-600 transition-colors" title="Documentation">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9 5.25h.008v.008H12v-.008z"/></svg>
          </a>
          <div class="h-4 w-px bg-gray-200" />
          <div class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-full bg-indigo-100 flex items-center justify-center">
              <span class="text-xs font-bold text-indigo-600">{{ userInitial }}</span>
            </div>
            <span class="text-sm text-gray-700 font-medium">{{ auth.user.name }}</span>
          </div>
        </div>
      </header>

      <!-- Flash Messages -->
      <transition name="flash">
        <div v-if="flash.success || flash.error" class="px-6 pt-4">
          <div
            v-if="flash.success"
            class="flex items-center gap-3 bg-green-50 border border-green-200 text-green-800 px-4 py-3 rounded-xl text-sm"
          >
            <svg class="w-4 h-4 text-green-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            {{ flash.success }}
          </div>
          <div
            v-if="flash.error"
            class="flex items-center gap-3 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm"
          >
            <svg class="w-4 h-4 text-red-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
            {{ flash.error }}
          </div>
        </div>
      </transition>

      <!-- Page Content -->
      <main class="flex-1 p-6 overflow-auto">
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page  = usePage();
const auth  = computed(() => page.props.auth);
const flash = computed(() => page.props.flash);

defineProps({ title: { type: String, default: '' } });

const userInitial = computed(() => auth.value?.user?.name?.[0]?.toUpperCase() ?? '?');

// ── Nav icons (inline SVG strings for v-html) ──────────────────
const icons = {
  dashboard: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>`,
  sites:     `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"/></svg>`,
  groups:    `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 12.75V12A2.25 2.25 0 014.5 9.75h15A2.25 2.25 0 0121.75 12v.75m-8.69-6.44l-2.12-2.12a1.5 1.5 0 00-1.061-.44H4.5A2.25 2.25 0 002.25 6v12a2.25 2.25 0 002.25 2.25h15A2.25 2.25 0 0021.75 18V9a2.25 2.25 0 00-2.25-2.25h-5.379a1.5 1.5 0 01-1.06-.44z"/></svg>`,
  tx:        `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 8.25h19.5M2.25 9h19.5m-16.5 5.25h6m-6 2.25h3m-3.75 3h15a2.25 2.25 0 002.25-2.25V6.75A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25v10.5A2.25 2.25 0 004.5 19.5z"/></svg>`,
  settings:  `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>`,
};

const navigation = [
  { name: 'Dashboard',     href: '/dashboard',   icon: icons.dashboard },
  { name: 'Payment Sites', href: '/sites',        icon: icons.sites },
  { name: 'Groups',        href: '/groups',       icon: icons.groups },
  { name: 'Transactions',  href: '/transactions', icon: icons.tx },
  { name: 'Settings',      href: '/settings',     icon: icons.settings },
];

const isActive = (href) => page.url.startsWith(href);

const showToken = ref(false);
function copyToken() {
  navigator.clipboard.writeText(auth.value.user.token_secret);
}
</script>

<style scoped>
.flash-enter-active, .flash-leave-active { transition: opacity 0.3s, transform 0.3s; }
.flash-enter-from, .flash-leave-to { opacity: 0; transform: translateY(-4px); }
</style>
