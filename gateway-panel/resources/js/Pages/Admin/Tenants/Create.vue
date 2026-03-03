<template>
  <AdminLayout title="New Tenant">

    <div class="max-w-2xl">

      <!-- Header -->
      <div class="flex items-center gap-3 mb-6">
        <Link href="/admin/tenants" class="text-slate-500 hover:text-white transition-colors">
          <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/>
          </svg>
        </Link>
        <div>
          <h1 class="text-xl font-bold text-white">New Tenant</h1>
          <p class="text-sm text-slate-500">Create a new customer account with a subscription</p>
        </div>
      </div>

      <form @submit.prevent="submit" class="space-y-5">

        <!-- Account Details -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
          <h2 class="text-sm font-semibold text-white mb-4">Account Details</h2>

          <div>
            <label class="block text-xs font-semibold text-slate-400 mb-1.5">Full Name</label>
            <input
              v-model="form.name"
              type="text"
              placeholder="Acme Corp"
              class="w-full bg-slate-800 border text-white placeholder-slate-500 text-sm px-4 py-2.5 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
              :class="errors.name ? 'border-red-500' : 'border-slate-700'"
            />
            <p v-if="errors.name" class="text-xs text-red-400 mt-1">{{ errors.name }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-400 mb-1.5">Email Address</label>
            <input
              v-model="form.email"
              type="email"
              placeholder="admin@acme.com"
              class="w-full bg-slate-800 border text-white placeholder-slate-500 text-sm px-4 py-2.5 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
              :class="errors.email ? 'border-red-500' : 'border-slate-700'"
            />
            <p v-if="errors.email" class="text-xs text-red-400 mt-1">{{ errors.email }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-400 mb-1.5">Password</label>
            <input
              v-model="form.password"
              type="password"
              placeholder="Min 8 characters"
              class="w-full bg-slate-800 border text-white placeholder-slate-500 text-sm px-4 py-2.5 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
              :class="errors.password ? 'border-red-500' : 'border-slate-700'"
            />
            <p v-if="errors.password" class="text-xs text-red-400 mt-1">{{ errors.password }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-400 mb-1.5">
              Tenant ID
              <span class="text-slate-600 font-normal ml-1">(subdomain slug)</span>
            </label>
            <div class="flex items-center bg-slate-800 border rounded-xl overflow-hidden transition-all" :class="errors.tenant_id ? 'border-red-500' : 'border-slate-700'">
              <input
                v-model="form.tenant_id"
                type="text"
                placeholder="acme"
                class="flex-1 bg-transparent text-white placeholder-slate-500 text-sm px-4 py-2.5 focus:outline-none"
              />
              <span class="text-slate-500 text-xs px-4 border-l border-slate-700 py-2.5 shrink-0">.oneshieldx.com</span>
            </div>
            <p v-if="errors.tenant_id" class="text-xs text-red-400 mt-1">{{ errors.tenant_id }}</p>
            <p v-else class="text-[11px] text-slate-600 mt-1">Only letters, numbers, hyphens, underscores. Cannot be "admin", "www", "api", "mail".</p>
          </div>
        </div>

        <!-- Subscription -->
        <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
          <h2 class="text-sm font-semibold text-white mb-4">Subscription</h2>

          <div>
            <label class="block text-xs font-semibold text-slate-400 mb-2">Plan</label>
            <div class="grid grid-cols-2 gap-2">
              <button
                v-for="plan in plans"
                :key="plan.id"
                type="button"
                @click="form.plan_id = plan.id"
                class="flex items-center gap-3 p-3 rounded-xl border text-left transition-all"
                :class="form.plan_id === plan.id
                  ? 'border-indigo-500 bg-indigo-500/10'
                  : 'border-slate-700 bg-slate-800 hover:border-slate-600'"
              >
                <div
                  class="w-2.5 h-2.5 rounded-full shrink-0 border-2 transition-colors"
                  :class="form.plan_id === plan.id ? 'border-indigo-400 bg-indigo-400' : 'border-slate-600'"
                />
                <div>
                  <p class="text-xs font-semibold text-white">{{ plan.label }}</p>
                  <p class="text-[10px] text-slate-500">
                    {{ plan.price_usd ? '$' + plan.price_usd + '/mo' : 'Free' }}
                    &bull;
                    {{ plan.max_shield_sites >= 999 ? 'Unlimited sites' : plan.max_shield_sites + ' site' + (plan.max_shield_sites > 1 ? 's' : '') }}
                  </p>
                </div>
              </button>
            </div>
            <p v-if="errors.plan_id" class="text-xs text-red-400 mt-1">{{ errors.plan_id }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-400 mb-1.5">
              Expires At
              <span class="text-slate-600 font-normal ml-1">(optional — leave blank for no expiry)</span>
            </label>
            <input
              v-model="form.expires_at"
              type="date"
              class="w-full bg-slate-800 border border-slate-700 text-white text-sm px-4 py-2.5 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all"
            />
            <p v-if="errors.expires_at" class="text-xs text-red-400 mt-1">{{ errors.expires_at }}</p>
          </div>

          <div>
            <label class="block text-xs font-semibold text-slate-400 mb-1.5">
              Admin Notes
              <span class="text-slate-600 font-normal ml-1">(optional)</span>
            </label>
            <textarea
              v-model="form.notes"
              rows="2"
              placeholder="Internal notes about this tenant..."
              class="w-full bg-slate-800 border border-slate-700 text-white placeholder-slate-500 text-sm px-4 py-2.5 rounded-xl focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all resize-none"
            />
            <p v-if="errors.notes" class="text-xs text-red-400 mt-1">{{ errors.notes }}</p>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex items-center gap-3">
          <button
            type="submit"
            :disabled="processing"
            class="flex items-center gap-2 bg-indigo-600 hover:bg-indigo-700 disabled:opacity-60 text-white text-sm font-semibold px-6 py-2.5 rounded-xl transition-colors shadow-sm"
          >
            <svg v-if="processing" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            {{ processing ? 'Creating...' : 'Create Tenant' }}
          </button>
          <Link href="/admin/tenants" class="text-sm text-slate-500 hover:text-white transition-colors">
            Cancel
          </Link>
        </div>

      </form>
    </div>

  </AdminLayout>
</template>

<script setup>
import { ref, reactive } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import AdminLayout from '@/Layouts/AdminLayout.vue';

const props = defineProps({
  plans: { type: Array, default: () => [] },
});

const page       = usePage();
const errors     = ref({});
const processing = ref(false);

const form = reactive({
  name:       '',
  email:      '',
  password:   '',
  tenant_id:  '',
  plan_id:    props.plans[0]?.id ?? null,
  expires_at: '',
  notes:      '',
});

function submit() {
  processing.value = true;
  errors.value = {};

  router.post('/admin/tenants', form, {
    onError: (e) => { errors.value = e; },
    onFinish: () => { processing.value = false; },
  });
}
</script>
