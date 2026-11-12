<script setup>
import { computed } from 'vue';
import Icon from '../UI/Icon.vue';
import { previewAccess } from '../../Utils/accessPreview';

/**
 * Admin-3: what a set of permissions opens, by area, plus the actions it
 * allows inside pages (previewAccess). Every signed-in person also has
 * the portal and their own HR pages, so those aren't listed.
 */
const props = defineProps({
    permissions: { type: Array, required: true /** permission names */ },
    superAdmin: { type: Boolean, default: false },
    labels: { type: Object, default: () => ({}) /** name → display name */ },
});

const preview = computed(() => previewAccess(props.permissions, { superAdmin: props.superAdmin, labels: props.labels }));
</script>

<template>
    <div class="space-y-4 text-sm">
        <p v-if="preview.superAdmin" class="rounded-md bg-warning-bg px-3 py-2 text-warning-fg">
            Super Administrator: every page and every action, whatever else is ticked.
        </p>

        <p class="text-text-muted">
            Besides what's listed, everyone signed in has the portal (Home, announcements, the directories) and their own
            HR pages.
        </p>

        <p v-if="!preview.areas.length && !preview.other.length" class="text-text">Nothing beyond that.</p>

        <section v-for="area in preview.areas" :key="area.area">
            <h3 class="text-xs font-semibold tracking-wide text-text-muted uppercase">{{ area.area }}</h3>
            <ul class="mt-1.5 grid gap-1 sm:grid-cols-2">
                <li v-for="page in area.pages" :key="page.href" class="flex items-center gap-2 text-text">
                    <Icon name="check" size="sm" class="shrink-0 text-success-fg" />
                    {{ page.label }}
                </li>
            </ul>
        </section>

        <section v-if="preview.other.length">
            <h3 class="text-xs font-semibold tracking-wide text-text-muted uppercase">Also allowed inside those pages</h3>
            <ul class="mt-1.5 flex flex-wrap gap-1.5">
                <li v-for="name in preview.other" :key="name" class="rounded-md bg-surface-muted px-2 py-0.5 text-xs text-text">{{ name }}</li>
            </ul>
        </section>
    </div>
</template>
