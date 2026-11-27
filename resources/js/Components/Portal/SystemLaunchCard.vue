<script setup>
import { computed } from 'vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import ExternalLinkIndicator from './ExternalLinkIndicator.vue';

/**
 * A system card in the division drill-down, after the legacy dashboard's
 * system card: the icon beside the name, the viewer's role as a badge under
 * it, and the green full-width "Access System" button at the bottom (slim,
 * always one line, the role kept out of it). Every state is decided by the
 * server-resolved fields from ApplicationResource — `url` is already null
 * when the viewer may not launch — so this only presents them:
 *
 * - Access System + role badge   url present (role, or "Guest" for open systems)
 * - Access + Maintenance access  url present while under maintenance (bypass)
 * - Under Maintenance            under maintenance, no url
 * - No Access                    restricted and no role
 * - Not configured yet           no url set yet
 */
const props = defineProps({
    system: { type: Object, required: true },
});

const state = computed(() => {
    const { url, role, requires_access: restricted, under_maintenance: maintenance } = props.system;

    if (url && maintenance) return { kind: 'bypass', badge: 'Maintenance' };
    if (url) return { kind: 'launch', badge: role || (restricted ? null : 'Guest') };
    if (maintenance) return { kind: 'maintenance' };
    if (restricted && !role) return { kind: 'locked' };

    return { kind: 'unconfigured' };
});

const launchable = computed(() => state.value.kind === 'launch' || state.value.kind === 'bypass');

// One line, 36px tall: the role is shown under the name instead.
const button = 'flex min-h-9 w-full items-center justify-center gap-2 rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap';
const disabledLabels = { maintenance: 'Under Maintenance', locked: 'No Access', unconfigured: 'Not configured yet' };
</script>

<template>
    <!-- Not itself clickable (the button below is), so only a faint hover tint. -->
    <article class="flex h-full rounded-lg border border-border bg-surface card-interactive hover:border-success/50">
        <div class="flex w-full flex-col gap-2 p-4">
            <div class="flex items-center gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-success-bg text-success-fg" aria-hidden="true">
                    <Icon :name="system.icon || 'grid'" size="sm" />
                </span>
                <div class="min-w-0">
                    <h3 class="text-base leading-snug font-semibold text-text">
                        <span
                            v-if="state.kind === 'launch'"
                            class="mr-1.5 mb-0.5 inline-block h-2 w-2 rounded-full bg-success ring-2 ring-success/25 align-middle"
                            aria-hidden="true"
                        />
                        {{ system.name }}
                    </h3>
                    <span
                        v-if="state.badge"
                        class="mt-0.5 inline-block max-w-full truncate rounded-full px-2 py-px align-top text-2xs leading-snug font-semibold tracking-wide uppercase"
                        :class="state.kind === 'bypass' ? 'bg-warning-bg text-warning-fg' : 'bg-success-bg text-success-fg'"
                        :title="state.kind === 'bypass' ? 'Under maintenance; you can still open it' : `Your role: ${state.badge}`"
                    >
                        <span class="sr-only">{{ state.kind === 'bypass' ? 'Status:' : 'Your role:' }}</span>
                        {{ state.kind === 'bypass' ? 'Maintenance access' : state.badge }}
                    </span>
                </div>
            </div>

            <p v-if="system.description" class="line-clamp-2 flex-1 text-sm leading-snug text-text-muted">
                {{ system.description }}
            </p>
            <div v-else class="flex-1" />

            <a
                v-if="launchable"
                :href="`/applications/${system.id}/launch`"
                target="_blank"
                rel="noopener noreferrer"
                class="mt-2 text-white transition focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 motion-safe:hover:-translate-y-0.5 hover:shadow-md"
                :class="[button, state.kind === 'bypass' ? 'bg-bypass hover:bg-bypass-hover' : 'bg-success hover:bg-success-hover']"
            >
                <Icon v-if="state.kind === 'bypass'" name="exclamation" size="sm" />
                {{ state.kind === 'bypass' ? 'Access' : 'Access System' }}
                <span class="sr-only">{{ system.name }}</span>
                <ExternalLinkIndicator />
            </a>

            <span
                v-else
                class="mt-2 cursor-not-allowed"
                :class="[button, state.kind === 'maintenance' ? 'bg-danger text-white' : 'border border-border bg-surface-muted text-text-muted']"
                aria-disabled="true"
            >
                <Icon :name="state.kind === 'maintenance' ? 'exclamation' : 'lock'" size="sm" />
                {{ disabledLabels[state.kind] }}
            </span>
        </div>
    </article>
</template>
