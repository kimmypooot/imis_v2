<script setup>
import Icon from '../UI/Icon.vue';

/**
 * A strip of key facts at the top of a self-service HR page (position,
 * office, years of service, document counts…). Each fact has an icon,
 * a small label and a value; a missing value shows as a dash.
 */
defineProps({
    items: { type: Array, required: true /** [{ icon, label, value, hint? }] */ },
});
</script>

<template>
    <dl class="grid grid-cols-2 gap-4 lg:grid-cols-4">
        <div v-for="item in items" :key="item.label" class="flex items-center gap-3.5 rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary/40">
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-info-bg text-link" aria-hidden="true">
                <Icon :name="item.icon" />
            </span>
            <div class="min-w-0">
                <dt class="text-xs text-text-muted">{{ item.label }}</dt>
                <dd class="truncate text-sm font-semibold text-text" :title="String(item.value ?? '')">{{ item.value || '—' }}</dd>
                <dd v-if="item.hint" class="truncate text-xs text-text-muted">{{ item.hint }}</dd>
            </div>
        </div>
    </dl>
</template>
