<script setup>
import { computed } from 'vue';
import Icon from '../UI/Icon.vue';
import { daysSince } from '../../Utils/datetime';

/**
 * The headline of an approver's queue: how many are waiting, how long the
 * oldest has waited, and how many have waited past the "late" mark. All
 * clear when nothing is waiting. Shared by Leave and Payslip Approvals.
 */
const props = defineProps({
    // ISO timestamps of when each waiting item arrived.
    since: { type: Array, required: true },
    noun: { type: String, required: true /** e.g. "leave application" */ },
    // Days after which an item counts as late.
    lateAfter: { type: Number, default: 3 },
});

const count = computed(() => props.since.length);
const ages = computed(() => props.since.map(daysSince));
const oldest = computed(() => Math.max(0, ...ages.value));
const late = computed(() => ages.value.filter((age) => age >= props.lateAfter).length);
const plural = computed(() => (count.value === 1 ? props.noun : `${props.noun}s`));
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-lg border p-5"
        :class="count ? (late ? 'border-warning-border bg-warning-bg' : 'border-info-border bg-info-bg') : 'border-success-border bg-success-bg'"
    >
        <span
            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-surface"
            :class="count ? (late ? 'text-warning' : 'text-primary') : 'text-success'"
            aria-hidden="true"
        >
            <Icon :name="count ? 'clock' : 'check'" size="lg" />
        </span>
        <div class="min-w-0 flex-1">
            <p class="text-2xl leading-none font-semibold text-text tabular-nums">
                {{ count ? count : 'All clear' }}
                <span v-if="count" class="text-sm font-normal text-text-muted">{{ plural }} waiting for you</span>
            </p>
            <p class="mt-1.5 text-sm text-text-muted">
                <template v-if="count">
                    Oldest has waited <span class="font-medium text-text">{{ oldest === 0 ? 'less than a day' : `${oldest} day${oldest === 1 ? '' : 's'}` }}</span>.
                </template>
                <template v-else>Nothing is waiting for your action.</template>
            </p>
        </div>
        <p v-if="late" class="flex items-center gap-2 rounded-full bg-surface px-3.5 py-1.5 text-sm font-medium text-warning" role="status">
            <Icon name="exclamation" size="sm" />{{ late }} waiting {{ lateAfter }}+ days
        </p>
    </div>
</template>
