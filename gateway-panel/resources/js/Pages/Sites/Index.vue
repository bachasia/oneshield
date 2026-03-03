<template>
  <AppLayout title="Mesh Sites">

    <!-- Page Header -->
    <div class="mb-1">
      <h1 class="text-xl font-semibold text-gray-800">Mesh Sites</h1>
      <p class="text-sm text-gray-500 mt-0.5">You can manage payment sites from here</p>
    </div>

    <!-- Stats + Action bar -->
    <div class="flex items-start justify-between mt-3 mb-4">
      <!-- Gateway mode stats -->
      <div class="text-sm text-gray-600 leading-relaxed">
        <div>
          <span class="font-medium">Live Mode:</span>
          {{ stats.live_paypal }} PayPal Activated
          <span class="mx-1 text-gray-400">|</span>
          {{ stats.live_stripe }} Stripe Activated
        </div>
        <div>
          <span class="font-medium">Test Mode:</span>
          {{ stats.test_paypal }} PayPal Activated
          <span class="mx-1 text-gray-400">|</span>
          {{ stats.test_stripe }} Stripe Activated
        </div>
      </div>

      <!-- Right controls -->
      <div class="flex items-center gap-2">
        <button
          @click="addSite"
          class="flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm font-medium transition-colors"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
          Add Site
        </button>
        <button
          @click="checkAll"
          :disabled="checkingAll"
          class="flex items-center gap-1.5 border border-blue-500 text-blue-600 hover:bg-blue-50 px-3 py-2 rounded text-sm font-medium transition-colors disabled:opacity-50"
        >
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
          Check all
        </button>
      </div>
    </div>

    <!-- Show active only toggle + group filter -->
    <div class="flex items-center justify-between mb-3">
      <div class="flex items-center gap-3">
        <select
          v-model="filterGroup"
          @change="applyFilters"
          class="px-3 py-1.5 border border-gray-300 rounded text-sm bg-white text-gray-700 focus:outline-none focus:ring-2 focus:ring-indigo-500"
        >
          <option value="">All Groups</option>
          <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
        </select>
      </div>
      <label class="flex items-center gap-2 text-sm text-gray-600 cursor-pointer select-none">
        <button
          @click="toggleActiveOnly"
          class="relative inline-flex h-5 w-9 items-center rounded-full transition-colors focus:outline-none"
          :class="showActiveOnly ? 'bg-indigo-600' : 'bg-gray-300'"
        >
          <span
            class="inline-block h-3.5 w-3.5 transform rounded-full bg-white shadow transition-transform"
            :class="showActiveOnly ? 'translate-x-4' : 'translate-x-0.5'"
          />
        </button>
        Show active only
      </label>
    </div>

    <!-- Sites Table -->
    <div class="bg-white rounded border border-gray-200 overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="border-b border-gray-200 text-gray-600 text-left">
            <th class="px-4 py-3 font-medium w-40">Site Name</th>
            <th class="px-4 py-3 font-medium">URL</th>
            <th class="px-4 py-3 font-medium w-36">Gross Received</th>
            <th class="px-4 py-3 font-medium w-40">Active Status</th>
            <th class="px-4 py-3 font-medium w-28">Groups</th>
            <th class="px-4 py-3 font-medium w-36">Mode</th>
            <th class="px-4 py-3 font-medium text-right"></th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-if="filteredSites.length === 0">
            <td colspan="7" class="px-4 py-12 text-center text-gray-400 text-sm">
              No mesh sites found. Click "Add Site" to get started.
            </td>
          </tr>
          <tr v-for="site in filteredSites" :key="site.id" class="hover:bg-gray-50 align-top">

            <!-- Site Name -->
            <td class="px-4 py-3">
              <div class="font-medium text-gray-900">{{ site.name }}</div>
            </td>

            <!-- URL + heartbeat status -->
            <td class="px-4 py-3">
              <a :href="site.url" target="_blank" class="text-gray-700 hover:text-indigo-600 text-sm">{{ site.url }}</a>
              <div class="mt-1">
                <span
                  class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium"
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
            <td class="px-4 py-3 text-gray-600 text-sm">
              <div>PayPal: {{ formatMoney(site.gross_paypal) }}</div>
              <div>Stripe: {{ formatMoney(site.gross_stripe) }}</div>
            </td>

            <!-- Active Status per gateway -->
            <td class="px-4 py-3">
              <div class="flex flex-col gap-1">
                <div class="flex items-center gap-1.5 text-xs text-gray-600">
                  <!-- PayPal icon -->
                  <svg class="w-3.5 h-3.5 text-blue-600" viewBox="0 0 24 24" fill="currentColor"><path d="M7.076 21.337H2.47a.641.641 0 01-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106zm14.146-14.42a3.35 3.35 0 00-.607-.541c-.013.076-.026.175-.041.254-.93 4.778-4.005 7.201-9.138 7.201h-2.19a.563.563 0 00-.556.479l-1.187 7.527h-.506l-.24 1.516a.56.56 0 00.554.647h3.882c.46 0 .85-.334.922-.788.06-.26.76-4.852.816-5.09a.932.932 0 01.923-.788h.58c3.76 0 6.705-1.528 7.565-5.946.36-1.847.174-3.388-.777-4.471z"/></svg>
                  PayPal
                  <span v-if="site.paypal_enabled" class="bg-green-500 text-white text-[10px] px-1.5 py-0.5 rounded flex items-center gap-0.5">
                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                    Activated
                  </span>
                </div>
                <div class="flex items-center gap-1.5 text-xs text-gray-600">
                  <!-- Stripe S -->
                  <span class="w-3.5 h-3.5 rounded-full bg-indigo-600 text-white text-[9px] font-bold flex items-center justify-center">S</span>
                  Stripe
                  <span v-if="site.stripe_enabled" class="bg-green-500 text-white text-[10px] px-1.5 py-0.5 rounded flex items-center gap-0.5">
                    <svg class="w-2.5 h-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                    Activated
                  </span>
                </div>
                <div class="flex items-center gap-1.5 text-xs text-gray-600">
                  <!-- Airwallex A -->
                  <span class="w-3.5 h-3.5 rounded-full bg-rose-500 text-white text-[9px] font-bold flex items-center justify-center">A</span>
                  Airwallex
                </div>
              </div>
            </td>

            <!-- Groups -->
            <td class="px-4 py-3">
              <span
                v-if="site.group"
                class="inline-block bg-blue-100 text-blue-700 text-xs font-medium px-2 py-0.5 rounded"
              >
                {{ site.group.name }}
              </span>
              <span v-else class="text-gray-400 text-xs">—</span>
            </td>

            <!-- Mode -->
            <td class="px-4 py-3">
              <div class="flex flex-col gap-1">
                <div class="flex items-center gap-1.5 text-xs text-gray-600">
                  <svg class="w-3.5 h-3.5 text-blue-600" viewBox="0 0 24 24" fill="currentColor"><path d="M7.076 21.337H2.47a.641.641 0 01-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106zm14.146-14.42a3.35 3.35 0 00-.607-.541c-.013.076-.026.175-.041.254-.93 4.778-4.005 7.201-9.138 7.201h-2.19a.563.563 0 00-.556.479l-1.187 7.527h-.506l-.24 1.516a.56.56 0 00.554.647h3.882c.46 0 .85-.334.922-.788.06-.26.76-4.852.816-5.09a.932.932 0 01.923-.788h.58c3.76 0 6.705-1.528 7.565-5.946.36-1.847.174-3.388-.777-4.471z"/></svg>
                  <span
                    class="text-[10px] font-medium px-1.5 py-0.5 rounded"
                    :class="site.paypal_mode === 'live' ? 'bg-green-100 text-green-700' : 'bg-gray-800 text-white'"
                  >
                    {{ site.paypal_mode === 'live' ? 'Live' : 'Sandbox' }}
                  </span>
                </div>
                <div class="flex items-center gap-1.5 text-xs text-gray-600">
                  <span class="w-3.5 h-3.5 rounded-full bg-indigo-600 text-white text-[9px] font-bold flex items-center justify-center">S</span>
                  <span
                    class="text-[10px] font-medium px-1.5 py-0.5 rounded"
                    :class="site.stripe_mode === 'live' ? 'bg-green-100 text-green-700' : 'bg-green-100 text-green-700'"
                  >
                    {{ site.stripe_mode === 'live' ? 'Live' : 'Live' }}
                  </span>
                </div>
              </div>
            </td>

            <!-- Actions -->
            <td class="px-4 py-3">
              <div class="flex items-center gap-1.5 justify-end flex-wrap">
                <button
                  @click="openSettings(site)"
                  class="bg-indigo-700 hover:bg-indigo-800 text-white text-xs px-3 py-1.5 rounded font-medium transition-colors"
                >Settings</button>
                <button
                  @click="viewReports(site)"
                  class="bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs px-3 py-1.5 rounded font-medium transition-colors"
                >Reports</button>
                <button
                  @click="confirmDelete(site)"
                  class="bg-red-500 hover:bg-red-600 text-white text-xs px-3 py-1.5 rounded font-medium transition-colors"
                >Delete</button>
                <button
                  @click="checkSite(site)"
                  class="border border-blue-400 text-blue-600 hover:bg-blue-50 text-xs px-3 py-1.5 rounded font-medium transition-colors flex items-center gap-1"
                >
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/></svg>
                  Check
                </button>
                <!-- Drag handle -->
                <span class="cursor-grab text-gray-300 hover:text-gray-500 ml-1 select-none text-base">⊕</span>
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
          class="px-3 py-1 rounded text-sm"
          :class="link.active
            ? 'bg-indigo-600 text-white'
            : link.url ? 'text-gray-600 hover:bg-gray-100' : 'text-gray-300 cursor-not-allowed'"
        />
      </div>
    </div>

    <!-- =========================================================== -->
    <!-- Add Site Modal (simple) -->
    <!-- =========================================================== -->
    <div v-if="showAddModal" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-6">
        <h3 class="text-base font-semibold text-gray-900 mb-4">Add Mesh Site</h3>
        <form @submit.prevent="submitAdd" class="space-y-3">
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Site Name</label>
            <input v-model="addForm.name" type="text" required placeholder="My Shop" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Site URL</label>
            <input v-model="addForm.url" type="url" required placeholder="https://mesh-site.com" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
          </div>
          <div>
            <label class="block text-xs font-medium text-gray-700 mb-1">Group (optional)</label>
            <select v-model="addForm.group_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
              <option :value="null">No Group</option>
              <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
            </select>
          </div>
          <div class="flex gap-3 pt-2">
            <button type="button" @click="showAddModal = false" class="flex-1 px-4 py-2 border border-gray-300 rounded text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
            <button type="submit" :disabled="addForm.processing" class="flex-1 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded text-sm font-medium disabled:opacity-50">Add Site</button>
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
        <div class="w-full max-w-3xl bg-white shadow-2xl flex flex-col overflow-hidden">

          <!-- Panel Header -->
          <div class="flex items-center justify-between px-6 py-4 border-b border-gray-200 flex-shrink-0">
            <div>
              <h2 class="text-base font-semibold text-gray-900">Mesh Site Settings</h2>
              <p class="text-xs text-gray-500 mt-0.5">{{ settingsSite.url }}</p>
            </div>
            <div class="flex items-center gap-3">
              <button @click="closeSettings" class="text-gray-400 hover:text-gray-600">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
              </button>
              <button
                @click="saveSettings"
                :disabled="settingsForm.processing"
                class="bg-indigo-700 hover:bg-indigo-800 text-white text-sm px-5 py-2 rounded font-medium disabled:opacity-50 transition-colors"
              >Save Settings</button>
            </div>
          </div>

          <!-- Panel Body (scrollable) -->
          <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">

            <!-- Row 1: Site Name + Groups -->
            <div class="grid grid-cols-2 gap-5">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Site Name</label>
                <input v-model="settingsForm.name" type="text" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1.5">Groups</label>
                <select v-model="settingsForm.group_id" class="w-full px-3 py-2 border border-gray-300 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                  <option :value="null">No Group</option>
                  <option v-for="g in groups" :key="g.id" :value="g.id">{{ g.name }}</option>
                </select>
              </div>
            </div>

            <!-- Authorize Key -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1.5">Authorize Key</label>
              <input
                :value="settingsSite.site_key"
                readonly
                class="w-full px-3 py-2 border border-gray-200 rounded text-sm bg-gray-50 text-gray-600 font-mono"
              />
              <p class="text-xs text-gray-400 mt-1">
                This key is used for connection between gateway and mesh site. If you recreate the website, update the correct key from the mesh site's OneShield Connect menu.
              </p>
            </div>

            <!-- Payments Settings -->
            <div>
              <h3 class="text-sm font-semibold text-gray-800 mb-3">Payments Settings</h3>

              <div class="flex rounded-lg border border-gray-200 overflow-hidden">

                <!-- Left: Gateway tabs -->
                <div class="w-28 bg-gray-800 flex flex-col flex-shrink-0">
                  <button
                    v-for="tab in gatewayTabs"
                    :key="tab.key"
                    @click="activeGateway = tab.key"
                    class="flex flex-col items-center justify-center gap-1.5 py-5 text-xs font-medium transition-colors"
                    :class="activeGateway === tab.key
                      ? 'bg-gray-700 text-white'
                      : 'text-gray-400 hover:bg-gray-700 hover:text-white'"
                  >
                    <!-- PayPal icon -->
                    <template v-if="tab.key === 'paypal'">
                      <svg class="w-7 h-7 text-blue-400" viewBox="0 0 24 24" fill="currentColor"><path d="M7.076 21.337H2.47a.641.641 0 01-.633-.74L4.944.901C5.026.382 5.474 0 5.998 0h7.46c2.57 0 4.578.543 5.69 1.81 1.01 1.15 1.304 2.42 1.012 4.287-.023.143-.047.288-.077.437-.983 5.05-4.349 6.797-8.647 6.797h-2.19c-.524 0-.968.382-1.05.9l-1.12 7.106zm14.146-14.42a3.35 3.35 0 00-.607-.541c-.013.076-.026.175-.041.254-.93 4.778-4.005 7.201-9.138 7.201h-2.19a.563.563 0 00-.556.479l-1.187 7.527h-.506l-.24 1.516a.56.56 0 00.554.647h3.882c.46 0 .85-.334.922-.788.06-.26.76-4.852.816-5.09a.932.932 0 01.923-.788h.58c3.76 0 6.705-1.528 7.565-5.946.36-1.847.174-3.388-.777-4.471z"/></svg>
                      <span>PayPal</span>
                    </template>
                    <!-- Stripe icon -->
                    <template v-if="tab.key === 'stripe'">
                      <svg class="w-7 h-7 text-indigo-400" viewBox="0 0 24 24" fill="currentColor"><path d="M13.976 9.15c-2.172-.806-3.356-1.426-3.356-2.409 0-.831.683-1.305 1.901-1.305 2.227 0 4.515.858 6.09 1.631l.89-5.494C18.252.975 15.697 0 12.165 0 9.667 0 7.589.654 6.104 1.872 4.56 3.147 3.757 4.992 3.757 7.218c0 4.039 2.467 5.76 6.476 7.219 2.585.92 3.445 1.574 3.445 2.583 0 .98-.84 1.545-2.354 1.545-1.875 0-4.965-.921-6.99-2.109l-.9 5.555C5.175 22.99 8.385 24 11.714 24c2.641 0 4.843-.624 6.328-1.813 1.664-1.305 2.525-3.236 2.525-5.732 0-4.128-2.524-5.851-6.591-7.305z"/></svg>
                      <span>Stripe</span>
                    </template>
                    <!-- Airwallex icon -->
                    <template v-if="tab.key === 'airwallex'">
                      <svg class="w-7 h-7 text-rose-400" viewBox="0 0 100 100" fill="currentColor"><path d="M50 5 L95 27.5 L95 72.5 L50 95 L5 72.5 L5 27.5 Z" opacity="0.2"/><path d="M50 20 L80 36 L80 64 L50 80 L20 64 L20 36 Z" opacity="0.4"/><text x="50" y="60" text-anchor="middle" font-size="32" font-weight="bold" fill="currentColor" opacity="0.9">A</text></svg>
                      <span>Airwallex</span>
                    </template>
                  </button>
                </div>

                <!-- Right: Config + Spin panels -->
                <div class="flex-1 flex divide-x divide-gray-200">

                  <!-- === PAYPAL CONFIG === -->
                  <template v-if="activeGateway === 'paypal'">
                    <div class="flex-1 p-5 space-y-4">
                      <h4 class="text-sm font-semibold text-gray-700 bg-gray-100 -mx-5 -mt-5 px-5 py-3 mb-4">PayPal API Config</h4>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">PayPal Client ID</label>
                        <input v-model="settingsForm.paypal_client_id" type="text" placeholder="Enter PayPal Client ID using for transaction on this site" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">PayPal Client Secret</label>
                        <input v-model="settingsForm.paypal_secret" type="password" placeholder="Enter PayPal Client Secret using for transaction on this sit" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                      </div>
                      <div class="grid grid-cols-2 gap-3">
                        <div>
                          <label class="block text-xs font-medium text-gray-700 mb-1">Paypal Mode</label>
                          <select v-model="settingsForm.paypal_mode" class="w-full px-3 py-2 border border-gray-300 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="sandbox">sandbox</option>
                            <option value="live">live</option>
                          </select>
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-700 mb-1">Is Enable/Activative?</label>
                          <select v-model="settingsForm.paypal_enabled" class="w-full px-3 py-2 border border-gray-300 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option :value="false">No</option>
                            <option :value="true">Yes</option>
                          </select>
                        </div>
                      </div>
                    </div>
                    <div class="w-56 p-5 space-y-4 flex-shrink-0">
                      <h4 class="text-sm font-semibold text-gray-700 bg-gray-100 -mx-5 -mt-5 px-5 py-3 mb-4">PayPal Spin Setting</h4>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Receive Cycle</label>
                        <select v-model="settingsForm.receive_cycle" class="w-full px-3 py-2 border border-gray-300 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                          <option value="lifetime">Lifetime</option>
                          <option value="monthly">Monthly</option>
                          <option value="weekly">Weekly</option>
                          <option value="daily">Daily</option>
                        </select>
                        <p class="text-[10px] text-gray-400 mt-1">The way OneShield will record received amount and reset for new receive cycle.</p>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Income Limit</label>
                        <input v-model="settingsForm.paypal_income_limit" type="number" min="0" step="0.01" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        <p class="text-[10px] text-gray-400 mt-1">The PayPal income amount limit for this account. Leave 0 for unlimit.</p>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Max Amount Per Order</label>
                        <input v-model="settingsForm.paypal_max_per_order" type="number" min="0" step="0.01" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        <p class="text-[10px] text-gray-400 mt-1">The maximum amount per order on checkout which this account can process. Leave 0 for unlimit.</p>
                      </div>
                    </div>
                  </template>

                  <!-- === STRIPE CONFIG === -->
                  <template v-if="activeGateway === 'stripe'">
                    <div class="flex-1 p-5 space-y-4">
                      <h4 class="text-sm font-semibold text-gray-700 bg-gray-100 -mx-5 -mt-5 px-5 py-3 mb-4">Stripe API Config</h4>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Stripe Public Key</label>
                        <input v-model="settingsForm.stripe_public_key" type="text" placeholder="pk_live_..." class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Stripe Secret Key</label>
                        <input v-model="settingsForm.stripe_secret_key" type="password" placeholder="sk_live_..." class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                      </div>
                      <div class="grid grid-cols-2 gap-3">
                        <div>
                          <label class="block text-xs font-medium text-gray-700 mb-1">Stripe Mode</label>
                          <select v-model="settingsForm.stripe_mode" class="w-full px-3 py-2 border border-gray-300 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option value="test">test</option>
                            <option value="live">live</option>
                          </select>
                        </div>
                        <div>
                          <label class="block text-xs font-medium text-gray-700 mb-1">Is Enable/Activative?</label>
                          <select v-model="settingsForm.stripe_enabled" class="w-full px-3 py-2 border border-gray-300 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                            <option :value="false">No</option>
                            <option :value="true">Yes</option>
                          </select>
                        </div>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Stripe Webhook Signing Secret</label>
                        <input v-model="settingsForm.stripe_webhook_secret" type="text" placeholder="whsec_..." class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Webhook URL (add this in Stripe Dashboard)</label>
                        <div class="flex gap-2">
                          <input
                            :value="webhookUrl('stripe')"
                            readonly
                            class="flex-1 px-3 py-2 border border-gray-200 rounded text-xs bg-gray-50 text-gray-500 font-mono"
                          />
                          <button @click="copyToClipboard(webhookUrl('stripe'))" class="px-3 py-2 border border-gray-300 rounded text-xs hover:bg-gray-50">Copy</button>
                        </div>
                      </div>
                    </div>
                    <div class="w-56 p-5 space-y-4 flex-shrink-0">
                      <h4 class="text-sm font-semibold text-gray-700 bg-gray-100 -mx-5 -mt-5 px-5 py-3 mb-4">Stripe Spin Setting</h4>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Receive Cycle</label>
                        <select v-model="settingsForm.receive_cycle" class="w-full px-3 py-2 border border-gray-300 rounded text-sm bg-white focus:ring-2 focus:ring-indigo-500 focus:outline-none">
                          <option value="lifetime">Lifetime</option>
                          <option value="monthly">Monthly</option>
                          <option value="weekly">Weekly</option>
                          <option value="daily">Daily</option>
                        </select>
                        <p class="text-[10px] text-gray-400 mt-1">The way OneShield will record received amount and reset for new receive cycle.</p>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Income Limit</label>
                        <input v-model="settingsForm.stripe_income_limit" type="number" min="0" step="0.01" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        <p class="text-[10px] text-gray-400 mt-1">The Stripe income amount limit for this account. Leave 0 for unlimit.</p>
                      </div>
                      <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Max Amount Per Order</label>
                        <input v-model="settingsForm.stripe_max_per_order" type="number" min="0" step="0.01" class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-indigo-500 focus:outline-none" />
                        <p class="text-[10px] text-gray-400 mt-1">The maximum amount per order on checkout which this account can process. Leave 0 for unlimit.</p>
                      </div>
                    </div>
                  </template>

                  <!-- === AIRWALLEX CONFIG === -->
                  <template v-if="activeGateway === 'airwallex'">
                    <div class="flex-1 p-5 space-y-4">
                      <h4 class="text-sm font-semibold text-gray-700 bg-gray-100 -mx-5 -mt-5 px-5 py-3 mb-4">Airwallex API Config <span class="text-xs text-amber-500 font-normal">(Phase 2)</span></h4>
                      <p class="text-sm text-gray-500">Airwallex integration is coming in Phase 2.</p>
                    </div>
                    <div class="w-56 p-5 flex-shrink-0">
                      <h4 class="text-sm font-semibold text-gray-700 bg-gray-100 -mx-5 -mt-5 px-5 py-3 mb-4">Airwallex Spin Setting</h4>
                    </div>
                  </template>

                </div>
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
import { Link, useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

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
const showAddModal = ref(false);
const addForm = useForm({ name: '', url: '', group_id: null });

function addSite() {
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
  settingsForm.patch(`/sites/${settingsSite.value.id}`, {
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

function copyToClipboard(text) {
  navigator.clipboard.writeText(text).catch(() => {});
}
</script>

<style scoped>
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
