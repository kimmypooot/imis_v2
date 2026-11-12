<script setup>
import { computed } from 'vue';
import { titleCase } from '../../Utils/text';
import Badge from './Badge.vue';

/**
 * The legacy `status-badge`: a Badge as a pill with a leading dot. Pass the
 * raw status value; known values get their tone (shared with Badge, see
 * tones.js), anything else is neutral grey.
 * The word is always shown, so color is never the only signal.
 */
const props = defineProps({
    status: { type: String, required: true },
    label: { type: String, default: null },
});

const statusTones = {
    active: 'success',
    published: 'success',
    success: 'success',
    logged_out: 'neutral',
    inactive: 'danger',
    expired: 'warning',
    maintenance: 'warning',
    draft: 'neutral',
    warning: 'warning',
    critical: 'danger',
    info: 'info',
};

const tone = computed(() => statusTones[props.status] ?? 'neutral');
// Statuses read as Title Case ("Returned for Correction"), whatever the source.
const text = computed(() => titleCase(props.label ?? props.status.replace(/_/g, ' ')));
</script>

<template>
    <Badge :tone="tone" pill dot>{{ text }}</Badge>
</template>
