<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-6">
    <div class="max-w-md w-full text-center">

      <!-- Shield icon -->
      <div class="flex justify-center mb-6">
        <div class="w-16 h-16 rounded-2xl flex items-center justify-center shadow-lg" :class="iconBgClass">
          <svg class="w-9 h-9" :class="iconClass" viewBox="0 0 24 24" fill="currentColor">
            <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4z"/>
          </svg>
        </div>
      </div>

      <!-- Message -->
      <h1 class="text-2xl font-bold text-gray-900 mb-2">{{ title }}</h1>
      <p class="text-gray-500 mb-8">{{ message }}</p>

      <!-- Contact admin -->
      <div class="bg-white border border-gray-200 rounded-2xl p-5 text-left mb-6">
        <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">What to do</p>
        <ul class="space-y-2 text-sm text-gray-600">
          <li class="flex items-start gap-2">
            <svg class="w-4 h-4 text-indigo-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75"/>
            </svg>
            Contact your account administrator to renew or upgrade your subscription.
          </li>
          <li v-if="status === 'suspended'" class="flex items-start gap-2">
            <svg class="w-4 h-4 text-indigo-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/>
            </svg>
            Your account has been suspended. Please reach out to support for assistance.
          </li>
        </ul>
      </div>

      <!-- Logout -->
      <Link
        href="/logout"
        method="post"
        as="button"
        class="text-sm text-gray-400 hover:text-gray-600 transition-colors"
      >
        Sign out
      </Link>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
  status:  { type: String, default: 'expired' },
  message: { type: String, default: 'Your subscription has expired.' },
});

const title = computed(() => {
  if (props.status === 'suspended') return 'Account Suspended';
  if (props.status === 'expired')   return 'Subscription Expired';
  return 'Subscription Required';
});

const iconBgClass = computed(() => {
  if (props.status === 'suspended') return 'bg-red-100';
  if (props.status === 'expired')   return 'bg-amber-100';
  return 'bg-gray-100';
});

const iconClass = computed(() => {
  if (props.status === 'suspended') return 'text-red-500';
  if (props.status === 'expired')   return 'text-amber-500';
  return 'text-gray-400';
});
</script>
