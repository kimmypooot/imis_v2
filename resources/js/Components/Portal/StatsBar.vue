<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Icon from '../UI/Icon.vue';

/**
 * The legacy dashboard's stats bar. Every number is a real count from the
 * server (PortalController); the clock is the visitor's own local time.
 */
defineProps({
    stats: { type: Object, required: true },
});

const now = ref(new Date());
let timer;

onMounted(() => {
    timer = window.setInterval(() => (now.value = new Date()), 1000);
});
onBeforeUnmount(() => window.clearInterval(timer));

const time = () => now.value.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit', second: '2-digit' });
const date = () => now.value.toLocaleDateString(undefined, { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
</script>

<template>
    <dl class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <div class="flex items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3">
            <Icon name="grid" class="shrink-0 text-primary" />
            <div class="flex flex-col-reverse">
                <dt class="text-xs text-text-muted">Active Systems</dt>
                <dd class="text-xl font-semibold text-text">{{ stats.systems }}</dd>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3">
            <Icon name="check" class="shrink-0 text-success" />
            <div class="flex flex-col-reverse">
                <dt class="text-xs text-text-muted">Accessible to You</dt>
                <dd class="text-xl font-semibold text-text">{{ stats.accessible }}</dd>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3">
            <Icon name="exclamation" class="shrink-0 text-warning" />
            <div class="flex flex-col-reverse">
                <dt class="text-xs text-text-muted">Under Maintenance</dt>
                <dd class="text-xl font-semibold text-text">{{ stats.underMaintenance }}</dd>
            </div>
        </div>
        <div class="flex items-center gap-3 rounded-lg border border-border bg-surface px-4 py-3">
            <Icon name="clock" class="shrink-0 text-primary" />
            <div class="flex flex-col-reverse">
                <dt class="text-xs text-text-muted">{{ date() }}</dt>
                <dd class="text-base font-semibold text-text tabular-nums">
                    <time :datetime="now.toISOString()">{{ time() }}</time>
                </dd>
            </div>
        </div>
    </dl>
</template>
