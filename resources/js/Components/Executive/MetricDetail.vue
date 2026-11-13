<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Badge from '../UI/Badge.vue';
import Button from '../UI/Button.vue';
import Icon from '../UI/Icon.vue';
import Sparkline from './Sparkline.vue';
import { changeTone, formatChange, formatValue, plainStatus, targetProgress, targetStatuses } from './format';

/**
 * A figure in full, opened from its tile: the value and a plain-language
 * sentence about where it stands, the 30-day trend, progress toward the
 * target, the breakdown (usually by office) and a link into the module for
 * those who may open it.
 */
const props = defineProps({
    metric: { type: Object, default: null },
    sectionLabel: { type: String, default: null },
});

defineEmits(['close']);

const status = computed(() => (props.metric ? targetStatuses[props.metric.status] ?? null : null));
const progress = computed(() => (props.metric ? targetProgress(props.metric) : null));
const change = computed(() => (props.metric ? formatChange(props.metric.change?.value, props.metric.unit) : null));
const tone = computed(() => (props.metric ? changeTone(props.metric) : 'neutral'));
const since = computed(() => (props.metric?.change ? new Date(`${props.metric.change.since}T00:00:00`).toLocaleDateString(undefined, { month: 'long', day: 'numeric' }) : null));
const largest = computed(() => Math.max(...(props.metric?.breakdown ?? []).map((row) => row.value ?? 0), 0));
const rowUnit = computed(() => (props.metric?.unit === 'percent' ? 'count' : props.metric?.unit));
const barClasses = { on_track: 'bg-success', at_risk: 'bg-warning', behind: 'bg-danger' };
const changeClasses = { success: 'text-success-fg', danger: 'text-danger-fg', neutral: 'text-text-muted' };
</script>

<template>
    <Modal :open="metric !== null" :title="metric?.label ?? ''" icon="chartBar" size="lg" @close="$emit('close')">
        <div v-if="metric" class="space-y-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <p v-if="sectionLabel" class="text-xs font-semibold tracking-wide text-text-muted uppercase">{{ sectionLabel }}</p>
                    <p class="text-5xl leading-none font-bold tracking-tight text-text tabular-nums">{{ formatValue(metric.value, metric.unit) }}</p>
                    <p v-if="metric.note || metric.period" class="mt-2 text-sm text-text-muted">{{ [metric.note, metric.period].filter(Boolean).join(' · ') }}</p>
                </div>
                <Badge v-if="status" :tone="status.tone">{{ status.label }}</Badge>
            </div>

            <p class="rounded-lg bg-surface-muted px-4 py-3 text-sm text-text">{{ plainStatus(metric) }}</p>

            <div v-if="progress !== null">
                <div class="mb-1 flex justify-between text-xs font-medium text-text-muted">
                    <span>Progress toward target</span>
                    <span>{{ progress }}%</span>
                </div>
                <div class="h-3 overflow-hidden rounded-full bg-surface-muted" role="presentation">
                    <div class="h-full rounded-full" :class="barClasses[metric.status] ?? 'bg-primary'" :style="{ width: `${progress}%` }" />
                </div>
                <p v-if="metric.target.note" class="mt-1 text-xs text-text-muted">{{ metric.target.note }}</p>
            </div>

            <section v-if="metric.trend.length">
                <h3 class="mb-1 text-sm font-semibold text-text">Last 30 days</h3>
                <Sparkline :trend="metric.trend" :unit="metric.unit" tall />
                <p v-if="change" class="mt-1 text-sm" :class="changeClasses[tone]">{{ change }} since {{ since }}</p>
            </section>
            <p v-else class="text-sm text-text-muted">A trend appears after a few days of figures.</p>

            <section v-if="metric.breakdown.length">
                <h3 class="mb-2 text-sm font-semibold text-text">Breakdown</h3>
                <ul class="space-y-2.5">
                    <li v-for="row in metric.breakdown" :key="row.label">
                        <div class="flex justify-between gap-3 text-sm">
                            <span class="text-text">{{ row.label }}</span>
                            <span class="font-semibold text-text tabular-nums">{{ formatValue(row.value, rowUnit) }}</span>
                        </div>
                        <div class="mt-1 h-1.5 rounded-full bg-surface-muted" aria-hidden="true">
                            <div class="h-full rounded-full bg-csc-blue-light" :style="{ width: `${largest ? ((row.value ?? 0) / largest) * 100 : 0}%` }" />
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <template #footer>
            <Link v-if="metric?.href" :href="metric.href" class="mr-auto inline-flex items-center gap-1 text-sm font-medium text-link hover:underline">
                Open in the module
                <Icon name="chevronRight" size="sm" />
            </Link>
            <Button variant="secondary" @click="$emit('close')" icon="close">Close</Button>
        </template>
    </Modal>
</template>
