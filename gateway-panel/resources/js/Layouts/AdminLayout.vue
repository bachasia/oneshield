<template>
  <div class="min-h-screen bg-slate-950 flex">

    <!-- ── Sidebar ─────────────────────────────────────────────── -->
    <aside class="w-64 bg-slate-900 border-r border-slate-800 flex flex-col shrink-0">

      <!-- Brand -->
      <div class="h-16 flex items-center gap-3 px-5 border-b border-slate-800">
        <div class="w-8 h-8 bg-indigo-500 rounded-lg flex items-center justify-center shadow-lg">
          <svg class="w-5 h-5 text-white" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
          </svg>
        </div>
        <div>
          <span class="text-sm font-bold text-white leading-none">OneShield</span>
          <p class="text-[10px] text-slate-400 leading-none mt-0.5">Super Admin</p>
        </div>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 px-3 py-4 space-y-0.5">
        <p class="px-3 pt-1 pb-2 text-[10px] font-semibold text-slate-500 uppercase tracking-wider">Management</p>
        <Link
          v-for="item in navigation"
          :key="item.name"
          :href="item.href"
          class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-all group"
          :class="isActive(item.href)
            ? 'bg-indigo-600 text-white shadow-sm'
            : 'text-slate-400 hover:bg-slate-800 hover:text-white'"
        >
          <span
            class="w-5 h-5 flex-shrink-0 transition-colors"
            :class="isActive(item.href) ? 'text-white' : 'text-slate-500 group-hover:text-slate-300'"
            v-html="item.icon"
          />
          <span>{{ item.name }}</span>
          <span v-if="isActive(item.href)" class="ml-auto w-1.5 h-1.5 rounded-full bg-white/60" />
        </Link>
      </nav>

      <!-- Impersonation banner -->
      <div v-if="impersonating" class="mx-3 mb-3 p-3 bg-amber-500/10 border border-amber-500/30 rounded-xl">
        <p class="text-[10px] font-semibold text-amber-400 uppercase tracking-wide mb-1.5">Impersonating tenant</p>
        <Link
          href="/admin/impersonate"
          method="delete"
          as="button"
          class="w-full text-xs bg-amber-500 hover:bg-amber-600 text-white font-medium px-2.5 py-1.5 rounded-lg transition-colors text-center block"
        >
          Stop &amp; Return to Admin
        </Link>
      </div>

      <!-- User profile footer -->
      <div class="px-3 pb-4 flex items-center gap-2.5 border-t border-slate-800 pt-3">
        <div class="w-8 h-8 rounded-full bg-indigo-500/20 flex items-center justify-center flex-shrink-0 border border-indigo-500/30">
          <span class="text-xs font-bold text-indigo-400">{{ userInitial }}</span>
        </div>
        <div class="flex-1 min-w-0">
          <p class="text-xs font-semibold text-white truncate">{{ auth.user.name }}</p>
          <p class="text-[10px] text-slate-500 truncate">{{ auth.user.email }}</p>
        </div>
        <Link
          href="/logout"
          method="post"
          as="button"
          title="Logout"
          class="text-slate-500 hover:text-red-400 transition-colors"
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
      <header class="h-14 bg-slate-900 border-b border-slate-800 flex items-center justify-between px-6 shrink-0">
        <div class="flex items-center gap-2 text-sm">
          <span class="text-slate-500">Admin</span>
          <svg class="w-3.5 h-3.5 text-slate-700" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/>
          </svg>
          <span class="font-semibold text-white">{{ title }}</span>
        </div>

        <div class="flex items-center gap-3">
          <span class="text-[10px] font-semibold px-2 py-0.5 rounded-full bg-indigo-500/20 text-indigo-400 border border-indigo-500/30 uppercase tracking-wide">
            Super Admin
          </span>
          <div class="flex items-center gap-2">
            <div class="w-7 h-7 rounded-full bg-indigo-500/20 flex items-center justify-center border border-indigo-500/30">
              <span class="text-xs font-bold text-indigo-400">{{ userInitial }}</span>
            </div>
            <span class="text-sm text-slate-300 font-medium">{{ auth.user.name }}</span>
          </div>
        </div>
      </header>

      <!-- Flash Messages -->
      <transition name="flash">
        <div v-if="flash.success || flash.error" class="px-6 pt-4">
          <div
            v-if="flash.success"
            class="flex items-center gap-3 bg-green-500/10 border border-green-500/30 text-green-400 px-4 py-3 rounded-xl text-sm"
          >
            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
            </svg>
            {{ flash.success }}
          </div>
          <div
            v-if="flash.error"
            class="flex items-center gap-3 bg-red-500/10 border border-red-500/30 text-red-400 px-4 py-3 rounded-xl text-sm"
          >
            <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
            </svg>
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
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const page        = usePage();
const auth        = computed(() => page.props.auth);
const flash       = computed(() => page.props.flash);
const impersonating = computed(() => page.props.impersonating);

defineProps({ title: { type: String, default: 'Dashboard' } });

const userInitial = computed(() => auth.value?.user?.name?.[0]?.toUpperCase() ?? '?');

const icons = {
  dashboard: `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"/></svg>`,
  tenants:   `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>`,
};

const navigation = [
  { name: 'Dashboard', href: '/admin',         icon: icons.dashboard },
  { name: 'Tenants',   href: '/admin/tenants', icon: icons.tenants },
];

const isActive = (href) => {
  if (href === '/admin') return page.url === '/admin';
  return page.url.startsWith(href);
};
</script>

<style scoped>
.flash-enter-active, .flash-leave-active { transition: opacity 0.3s, transform 0.3s; }
.flash-enter-from, .flash-leave-to { opacity: 0; transform: translateY(-4px); }
</style>
