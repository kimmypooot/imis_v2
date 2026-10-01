<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import Badge from '../UI/Badge.vue';
import ExternalLinkIndicator from './ExternalLinkIndicator.vue';

/**
 * A system card in the division drill-down, with the legacy launch button
 * states. Every state is decided by the server-resolved fields from
 * ApplicationResource — `url` is already null when the viewer may not
 * launch — so this only presents them:
 *
 * - Access System [role]   url present (role badge, or "Guest" for open systems)
 * - Maintenance bypass     url present while under maintenance
 * - Under Maintenance      under maintenance, no url
 * - No Access              restricted and no role
 * - Not configured         no url set yet
 */
const props = defineProps({
    system: { type: Object, required: true },
});

const state = computed(() => {
    const { url, role, requires_access: restricted, under_maintenance: maintenance } = props.system;

    if (url && maintenance) return { kind: 'bypass', badge: role || 'Maintenance' };
    if (url) return { kind: 'launch', badge: role || (restricted ? null : 'Guest') };
    if (maintenance) return { kind: 'maintenance' };
    if (restricted && !role) return { kind: 'locked' };

    return { kind: 'unconfigured' };
});

const descriptionId = computed(() => `system-${props.system.id}-state`);
</script>

<template>
    <article class="flex h-full flex-col rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary/40">
        <div class="flex items-start gap-3">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                <Icon :name="system.icon || 'grid'" />
            </span>
            <div class="min-w-0 flex-1">
                <h3 class="font-medium text-text">{{ system.name }}</h3>
                <p v-if="system.short_name" class="text-xs text-text-muted">{{ system.short_name }}</p>
            </div>
        </div>

        <p v-if="system.description" class="mt-3 line-clamp-3 flex-1 text-sm text-text-muted">{{ system.description }}</p>
        <div v-else class="flex-1" />

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <a
                v-if="state.kind === 'launch' || state.kind === 'bypass'"
                :href="system.url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-2 rounded-md px-3 py-2 text-sm font-medium text-white focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
                :class="state.kind === 'bypass' ? 'bg-warning hover:opacity-90' : 'bg-primary hover:bg-primary-hover'"
                :aria-describedby="descriptionId"
            >
                {{ state.kind === 'bypass' ? 'Access (maintenance)' : 'Access System' }}
                <span v-if="state.badge" class="rounded bg-white/20 px-1.5 py-0.5 text-xs">{{ state.badge }}</span>
                <ExternalLinkIndicator />
            </a>

            <span
                v-else
                class="inline-flex cursor-not-allowed items-center gap-2 rounded-md border px-3 py-2 text-sm font-medium"
                :class="
                    state.kind === 'maintenance'
                        ? 'border-warning-border bg-warning-bg text-warning'
                        : 'border-border bg-surface-muted text-text-muted'
                "
                aria-disabled="true"
                :aria-describedby="descriptionId"
            >
                <Icon :name="state.kind === 'maintenance' ? 'exclamation' : 'lock'" size="sm" />
                {{ { maintenance: 'Under Maintenance', locked: 'No Access', unconfigured: 'Not configured yet' }[state.kind] }}
            </span>

            <Link
                :href="`/applications/${system.id}`"
                class="ml-auto text-sm font-medium text-primary hover:text-primary-hover"
                :aria-label="`Details about ${system.name}`"
            >
                Details
            </Link>
        </div>

        <!-- Why the button looks the way it does, for everyone (not only on hover). -->
        <p :id="descriptionId" class="mt-2 text-xs text-text-muted">
            <template v-if="state.kind === 'bypass'">Under maintenance — you can still open it because you manage maintenance.</template>
            <template v-else-if="state.kind === 'maintenance'">{{ system.maintenance_message || 'Temporarily unavailable. Please check back later.' }}</template>
            <template v-else-if="state.kind === 'locked'">You don't have a role in this system. Contact your administrator.</template>
            <template v-else-if="state.kind === 'unconfigured'">The link for this system hasn't been set up yet.</template>
            <template v-else-if="system.role">Your role: {{ system.role }}.</template>
            <template v-else>Open to all staff.</template>
        </p>
    </article>
</template>
