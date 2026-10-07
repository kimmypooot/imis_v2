<script setup>
import { computed } from 'vue';
import Icon from '../UI/Icon.vue';
import ExternalLinkIndicator from './ExternalLinkIndicator.vue';

/**
 * A system card in the division drill-down, styled after the legacy
 * dashboard's system card (icon beside the details, full-width launch
 * button at the bottom, green accent on hover). Every state is decided by
 * the server-resolved fields from ApplicationResource — `url` is already
 * null when the viewer may not launch — so this only presents them:
 *
 * - Access System [role]   url present (role badge, or "Guest" for open systems)
 * - Access [Maintenance]   url present while under maintenance (bypass)
 * - Under Maintenance      under maintenance, no url
 * - No Access              restricted and no role
 * - Not configured         no url set yet
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

const button =
    'flex min-h-11 w-full items-center justify-center gap-2 rounded-lg px-4 py-2.5 text-sm font-medium whitespace-nowrap';
</script>

<template>
    <!-- Not itself clickable (the button below is), so no hover effect on the card. -->
    <article class="flex h-full rounded-lg border border-border bg-surface">
        <div class="flex w-full gap-3.5 p-5">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-lg bg-success-bg text-success" aria-hidden="true">
                <Icon :name="system.icon || 'grid'" />
            </span>

            <div class="flex min-w-0 flex-1 flex-col gap-1 pt-1.5">
                <h3 class="text-base leading-snug font-semibold text-text">
                    <!-- Legacy "online" dot: shown only when the system can be opened. -->
                    <span
                        v-if="state.kind === 'launch'"
                        class="mr-1.5 mb-0.5 inline-block h-2 w-2 rounded-full bg-success ring-2 ring-success/25 align-middle"
                        aria-hidden="true"
                    />
                    {{ system.name }}
                </h3>

                <p v-if="system.description" class="line-clamp-2 flex-1 text-sm leading-snug text-text-muted">
                    {{ system.description }}
                </p>
                <div v-else class="flex-1" />

                <a
                    v-if="launchable"
                    :href="`/applications/${system.id}/launch`"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mt-3 text-white transition focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 motion-safe:hover:-translate-y-0.5 hover:shadow-md"
                    :class="[button, state.kind === 'bypass' ? 'bg-bypass hover:bg-bypass-hover' : 'bg-success hover:bg-success-hover']"
                >
                    <Icon v-if="state.kind === 'bypass'" name="exclamation" size="sm" />
                    {{ state.kind === 'bypass' ? 'Access' : 'Access System' }}
                    <span
                        v-if="state.badge"
                        class="rounded-full bg-white/20 px-2 py-px text-xs leading-snug font-semibold tracking-wide uppercase"
                    >
                        {{ state.badge }}
                    </span>
                    <ExternalLinkIndicator />
                </a>

                <span
                    v-else
                    class="mt-3 cursor-not-allowed"
                    :class="[
                        button,
                        state.kind === 'maintenance'
                            ? 'bg-danger text-white'
                            : 'border-[1.5px] border-border bg-surface-muted text-text-muted',
                    ]"
                    aria-disabled="true"
                >
                    <Icon :name="state.kind === 'maintenance' ? 'exclamation' : 'lock'" size="sm" />
                    {{ { maintenance: 'Under Maintenance', locked: 'No Access', unconfigured: 'Not configured yet' }[state.kind] }}
                </span>
            </div>
        </div>
    </article>
</template>
