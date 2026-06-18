<script setup>
import { ref, computed, onMounted } from 'vue';
import { route, navigate } from './lib/router.js';
import { api } from './lib/api.js';
import { onPoll, usePolling } from './lib/polling.js';
import { theme, cycleTheme, applyTheme, isDark } from './lib/theme.js';
import { num, relativeTime } from './lib/format.js';

import StatCard from './components/StatCard.vue';
import Icon from './components/Icon.vue';
import Logo from './components/Logo.vue';
import Toasts from './components/Toasts.vue';
import ConfirmDialog from './components/ConfirmDialog.vue';

import ScheduleView from './views/ScheduleView.vue';
import QueuesView from './views/QueuesView.vue';
import ErrorsView from './views/ErrorsView.vue';
import SetupScreen from './views/SetupScreen.vue';

const { paused, togglePause, lastTick } = usePolling();

const overview = ref(null);
const overviewError = ref(null);
const needsSetup = ref(false);

const TABS = [
  { id: 'schedule', label: 'Schedule', icon: 'schedule' },
  { id: 'queues', label: 'Queues & Jobs', icon: 'queue' },
  { id: 'errors', label: 'Errors', icon: 'error' },
];

const cfg = window.Watchtower || {};

const themeIcon = computed(() => (theme.value === 'dark' ? 'moon' : theme.value === 'light' ? 'sun' : isDark() ? 'moon' : 'sun'));
const themeLabel = computed(() => theme.value.charAt(0).toUpperCase() + theme.value.slice(1));

const scheduleHealthy = computed(() => {
  const s = overview.value?.schedule;
  if (!s) return null;
  return Math.max(0, (s.total || 0) - (s.missed || 0));
});

async function loadOverview() {
  try {
    overview.value = await api.overview();
    overviewError.value = null;
    needsSetup.value = false;
  } catch (e) {
    if (e.status === 503 && e.data && e.data.error === 'watchtower_not_installed') {
      needsSetup.value = true;
      overviewError.value = null;
    } else {
      overviewError.value = e.message;
    }
  }
}

function onSetupDone() {
  needsSetup.value = false;
  loadOverview();
}

const currentView = computed(() => {
  switch (route.value) {
    case 'queues': return QueuesView;
    case 'errors': return ErrorsView;
    default: return ScheduleView;
  }
});

onMounted(() => {
  applyTheme();
  loadOverview();
});
onPoll(loadOverview);
</script>

<template>
  <div class="min-h-screen">
    <!-- Header -->
    <header class="sticky top-0 z-40 wt-header">
      <div class="mx-auto flex max-w-[1280px] items-center gap-4 px-4 py-3 sm:px-6">
        <div class="flex items-center gap-2.5">
          <div class="wt-logo"><Logo :size="26" /></div>
          <div class="leading-none">
            <div class="wt-wordmark">Watchtower</div>
            <div class="wt-submark">v{{ cfg.version || 'dev' }}</div>
          </div>
        </div>

        <!-- Tabs -->
        <nav class="ml-2 hidden items-center gap-1 sm:flex">
          <button
            v-for="t in TABS" :key="t.id"
            class="wt-tab" :class="{ 'wt-tab-active': route === t.id }"
            @click="navigate(t.id)"
          >
            <Icon :name="t.icon" :size="15" /> {{ t.label }}
          </button>
        </nav>

        <div class="ml-auto flex items-center gap-2">
          <div class="hidden items-center gap-1.5 text-xs md:flex" style="color: var(--wt-text-faint)">
            <span class="wt-live-dot" :class="{ 'wt-live-paused': paused }"></span>
            <span>{{ paused ? 'Paused' : 'Live' }} · {{ relativeTime(lastTick) }}</span>
          </div>
          <button class="wt-icon-btn" :title="paused ? 'Resume auto-refresh' : 'Pause auto-refresh'" @click="togglePause">
            <Icon :name="paused ? 'play' : 'pause'" :size="15" />
          </button>
          <button class="wt-icon-btn" :title="`Theme: ${themeLabel} (click to cycle)`" @click="cycleTheme">
            <Icon :name="themeIcon" :size="15" />
          </button>
        </div>
      </div>

      <!-- mobile tabs -->
      <nav class="flex items-center gap-1 overflow-x-auto px-4 pb-2 sm:hidden">
        <button
          v-for="t in TABS" :key="t.id"
          class="wt-tab" :class="{ 'wt-tab-active': route === t.id }"
          @click="navigate(t.id)"
        >
          <Icon :name="t.icon" :size="15" /> {{ t.label }}
        </button>
      </nav>
    </header>

    <main class="mx-auto max-w-[1280px] px-4 py-5 sm:px-6">
      <!-- First-run / multi-tenant setup screen -->
      <SetupScreen v-if="needsSetup" @done="onSetupDone" />

      <template v-else>
      <!-- Summary bar -->
      <div class="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
        <StatCard
          label="Jobs processed (24h)"
          :value="overview ? num(overview.jobs.processed_24h) : '—'"
          tone="ok" icon="check"
        />
        <StatCard
          label="Failures (24h)"
          :value="overview ? num(overview.jobs.failed_24h) : '—'"
          :sub="overview ? num(overview.jobs.failed_total) + ' total' : ''"
          :tone="overview && overview.jobs.failed_24h ? 'fail' : 'neutral'"
          icon="error"
        />
        <StatCard
          label="Scheduled tasks"
          :value="overview ? `${num(scheduleHealthy)} / ${num(overview.schedule.total)}` : '—'"
          :sub="overview && overview.schedule.missed ? `${num(overview.schedule.missed)} missed!` : 'all healthy'"
          :tone="overview && overview.schedule.missed ? 'warn' : 'ok'"
          icon="schedule"
        />
        <StatCard
          label="Unresolved errors"
          :value="overview ? num(overview.exceptions.unresolved) : '—'"
          :tone="overview && overview.exceptions.unresolved ? 'fail' : 'ok'"
          icon="error"
        />
      </div>

      <div v-if="overviewError" class="mb-4 wt-card px-4 py-2.5 text-xs flex items-center gap-2" style="color:#dc2626">
        <Icon name="error" :size="14" /> Overview unavailable: {{ overviewError }}
      </div>

      <!-- Recording notice -->
      <div
        v-if="overview && overview.meta && !overview.meta.recording"
        class="mb-4 wt-card px-4 py-2.5 text-xs flex items-center gap-2"
        style="color: var(--wt-text-muted)"
      >
        <Icon name="pause" :size="14" /> Recording is currently disabled — data may be stale.
      </div>

      <!-- Active view -->
      <component :is="currentView" :key="route" />

      <footer class="mt-8 flex items-center justify-between text-xs" style="color: var(--wt-text-faint)">
        <span>Watchtower · self-contained monitoring</span>
        <span v-if="overview && overview.meta">
          sampling {{ Math.round((overview.meta.sampling_rate ?? 1) * 100) }}% ·
          generated {{ relativeTime(overview.meta.generated_at) }}
        </span>
      </footer>
      </template>
    </main>

    <Toasts />
    <ConfirmDialog />
  </div>
</template>

<style scoped>
.wt-header {
  background: color-mix(in srgb, var(--wt-bg) 82%, transparent);
  backdrop-filter: blur(14px) saturate(1.2);
  border-bottom: 1px solid var(--wt-border);
}
.wt-logo {
  display: flex; align-items: center; justify-content: center;
  width: 38px; height: 38px; border-radius: 0.6rem;
  background: #ffffff;
  box-shadow: inset 0 0 0 1px var(--wt-border), 0 2px 8px -3px rgba(15, 23, 42, 0.25);
}

.wt-wordmark {
  font-size: 1rem; font-weight: 700; letter-spacing: -0.01em;
  color: var(--wt-text);
}
.wt-submark {
  margin-top: 1px;
  font-size: 0.6875rem;
  color: var(--wt-text-faint);
}

.wt-tab {
  position: relative;
  display: inline-flex; align-items: center; gap: 0.4rem;
  padding: 0.5rem 0.65rem; margin: 0 0.1rem;
  font-size: 0.8125rem; font-weight: 500;
  white-space: nowrap; color: var(--wt-text-muted);
  cursor: pointer; transition: color .14s ease;
}
.wt-tab:hover { color: var(--wt-text); }
.wt-tab-active { color: var(--wt-accent); font-weight: 600; }
.wt-tab-active::after {
  content: ''; position: absolute; left: 0.65rem; right: 0.65rem; bottom: -0.78rem; height: 2px;
  background: var(--wt-accent); border-radius: 2px;
}

.wt-icon-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 34px; height: 34px; border-radius: 0.45rem;
  border: 1px solid var(--wt-border); background: var(--wt-surface-2);
  color: var(--wt-text-muted); cursor: pointer; transition: all .14s ease;
}
.wt-icon-btn:hover { color: var(--wt-accent); background: var(--wt-surface-3); border-color: var(--wt-border-strong); }

.wt-live-dot {
  width: 7px; height: 7px; border-radius: 9999px; background: var(--wt-ok);
  box-shadow: 0 0 0 0 currentColor; color: var(--wt-ok);
  animation: wt-pulse 2.2s infinite;
}
.wt-live-paused { background: var(--wt-text-faint); color: var(--wt-text-faint); animation: none; }
@keyframes wt-pulse {
  0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--wt-ok) 45%, transparent); }
  70% { box-shadow: 0 0 0 6px transparent; }
  100% { box-shadow: 0 0 0 0 transparent; }
}
</style>
