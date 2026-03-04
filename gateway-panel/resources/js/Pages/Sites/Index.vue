<template>
  <AppLayout title="Shield Sites">

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

    <!-- Page Header -->
    <div class="mb-1">
      <h1 class="text-xl font-semibold text-gray-800">Shield Sites</h1>
      <p class="text-sm text-gray-500 mt-0.5">You can manage payment sites from here</p>
    </div>

    <!-- Stats + Action bar -->
    <div class="flex items-start justify-between mt-4 mb-5 gap-4">
      <!-- Gateway mode stats -->
      <div class="bg-white rounded-xl border border-gray-200 px-4 py-3 text-sm text-gray-600 leading-relaxed">
        <div>
          <span class="font-semibold text-gray-800">Live Mode:</span>
          {{ stats.live_paypal }} PayPal Activated
          <span class="mx-1 text-gray-400">|</span>
          {{ stats.live_stripe }} Stripe Activated
        </div>
        <div>
          <span class="font-semibold text-gray-800">Test Mode:</span>
          {{ stats.test_paypal }} PayPal Activated
          <span class="mx-1 text-gray-400">|</span>
          {{ stats.test_stripe }} Stripe Activated
        </div>
      </div>

      <!-- Right controls -->
      <div class="flex items-center gap-2">
        <button
          @click="addSite"
          class="flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition-colors"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Add Site
        </button>
        <button
          @click="checkAll"
          :disabled="checkingAll"
          class="flex items-center gap-1.5 border border-blue-500 text-blue-600 hover:bg-blue-50 px-3 py-2 rounded-lg text-sm font-medium transition-colors disabled:opacity-50"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
          Check all
        </button>
      </div>
    </div>

    <!-- Show active only toggle + group filter -->
    <div class="bg-white rounded-xl border border-gray-200 p-4 mb-5">
      <div class="flex items-center justify-between gap-4">
      <div class="flex items-center gap-3">
        <select
          v-model="filterGroup"
          @change="applyFilters"
          class="px-3 py-2 border border-gray-300 rounded-lg text-sm bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 min-w-[180px]"
        >
          <option value="">All Groups</option>
          <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
        </select>
      </div>
      <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
        <button
          @click="toggleActiveOnly"
          class="relative inline-flex h-6 w-10 items-center rounded-full transition-colors focus:outline-none"
          :class="showActiveOnly ? 'bg-indigo-600' : 'bg-gray-300'"
        >
          <span
            class="inline-block h-4 w-4 transform rounded-full bg-white shadow transition-transform"
            :class="showActiveOnly ? 'translate-x-5' : 'translate-x-1'"
          />
        </button>
        Show active only
      </label>
      </div>
    </div>

    <!-- Sites Table -->
    <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="bg-gray-50 border-b border-gray-200 text-left">
            <th class="px-3 py-3.5 w-8"></th>
            <th class="px-5 py-3.5 text-xs font-semibold text-gray-500 uppercase tracking-wide w-40">Site Name</th>
            <th class="px-5 py-3.5 text-xs font-semibold text-gray-500 uppercase tracking-wide">URL</th>
            <th class="px-5 py-3.5 text-xs font-semibold text-gray-500 uppercase tracking-wide w-40">Gross Received</th>
            <th class="px-5 py-3.5 text-xs font-semibold text-gray-500 uppercase tracking-wide w-44">Active Status</th>
            <th class="px-5 py-3.5 text-xs font-semibold text-gray-500 uppercase tracking-wide w-28">Groups</th>
            <th class="px-5 py-3.5 text-xs font-semibold text-gray-500 uppercase tracking-wide w-36">Mode</th>
            <th class="px-5 py-3.5 text-xs font-semibold text-gray-500 uppercase tracking-wide text-right"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="filteredSites.length === 0">
            <td colspan="8" class="px-5 py-14 text-center text-gray-400 text-sm">
              No shield sites found. Click "Add Site" to get started.
            </td>
          </tr>
          <tr
            v-for="(site, idx) in filteredSites"
            :key="site.id"
            draggable="true"
            @dragstart="onDragStart($event, site)"
            @dragover="onDragOver($event, idx)"
            @dragleave="onDragLeave"
            @drop="onDrop($event, idx)"
            @dragend="onDragEnd"
            class="hover:bg-gray-50/70 align-top transition-colors"
            :class="{
              'opacity-50': dragState.draggingId === site.id,
              'border-t-2 border-indigo-400': dragState.overIndex === idx && dragState.draggingId !== site.id,
            }"
          >
            <!-- Drag Handle -->
            <td class="px-3 py-4 cursor-grab active:cursor-grabbing">
              <svg class="w-4 h-4 text-gray-300 hover:text-gray-500 transition-colors" fill="currentColor" viewBox="0 0 20 20">
                <path d="M7 2a2 2 0 110 4 2 2 0 010-4zm6 0a2 2 0 110 4 2 2 0 010-4zM7 8a2 2 0 110 4 2 2 0 010-4zm6 0a2 2 0 110 4 2 2 0 010-4zM7 14a2 2 0 110 4 2 2 0 010-4zm6 0a2 2 0 110 4 2 2 0 010-4z"/>
              </svg>
            </td>

            <!-- Site Name -->
            <td class="px-5 py-4">
              <div class="font-semibold text-gray-900 leading-5">{{ site.name }}</div>
            </td>

            <!-- URL + heartbeat status -->
            <td class="px-5 py-4">
              <a :href="site.url" target="_blank" class="text-gray-700 hover:text-indigo-600 text-sm font-medium break-all">{{ site.url }}</a>
              <div class="mt-2">
                <span
                  class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium"
                  :class="heartbeatClass(site)"
                >
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
                  </svg>
                  {{ heartbeatLabel(site) }}
                </span>
              </div>
            </td>

            <!-- Gross Received -->
            <td class="px-5 py-4 text-sm">
              <div class="text-gray-700 font-medium">PayPal: <span class="text-gray-900">{{ formatMoney(site.gross_paypal) }}</span></div>
              <div class="text-gray-500 mt-1">Stripe: <span class="text-gray-700">{{ formatMoney(site.gross_stripe) }}</span></div>
            </td>

            <!-- Active Status per gateway -->
            <td class="px-5 py-4">
              <div class="flex flex-col gap-2">
                <div class="flex items-center gap-2 text-xs text-gray-600">
                  <!-- PayPal icon -->
                  <svg class="w-3.5 h-3.5 text-blue-600" viewBox="0 0 24 24" fill="currentColor"><path d="M7.076 21.337H2.47a.641.641 0 01-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106zm14.146-14.42a3.35 3.35 0 00-.607-.541c-.013.076-.026.175-.041.254-.93 4.778-4.005 7.201-9.138 7.201h-2.19a.563.563 0 00-.556.479l-1.187 7.527h-.506l-.24 1.516a.56.56 0 00.554.647h3.882c.46 0 .85-.334.922-.788.06-.26.76-4.852.816-5.09a.932.932 0 01.923-.788h.58c3.76 0 6.705-1.528 7.565-5.946.36-1.847.174-3.388-.777-4.471z"/></svg>
                  PayPal
                    <span v-if="site.paypal_enabled" class="bg-green-50 text-green-700 text-[10px] px-2 py-1 rounded-full flex items-center gap-1 font-semibold">
                      <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                      Activated
                    </span>
                    <span v-else class="bg-gray-100 text-gray-500 text-[10px] px-2 py-1 rounded-full font-semibold">Inactive</span>
                  </div>
                <div class="flex items-center gap-2 text-xs text-gray-600">
                  <!-- Stripe S -->
                  <span class="w-3.5 h-3.5 rounded-full bg-indigo-600 text-white text-[9px] font-bold flex items-center justify-center">S</span>
                  Stripe
                    <span v-if="site.stripe_enabled" class="bg-green-50 text-green-700 text-[10px] px-2 py-1 rounded-full flex items-center gap-1 font-semibold">
                      <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                      Activated
                    </span>
                    <span v-else class="bg-gray-100 text-gray-500 text-[10px] px-2 py-1 rounded-full font-semibold">Inactive</span>
                  </div>
                <div class="flex items-center gap-2 text-xs text-gray-400">
                  <!-- Airwallex A -->
                  <span class="w-3.5 h-3.5 rounded-full bg-rose-500 text-white text-[9px] font-bold flex items-center justify-center">A</span>
                  Airwallex
                </div>
              </div>
            </td>

            <!-- Groups -->
            <td class="px-5 py-4">
              <span
                v-if="site.group"
                class="inline-block bg-blue-50 text-blue-700 text-xs font-semibold px-2.5 py-1 rounded-full"
              >
                {{ site.group.name }}
              </span>
              <span v-else class="text-gray-400 text-xs">—</span>
            </td>

            <!-- Mode -->
            <td class="px-5 py-4">
              <div class="flex flex-col gap-2">
                <div class="flex items-center gap-2 text-xs text-gray-600">
                  <svg class="w-3.5 h-3.5 text-blue-600" viewBox="0 0 24 24" fill="currentColor"><path d="M7.076 21.337H2.47a.641.641 0 01-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106zm14.146-14.42a3.35 3.35 0 00-.607-.541c-.013.076-.026.175-.041.254-.93 4.778-4.005 7.201-9.138 7.201h-2.19a.563.563 0 00-.556.479l-1.187 7.527h-.506l-.24 1.516a.56.56 0 00.554.647h3.882c.46 0 .85-.334.922-.788.06-.26.76-4.852.816-5.09a.932.932 0 01.923-.788h.58c3.76 0 6.705-1.528 7.565-5.946.36-1.847.174-3.388-.777-4.471z"/></svg>
                  <span
                     class="text-[10px] font-semibold px-2 py-1 rounded-full"
                     :class="site.paypal_mode === 'live' ? 'bg-green-100 text-green-700' : 'bg-gray-800 text-white'"
                   >
                     {{ site.paypal_mode === 'live' ? 'Live' : 'Sandbox' }}
                   </span>
                 </div>
                <div class="flex items-center gap-2 text-xs text-gray-600">
                  <span class="w-3.5 h-3.5 rounded-full bg-indigo-600 text-white text-[9px] font-bold flex items-center justify-center">S</span>
                  <span
                     class="text-[10px] font-semibold px-2 py-1 rounded-full"
                     :class="site.stripe_mode === 'live' ? 'bg-green-100 text-green-700' : 'bg-gray-800 text-white'"
                    >
                     {{ site.stripe_mode === 'live' ? 'Live' : 'Test' }}
                    </span>
                  </div>
              </div>
            </td>

            <!-- Actions -->
            <td class="px-5 py-4">
              <div class="flex items-center gap-2 justify-end flex-wrap">
                <button
                  @click="openSettings(site)"
                   class="bg-indigo-700 hover:bg-indigo-800 text-white text-xs px-3.5 py-2 rounded-lg font-medium transition-colors"
                 >Settings</button>
                <button
                  @click="viewReports(site)"
                   class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs px-3.5 py-2 rounded-lg font-medium transition-colors"
                 >Reports</button>
                <button
                  @click="confirmDelete(site)"
                   class="bg-red-500 hover:bg-red-600 text-white text-xs px-3.5 py-2 rounded-lg font-medium transition-colors"
                 >Delete</button>
                <button
                  @click="checkSite(site)"
                   class="border border-blue-400 text-blue-600 hover:bg-blue-50 text-xs px-3.5 py-2 rounded-lg font-medium transition-colors flex items-center gap-1.5"
                 >
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                  Check
                </button>
                <!-- Drag-to-reorder: reserved for Phase 2 (sortable not yet implemented) -->
              </div>
            </td>

          </tr>
        </tbody>
      </table>

      <!-- Pagination -->
      <div v-if="sites.last_page > 1" class="px-4 py-3 border-t border-gray-200 flex gap-1">
        <Link
          v-for="link in sites.links"
          :key="link.label"
          :href="link.url || ''"
          v-html="link.label"
           class="px-3 py-1 rounded-lg text-sm"
          :class="link.active
            ? 'bg-indigo-600 text-white'
            : link.url ? 'text-gray-600 hover:bg-gray-100' : 'text-gray-300 cursor-not-allowed'"
        />
      </div>
    </div>

    <!-- =========================================================== -->
    <!-- Upgrade Plan Modal -->
    <!-- =========================================================== -->
    <div v-if="showUpgradeModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-sm p-7 border border-gray-100 text-center">
        <div class="w-14 h-14 rounded-2xl bg-amber-50 flex items-center justify-center mx-auto mb-4">
          <svg class="w-7 h-7 text-amber-500" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>
          </svg>
        </div>
        <h3 class="text-base font-semibold text-gray-900 mb-2">Shield Site Limit Reached</h3>
        <p class="text-sm text-gray-500 mb-1">
          Your <span class="font-semibold text-gray-700">{{ subscription?.plan?.label }}</span> plan allows
          <span class="font-semibold text-gray-700">{{ subscription?.sites_limit }}</span> shield site(s).
        </p>
        <p class="text-sm text-gray-500 mb-5">Contact your administrator to upgrade your plan.</p>
        <button
          @click="showUpgradeModal = false"
          class="w-full bg-indigo-600 hover:bg-indigo-700 text-white py-2.5 rounded-xl text-sm font-medium transition-colors"
        >Got it</button>
      </div>
    </div>

    <!-- =========================================================== -->
    <!-- Add Site Modal (simple) -->
    <!-- =========================================================== -->
    <div v-if="showAddModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-7 border border-gray-100">
         <h3 class="text-base font-semibold text-gray-900 mb-5">Add Shield Site</h3>
         <form @submit.prevent="submitAdd" class="space-y-4">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Site Name</label>
            <input v-model="addForm.name" type="text" required placeholder="My Shop" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Site URL</label>
            <input v-model="addForm.url" type="url" required placeholder="https://shield-site.com" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Group (optional)</label>
            <select v-model="addForm.group_id" class="w-full px-3 py-2 border border-gray-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
              <option :value="null">No Group</option>
              <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
            </select>
          </div>
           <div class="flex gap-3 pt-3">
            <button type="button" @click="showAddModal = false" class="flex-1 px-4 py-2 border border-gray-300 rounded-xl text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
            <button type="submit" :disabled="addForm.processing" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-xl text-sm font-medium disabled:opacity-50">Add Site</button>
          </div>
        </form>
      </div>
    </div>

    <!-- =========================================================== -->
    <!-- Settings Slide-over Panel (right side) -->
    <!-- =========================================================== -->
    <transition name="slideover">
      <div v-if="settingsSite" class="fixed inset-0 z-50 flex">
        <!-- Backdrop -->
        <div class="flex-1 bg-black/40" @click="closeSettings" />

        <!-- Panel -->
        <div class="w-full max-w-[1180px] bg-white shadow-xl flex flex-col overflow-hidden rounded-l-[32px] border-l border-gray-200">

          <!-- Panel Header -->
          <div class="flex items-center justify-between px-6 py-5 border-b border-gray-200 flex-shrink-0 bg-white/95 backdrop-blur">
            <div>
              <p class="text-[11px] font-semibold text-gray-400 uppercase tracking-[0.18em]">Shield Site Settings</p>
              <h2 class="text-lg font-semibold text-gray-900 mt-1">{{ settingsForm.name || settingsSite.name }}</h2>
              <p class="text-xs text-gray-500 mt-1 break-all">{{ settingsSite.url }}</p>
            </div>
            <div class="flex items-center gap-3">
              <button @click="closeSettings" class="w-9 h-9 rounded-xl text-gray-400 hover:text-gray-600 hover:bg-gray-100 flex items-center justify-center transition-colors">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
              </button>
              <button
                @click="saveSettings"
                :disabled="settingsForm.processing"
                class="bg-indigo-700 hover:bg-indigo-800 text-white text-sm px-5 py-2.5 rounded-xl font-medium disabled:opacity-50 transition-colors"
              >
                {{ settingsForm.processing ? 'Saving...' : 'Save Settings' }}
              </button>
            </div>
          </div>

          <!-- Panel Body (scrollable) -->
          <div class="flex-1 overflow-y-auto px-7 py-7 space-y-6 bg-gray-50/60">

            <div class="grid grid-cols-[minmax(0,1.1fr)_minmax(0,0.9fr)] gap-6">
              <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="flex items-center gap-3 mb-4">
                  <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center">
                    <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3.75h15a.75.75 0 01.75.75v11.25a.75.75 0 01-.75.75h-15a.75.75 0 01-.75-.75V4.5a.75.75 0 01.75-.75z"/></svg>
                  </div>
                  <div>
                    <h3 class="text-sm font-semibold text-gray-900">Site Details</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Basic identity and grouping</p>
                  </div>
                </div>

                <div class="grid grid-cols-2 gap-4">
                  <div class="col-span-2">
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Site Name</label>
                    <input v-model="settingsForm.name" type="text" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                  </div>
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1.5">Groups</label>
                    <select v-model="settingsForm.group_id" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                      <option :value="null">No Group</option>
                      <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                    </select>
                  </div>
                </div>
              </div>

              <div class="bg-white rounded-2xl border border-gray-200 p-5">
                <div class="flex items-center gap-3 mb-4">
                  <div class="w-10 h-10 rounded-xl bg-amber-50 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z"/></svg>
                  </div>
                  <div>
                    <h3 class="text-sm font-semibold text-gray-900">Authorize Key</h3>
                    <p class="text-xs text-gray-500 mt-0.5">Used by the Shield Site plugin</p>
                  </div>
                </div>

                <div class="space-y-3">
                  <input
                    :value="settingsSite.site_key"
                    readonly
                    class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 text-gray-600 font-mono"
                  />
                  <div class="flex items-center justify-between gap-3 rounded-xl bg-gray-50 border border-gray-200 px-3 py-2.5">
                    <div>
                      <p class="text-xs font-medium text-gray-700">Current heartbeat</p>
                      <p class="text-xs text-gray-500 mt-0.5">{{ heartbeatLabel(settingsSite) }}</p>
                    </div>
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium" :class="heartbeatClass(settingsSite)">
                      <span class="w-1.5 h-1.5 rounded-full bg-current opacity-70"></span>
                      Status
                    </span>
                  </div>
                  <p class="text-xs text-gray-400 leading-relaxed">
                    This key links the gateway panel and the shield site. If the site is recreated, update the key in the OneShield Connect menu on that shield site.
                  </p>
                </div>
              </div>
            </div>

            <div class="bg-white rounded-2xl border border-gray-200 overflow-hidden">
              <div class="border-b border-gray-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Payment Settings</h3>
                <p class="text-xs text-gray-500 mt-0.5">Configure credentials, mode, limits, and webhook endpoints for each gateway</p>
              </div>

              <div class="px-6 pt-5 pb-2 border-b border-gray-100 bg-white">
                <div class="flex gap-2 overflow-x-auto">
                  <button
                    v-for="tab in gatewayTabs"
                    :key="tab.key"
                    @click="activeGateway = tab.key"
                    class="min-w-[190px] flex items-center gap-3 px-4 py-3 rounded-xl text-left text-sm font-medium transition-colors border"
                    :class="activeGateway === tab.key
                      ? 'bg-gray-50 text-gray-900 border-gray-300'
                      : 'bg-white text-gray-500 border-gray-200 hover:bg-gray-50 hover:text-gray-800'"
                  >
                    <span class="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
                      :class="activeGateway === tab.key ? 'bg-white border border-gray-200' : 'bg-gray-50 border border-gray-200'">
                      <svg v-if="tab.key === 'paypal'" class="w-5 h-5 text-blue-500" viewBox="0 0 24 24" fill="currentColor"><path d="M7.076 21.337H2.47a.641.641 0 01-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106zm14.146-14.42a3.35 3.35 0 00-.607-.541c-.013.076-.026.175-.041.254-.93 4.778-4.005 7.201-9.138 7.201h-2.19a.563.563 0 00-.556.479l-1.187 7.527h-.506l-.24 1.516a.56.56 0 00.554.647h3.882c.46 0 .85-.334.922-.788.06-.26.76-4.852.816-5.09a.932.932 0 01.923-.788h.58c3.76 0 6.705-1.528 7.565-5.946.36-1.847.174-3.388-.777-4.471z"/></svg>
                      <svg v-else-if="tab.key === 'stripe'" class="w-5 h-5 text-indigo-500" viewBox="0 0 24 24" fill="currentColor"><path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-6.99-2.109l-.9 5.555C5.175 22.99 8.385 24 11.714 24c2.641 0 4.843-.624 6.328-1.813 1.664-1.305 2.525-3.236 2.525-5.732 0-4.128-2.524-5.851-6.591-7.305z"/></svg>
                      <svg v-else class="w-5 h-5 text-rose-500" viewBox="0 0 100 100" fill="currentColor"><path d="M50 5 L95 27.5 L95 72.5 L50 95 L5 72.5 L5 27.5 Z" opacity="0.2"/><path d="M50 20 L80 36 L80 64 L50 80 L20 64 L20 36 Z" opacity="0.4"/><text x="50" y="60" text-anchor="middle" font-size="32" font-weight="bold" fill="currentColor" opacity="0.9">A</text></svg>
                    </span>
                    <div>
                      <div>{{ tab.label }}</div>
                      <div class="text-[11px] font-normal text-gray-500">
                        {{ tab.key === 'airwallex' ? 'Phase 2' : 'Gateway config' }}
                      </div>
                    </div>
                  </button>
                </div>
              </div>

              <div class="p-6 bg-white">
                <template v-if="activeGateway === 'paypal' || activeGateway === 'stripe'">
                  <div class="grid grid-cols-[minmax(0,1fr)_minmax(320px,380px)] gap-8 items-start">
                    <div class="space-y-5 min-w-0">
                      <div class="flex items-center justify-between">
                        <div>
                          <h4 class="text-sm font-semibold text-gray-900">Gateway Credentials</h4>
                          <p class="text-xs text-gray-500 mt-0.5">Securely connect this shield site to {{ activeGateway === 'paypal' ? 'PayPal' : 'Stripe' }}</p>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold capitalize" :class="activeGateway === 'paypal' ? 'bg-blue-50 text-blue-700' : 'bg-indigo-50 text-indigo-700'">
                          <span class="w-1.5 h-1.5 rounded-full" :class="activeGateway === 'paypal' ? 'bg-blue-500' : 'bg-indigo-500'"></span>
                          {{ activeGateway }}
                        </span>
                      </div>

                      <div v-if="activeGateway === 'paypal'" class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                          <label class="block text-xs font-medium text-gray-700 mb-1.5">PayPal Client ID</label>
                          <input v-model="settingsForm.paypal_client_id" type="text" placeholder="Enter PayPal Client ID used on this site" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-700 mb-1.5">PayPal Client Secret</label>
                          <input v-model="settingsForm.paypal_secret" type="password" placeholder="Enter PayPal Client Secret used on this site" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-700 mb-1.5">PayPal Mode</label>
                          <select v-model="settingsForm.paypal_mode" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="sandbox">sandbox</option>
                            <option value="live">live</option>
                          </select>
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-700 mb-1.5">Activation</label>
                          <select v-model="settingsForm.paypal_enabled" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option :value="false">No</option>
                            <option :value="true">Yes</option>
                          </select>
                        </div>
                      </div>

                      <div v-else class="grid grid-cols-2 gap-4">
                        <div class="col-span-2">
                          <label class="block text-xs font-medium text-gray-700 mb-1.5">Stripe Public Key</label>
                          <input v-model="settingsForm.stripe_public_key" type="text" placeholder="pk_live_..." class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-700 mb-1.5">Stripe Secret Key</label>
                          <input v-model="settingsForm.stripe_secret_key" type="password" placeholder="sk_live_..." class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-700 mb-1.5">Stripe Mode</label>
                          <select v-model="settingsForm.stripe_mode" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="test">test</option>
                            <option value="live">live</option>
                          </select>
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-700 mb-1.5">Activation</label>
                          <select v-model="settingsForm.stripe_enabled" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option :value="false">No</option>
                            <option :value="true">Yes</option>
                          </select>
                        </div>
                        <div class="col-span-2">
                          <label class="block text-xs font-medium text-gray-700 mb-1.5">Stripe Webhook Signing Secret</label>
                          <input v-model="settingsForm.stripe_webhook_secret" type="text" placeholder="whsec_..." class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        </div>
                        <div class="col-span-2">
                          <label class="block text-xs font-medium text-gray-700 mb-1.5">Webhook URL</label>
                          <div class="flex gap-2">
                            <input :value="webhookUrl('stripe')" readonly class="flex-1 px-3 py-2.5 border border-gray-200 rounded-xl text-xs bg-gray-50 text-gray-500 font-mono" />
                            <button @click="copyToClipboard(webhookUrl('stripe'))" class="px-3.5 py-2.5 border border-gray-300 rounded-xl text-xs font-medium hover:bg-gray-50 transition-colors">Copy</button>
                          </div>
                        </div>
                      </div>
                    </div>

                    <div class="bg-gray-50 rounded-2xl border border-gray-200 p-6 space-y-5">
                      <div>
                        <h4 class="text-sm font-semibold text-gray-900">Spin Settings</h4>
                        <p class="text-xs text-gray-500 mt-0.5">Control allocation cycle and order limits</p>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">Receive Cycle</label>
                        <select v-model="settingsForm.receive_cycle" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                          <option value="lifetime">Lifetime</option>
                          <option value="monthly">Monthly</option>
                          <option value="weekly">Weekly</option>
                          <option value="daily">Daily</option>
                        </select>
                        <p class="text-[11px] text-gray-400 mt-1.5 leading-relaxed">Defines how OneShield resets accumulated received volume for this gateway.</p>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">Income Limit</label>
                        <input v-if="activeGateway === 'paypal'" v-model="settingsForm.paypal_income_limit" type="number" min="0" step="0.01" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        <input v-else v-model="settingsForm.stripe_income_limit" type="number" min="0" step="0.01" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        <p class="text-[11px] text-gray-400 mt-1.5 leading-relaxed">Maximum total income for this account in the selected cycle. Use `0` for unlimited.</p>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1.5">Max Amount Per Order</label>
                        <input v-if="activeGateway === 'paypal'" v-model="settingsForm.paypal_max_per_order" type="number" min="0" step="0.01" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        <input v-else v-model="settingsForm.stripe_max_per_order" type="number" min="0" step="0.01" class="w-full px-3 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        <p class="text-[11px] text-gray-400 mt-1.5 leading-relaxed">Maximum single order amount this shield site can process through the active gateway.</p>
                      </div>
                    </div>
                  </div>
                </template>

                <template v-else>
                  <div class="rounded-2xl border border-dashed border-rose-200 bg-rose-50/50 p-10 text-center">
                    <div class="w-14 h-14 rounded-2xl bg-rose-100 text-rose-600 flex items-center justify-center mx-auto mb-4">
                      <svg class="w-7 h-7" viewBox="0 0 100 100" fill="currentColor"><path d="M50 5 L95 27.5 L95 72.5 L50 95 L5 72.5 L5 27.5 Z" opacity="0.2"/><path d="M50 20 L80 36 L80 64 L50 80 L20 64 L20 36 Z" opacity="0.4"/><text x="50" y="60" text-anchor="middle" font-size="32" font-weight="bold" fill="currentColor" opacity="0.9">A</text></svg>
                    </div>
                    <h4 class="text-sm font-semibold text-gray-900">Airwallex arrives in Phase 2</h4>
                    <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">This slot is reserved so the gateway settings panel keeps the same structure now and can expand cleanly when Airwallex support is added.</p>
                  </div>
                </template>
              </div>
            </div>

          </div>
          <!-- End Panel Body -->

        </div>
      </div>
    </transition>

  </AppLayout>
</template>

<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, useForm, router, usePage } from '@inertiajs/vue3';
import { ref, computed, reactive } from 'vue';

const props = defineProps({
  sites:   Object,
  groups:  Array,
  filters: Object,
  stats:   Object,
});

// ── Filters ──────────────────────────────────────────────────────────────
const filterGroup    = ref(props.filters.group_id || '');
const showActiveOnly = ref(props.filters.status === 'active');

function applyFilters() {
  router.get('/sites', {
    group_id: filterGroup.value || undefined,
    status:   showActiveOnly.value ? 'active' : undefined,
  }, { preserveState: true });
}

function toggleActiveOnly() {
  showActiveOnly.value = !showActiveOnly.value;
  applyFilters();
}

const filteredSites = computed(() => props.sites.data);

// ── Gateway tabs ─────────────────────────────────────────────────────────
const gatewayTabs = [
  { key: 'paypal',    label: 'PayPal' },
  { key: 'stripe',    label: 'Stripe' },
  { key: 'airwallex', label: 'Airwallex' },
];
const activeGateway = ref('paypal');

// ── Add Site ─────────────────────────────────────────────────────────────
const page            = usePage();
const subscription    = computed(() => page.props.subscription);
const atLimit         = computed(() => {
  const s = subscription.value;
  if (!s) return false;
  if (s.sites_limit >= 999) return false;
  return s.sites_used >= s.sites_limit;
});
const showUpgradeModal = ref(false);
const showAddModal     = ref(false);
const addForm = useForm({ name: '', url: '', group_id: null });

function addSite() {
  if (atLimit.value) {
    showUpgradeModal.value = true;
    return;
  }
  addForm.reset();
  showAddModal.value = true;
}

function submitAdd() {
  addForm.post('/sites', { onSuccess: () => { showAddModal.value = false; } });
}

// ── Settings Panel ────────────────────────────────────────────────────────
const settingsSite = ref(null);
const settingsForm = useForm({
  name: '', group_id: null,
  paypal_client_id: '', paypal_secret: '', paypal_mode: 'sandbox', paypal_enabled: false,
  paypal_income_limit: 0, paypal_max_per_order: 0,
  stripe_public_key: '', stripe_secret_key: '', stripe_mode: 'test', stripe_enabled: false,
  stripe_webhook_secret: '', stripe_income_limit: 0, stripe_max_per_order: 0,
  receive_cycle: 'lifetime',
});

function openSettings(site) {
  settingsSite.value   = site;
  activeGateway.value  = 'paypal';
  settingsForm.name             = site.name;
  settingsForm.group_id         = site.group_id;
  settingsForm.paypal_client_id = '';   // don't pre-fill encrypted fields
  settingsForm.paypal_secret    = '';
  settingsForm.paypal_mode      = site.paypal_mode  || 'sandbox';
  settingsForm.paypal_enabled   = site.paypal_enabled || false;
  settingsForm.paypal_income_limit  = site.paypal_income_limit  || 0;
  settingsForm.paypal_max_per_order = site.paypal_max_per_order || 0;
  settingsForm.stripe_public_key    = '';
  settingsForm.stripe_secret_key    = '';
  settingsForm.stripe_mode          = site.stripe_mode   || 'test';
  settingsForm.stripe_enabled       = site.stripe_enabled || false;
  settingsForm.stripe_webhook_secret= '';
  settingsForm.stripe_income_limit  = site.stripe_income_limit  || 0;
  settingsForm.stripe_max_per_order = site.stripe_max_per_order || 0;
  settingsForm.receive_cycle        = site.receive_cycle || 'lifetime';
}

function closeSettings() {
  settingsSite.value = null;
  settingsForm.reset();
}

function saveSettings() {
  settingsForm.put(`/sites/${settingsSite.value.id}`, {
    onSuccess: closeSettings,
  });
}

// ── Check / Check all ────────────────────────────────────────────────────
const checkingAll = ref(false);

function checkSite(site) {
  router.post(`/sites/${site.id}/check`, {}, { preserveState: true });
}

function checkAll() {
  checkingAll.value = true;
  const promises = props.sites.data.map(site =>
    fetch(`/sites/${site.id}/check`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
      },
    })
  );
  Promise.all(promises).finally(() => {
    checkingAll.value = false;
    router.reload({ only: ['sites'] });
  });
}

function viewReports(site) {
  router.get(`/transactions?site_id=${site.id}`);
}

function confirmDelete(site) {
  if (confirm(`Remove site "${site.name}"? This cannot be undone.`)) {
    router.delete(`/sites/${site.id}`);
  }
}

// ── Drag-to-reorder ───────────────────────────────────────────────────────
// Local mutable copy of the site list (just ids + display order) for drag state
const dragState = reactive({
  draggingId: null,
  overIndex:  null,
  // local ordered id list — rebuilt from filteredSites on each page load
  orderedIds: [],
});

// Keep orderedIds in sync with filteredSites
function initDragOrder() {
  dragState.orderedIds = filteredSites.value.map(s => s.id);
}
initDragOrder();

function onDragStart(event, site) {
  dragState.draggingId = site.id;
  event.dataTransfer.effectAllowed = 'move';
  // Use site id as payload (same page, so we don't need cross-origin safety)
  event.dataTransfer.setData('text/plain', String(site.id));
}

function onDragOver(event, index) {
  event.preventDefault();
  event.dataTransfer.dropEffect = 'move';
  dragState.overIndex = index;
}

function onDragLeave() {
  dragState.overIndex = null;
}

function onDrop(event, targetIndex) {
  event.preventDefault();
  dragState.overIndex = null;

  const sourceId = parseInt(event.dataTransfer.getData('text/plain'), 10);
  const ids = [...dragState.orderedIds];
  const sourceIndex = ids.indexOf(sourceId);
  if (sourceIndex === -1 || sourceIndex === targetIndex) {
    dragState.draggingId = null;
    return;
  }

  // Reorder local array
  ids.splice(sourceIndex, 1);
  ids.splice(targetIndex, 0, sourceId);
  dragState.orderedIds = ids;
  dragState.draggingId = null;

  // Persist to server
  fetch('/sites/reorder', {
    method: 'PATCH',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
    },
    body: JSON.stringify({ ordered_ids: ids }),
  }).then(res => {
    if (!res.ok) {
      // Revert on server error
      initDragOrder();
    }
  }).catch(() => initDragOrder());
}

function onDragEnd() {
  dragState.draggingId = null;
  dragState.overIndex  = null;
}

// ── Helpers ───────────────────────────────────────────────────────────────
function heartbeatClass(site) {
  if (!site.last_heartbeat_at) return 'bg-gray-100 text-gray-500';
  const diffMin = (Date.now() - new Date(site.last_heartbeat_at)) / 60000;
  return diffMin <= 10
    ? 'bg-green-100 text-green-700'
    : diffMin <= 60
      ? 'bg-yellow-100 text-yellow-700'
      : 'bg-red-100 text-red-600';
}

function heartbeatLabel(site) {
  if (!site.last_heartbeat_at) return 'Never';
  const diffMin = Math.floor((Date.now() - new Date(site.last_heartbeat_at)) / 60000);
  if (diffMin <= 10) return 'Success';
  if (diffMin < 60)  return `${diffMin}m ago`;
  if (diffMin < 1440) return `${Math.floor(diffMin / 60)}h ago`;
  return `${Math.floor(diffMin / 1440)}d ago`;
}

function formatMoney(val) {
  if (!val || val === 0) return '0';
  return Number(val).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function webhookUrl(gateway) {
  const base = window.location.origin;
  return `${base}/api/webhook/${gateway}/${settingsSite.value?.id ?? '{site_id}'}`;
}

const copyToast  = ref('');
let   toastTimer = null;

function showCopyFeedback(label = 'Copied!') {
  copyToast.value = label;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { copyToast.value = ''; }, 2000);
}

function copyToClipboard(text) {
  navigator.clipboard.writeText(text).then(() => showCopyFeedback('Copied!')).catch(() => {});
}
</script>

<style scoped>
.toast-enter-active, .toast-leave-active { transition: opacity 0.2s, transform 0.2s; }
.toast-enter-from, .toast-leave-to { opacity: 0; transform: translateY(8px); }

.slideover-enter-active,
.slideover-leave-active {
  transition: opacity 0.2s ease;
}
.slideover-enter-active .flex-1,
.slideover-leave-active .flex-1 {
  transition: opacity 0.2s ease;
}
.slideover-enter-active > div:last-child,
.slideover-leave-active > div:last-child {
  transition: transform 0.25s ease;
}
.slideover-enter-from > div:last-child,
.slideover-leave-to > div:last-child {
  transform: translateX(100%);
}
.slideover-enter-from,
.slideover-leave-to {
  opacity: 0;
}
</style>
