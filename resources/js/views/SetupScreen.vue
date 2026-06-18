<script setup>
import { ref, onMounted } from 'vue';
import { api } from '../lib/api.js';
import Logo from '../components/Logo.vue';
import Icon from '../components/Icon.vue';
import Spinner from '../components/Spinner.vue';

const emit = defineEmits(['done']);

const tablePrefix = (window.Watchtower && window.Watchtower.tablePrefix) || 'watchtower_';
const status = ref(null);
const loading = ref(false);
const running = ref(false);
const error = ref(null);
const output = ref(null);

async function loadStatus() {
  loading.value = true;
  try {
    status.value = await api.setupStatus();
    if (status.value.installed) emit('done');
  } catch (e) {
    error.value = e.message;
  } finally {
    loading.value = false;
  }
}

async function runSetup() {
  running.value = true;
  error.value = null;
  output.value = null;
  try {
    const res = await api.setupMigrate();
    output.value = res.output || '';
    status.value = res;
    if (res.ok) {
      // Give the success state a beat to read, then hand back to the app.
      setTimeout(() => emit('done'), 600);
    } else {
      error.value = res.message || 'Setup did not complete.';
    }
  } catch (e) {
    error.value = e.data?.message || e.message;
  } finally {
    running.value = false;
  }
}

onMounted(loadStatus);
</script>

<template>
  <div class="wt-setup-wrap">
    <div class="wt-card wt-setup">
      <div class="wt-setup-logo"><Logo :size="40" /></div>

      <h1 class="wt-setup-title wt-display">Set up Watchtower</h1>
      <p class="wt-setup-lead">
        Watchtower's database tables haven't been created yet on the
        <code>{{ status?.connection || 'default' }}</code> connection. Create them now to start
        monitoring — this runs only Watchtower's own migrations and never touches your app's tables.
      </p>

      <!-- table checklist -->
      <ul v-if="status?.tables" class="wt-setup-tables">
        <li v-for="(present, name) in status.tables" :key="name">
          <span class="wt-check" :class="present ? 'wt-check-ok' : 'wt-check-missing'">
            <Icon :name="present ? 'check' : 'error'" :size="13" />
          </span>
          <code>{{ tablePrefix + name }}</code>
          <span class="wt-check-label">{{ present ? 'ready' : 'missing' }}</span>
        </li>
      </ul>

      <button class="wt-btn wt-btn-primary wt-setup-btn" :disabled="running" @click="runSetup">
        <Spinner v-if="running" :size="15" />
        <Icon v-else name="play" :size="15" />
        {{ running ? 'Creating tables…' : 'Run database setup' }}
      </button>

      <pre v-if="output" class="wt-output wt-setup-output">{{ output }}</pre>

      <div v-if="error" class="wt-setup-error">
        <Icon name="error" :size="14" /> {{ error }}
      </div>

      <div class="wt-setup-hint">
        <strong>Multi-tenant app?</strong> If your default connection changes per request,
        set <code>WATCHTOWER_DB_CONNECTION</code> to a stable central connection in your
        <code>.env</code>, run <code>php artisan config:clear</code>, then click the button above.
      </div>

      <button class="wt-setup-retry" :disabled="loading" @click="loadStatus">
        <Icon name="refresh" :size="13" /> Re-check
      </button>
    </div>
  </div>
</template>

<style scoped>
.wt-setup-wrap { display: flex; justify-content: center; padding: 3rem 1rem; }
.wt-setup { max-width: 560px; width: 100%; padding: 2rem 2rem 1.5rem; text-align: center; }
.wt-setup-logo {
  width: 72px; height: 72px; margin: 0 auto 1.25rem; border-radius: 1rem;
  background: #fff; display: flex; align-items: center; justify-content: center;
  box-shadow: inset 0 0 0 1px var(--wt-border), 0 8px 24px -12px rgba(15,23,42,.4);
}
.wt-setup-title { font-size: 1.5rem; font-weight: 700; color: var(--wt-text); }
.wt-setup-lead { margin: 0.6rem 0 1.25rem; font-size: 0.9rem; line-height: 1.55; color: var(--wt-text-muted); }
.wt-setup-lead code, .wt-setup-hint code { font-family: 'IBM Plex Mono', monospace; font-size: 0.82em; background: var(--wt-surface-2); padding: 0.05rem 0.3rem; border-radius: 0.3rem; color: var(--wt-text); }
.wt-setup-tables { list-style: none; margin: 0 0 1.25rem; padding: 0; text-align: left; border: 1px solid var(--wt-border); border-radius: 0.6rem; overflow: hidden; }
.wt-setup-tables li { display: flex; align-items: center; gap: 0.6rem; padding: 0.55rem 0.85rem; font-family: 'IBM Plex Mono', monospace; font-size: 0.78rem; color: var(--wt-text-muted); border-bottom: 1px solid var(--wt-border); }
.wt-setup-tables li:last-child { border-bottom: 0; }
.wt-check { display: inline-flex; width: 20px; height: 20px; align-items: center; justify-content: center; border-radius: 9999px; }
.wt-check-ok { color: var(--wt-ok); background: color-mix(in srgb, var(--wt-ok) 14%, transparent); }
.wt-check-missing { color: var(--wt-fail); background: color-mix(in srgb, var(--wt-fail) 14%, transparent); }
.wt-check-label { margin-left: auto; text-transform: uppercase; letter-spacing: 0.06em; font-size: 0.62rem; }
.wt-setup-btn { width: 100%; justify-content: center; padding: 0.6rem; font-size: 0.9rem; }
.wt-setup-output { margin-top: 1rem; text-align: left; }
.wt-setup-error { margin-top: 1rem; display: flex; align-items: center; gap: 0.4rem; justify-content: center; font-size: 0.82rem; color: var(--wt-fail); }
.wt-setup-hint { margin-top: 1.25rem; padding: 0.8rem 0.95rem; text-align: left; font-size: 0.8rem; line-height: 1.5; color: var(--wt-text-muted); background: var(--wt-surface-2); border: 1px solid var(--wt-border); border-radius: 0.6rem; }
.wt-setup-retry { margin-top: 1rem; display: inline-flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; color: var(--wt-text-faint); background: none; border: 0; cursor: pointer; }
.wt-setup-retry:hover { color: var(--wt-text); }
</style>
