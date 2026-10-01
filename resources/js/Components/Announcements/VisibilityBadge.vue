<script setup>
import { computed } from 'vue';
import Badge from '../UI/Badge.vue';

/**
 * Shows the server-computed `visibility_state` (Announcement::visibilityState())
 * — whether an announcement is actually on the portal right now, which
 * "published" status alone doesn't answer.
 */
const props = defineProps({
    state: {
        type: String,
        required: true,
    },
});

const variants = {
    live: { tone: 'success', label: 'Live' },
    scheduled: { tone: 'info', label: 'Scheduled' },
    expired: { tone: 'warning', label: 'Expired' },
    draft: { tone: 'neutral', label: 'Draft' },
    archived: { tone: 'neutral', label: 'Archived' },
};

const variant = computed(() => variants[props.state] ?? variants.draft);
</script>

<template>
    <Badge :tone="variant.tone">{{ variant.label }}</Badge>
</template>
