<script setup>
import Card from '../UI/Card.vue';
import StatusBadge from '../UI/StatusBadge.vue';
import Icon from '../UI/Icon.vue';
import { formatCalendarDate } from '../../Utils/datetime';

/**
 * HR-1b: an employee's movements between offices, newest first, with
 * where they are today. Read-only unless `canManage` (HR), which adds
 * edit / remove per entry. Shared by the HR record page and "My
 * Employee Record".
 */
defineProps({
    assignments: { type: Object, required: true /** { entries, current } from EmployeeAssignments::timeline() */ },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(['edit', 'remove']);

const date = (value) => (value ? formatCalendarDate(value) : null);

const period = (entry) => {
    const start = date(entry.effective_date);
    if (entry.kind === 'return') return `From ${start}`;
    if (!entry.end_date) return `${start} – ${entry.upcoming ? '' : 'present'}`.trim();
    return `${start} – ${date(entry.end_date)}`;
};

const tones = { reassignment: 'info', detail: 'warning', designation: 'draft', return: 'logged_out' };
</script>

<template>
    <Card title="Assignment history" icon="building" description="Reassignments move the office; details and designations keep the mother unit.">
        <template v-if="$slots.actions" #actions><slot name="actions" /></template>

        <dl class="mb-5 grid gap-3 rounded-md bg-surface-muted p-4 text-sm sm:grid-cols-3">
            <div>
                <dt class="text-text-muted">Office (mother unit)</dt>
                <dd class="mt-0.5 font-medium text-text">{{ assignments.current.office || '—' }}</dd>
            </div>
            <div>
                <dt class="text-text-muted">Detailed to</dt>
                <dd class="mt-0.5 font-medium text-text">
                    <template v-if="assignments.current.details.length">
                        <span v-for="detail in assignments.current.details" :key="detail.id" class="block">
                            {{ detail.to_unit }}<span v-if="detail.end_date" class="font-normal text-text-muted"> until {{ date(detail.end_date) }}</span>
                        </span>
                    </template>
                    <template v-else>—</template>
                </dd>
            </div>
            <div>
                <dt class="text-text-muted">Designations</dt>
                <dd class="mt-0.5 font-medium text-text">
                    <template v-if="assignments.current.designations.length">
                        <span v-for="designation in assignments.current.designations" :key="designation.id" class="block">{{ designation.to_unit }}<template v-if="designation.as_head"> ({{ designation.head_capacity_label }})</template></span>
                    </template>
                    <template v-else>—</template>
                </dd>
            </div>
        </dl>

        <p v-if="assignments.entries.length === 0" class="text-sm text-text-muted">No movements recorded.</p>

        <ol v-else class="relative space-y-4 border-l-2 border-border pl-5">
            <li v-for="entry in assignments.entries" :key="entry.id" class="relative">
                <span
                    class="absolute -left-[1.6rem] top-1.5 h-3 w-3 rounded-full ring-4 ring-surface"
                    :class="entry.current ? 'bg-primary' : entry.upcoming ? 'bg-warning' : 'bg-border'"
                    aria-hidden="true"
                />
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <StatusBadge :status="tones[entry.kind]" :label="entry.kind_label" />
                            <StatusBadge v-if="entry.as_head" status="info" :label="entry.head_capacity_label" />
                            <StatusBadge v-if="entry.current" status="active" label="Current" />
                            <StatusBadge v-else-if="entry.upcoming" status="warning" label="Upcoming" />
                        </div>
                        <p class="mt-1 text-sm font-medium text-text">
                            <template v-if="entry.from_unit && entry.kind !== 'designation'">{{ entry.from_unit }} → </template>{{ entry.to_unit }}
                        </p>
                        <p class="text-xs text-text-muted">
                            {{ period(entry) }}<template v-if="entry.end_is_derived"> (until the next reassignment)</template>
                        </p>
                        <p v-if="entry.order_number || entry.order_date" class="mt-1 text-xs text-text-muted">
                            Order {{ entry.order_number ? `No. ${entry.order_number}` : '' }}<template v-if="entry.order_date"> dated {{ date(entry.order_date) }}</template>
                            <a v-if="entry.scan" :href="entry.scan.url" target="_blank" rel="noopener" class="ml-1 inline-flex items-center gap-1 text-link hover:underline">
                                <Icon name="eye" size="sm" />View scan
                            </a>
                        </p>
                        <p v-else-if="entry.scan" class="mt-1 text-xs">
                            <a :href="entry.scan.url" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-link hover:underline"><Icon name="eye" size="sm" />View scanned order</a>
                        </p>
                        <p v-if="entry.supervision_handed_at" class="mt-1 text-xs text-text-muted">
                            The office's staff were handed over to them as immediate supervisor<template v-if="entry.supervision_restored_at">, and given back their previous supervisors when it ended</template>.
                        </p>
                        <p v-if="entry.remarks" class="mt-1 whitespace-pre-line text-sm text-text">{{ entry.remarks }}</p>
                    </div>
                    <div v-if="canManage" class="flex shrink-0 gap-3 text-sm">
                        <button type="button" class="text-link hover:underline" @click="emit('edit', entry)">
                            Edit<span class="sr-only"> {{ entry.kind_label }} effective {{ date(entry.effective_date) }}</span>
                        </button>
                        <button type="button" class="text-danger-fg hover:underline" @click="emit('remove', entry)">
                            Remove<span class="sr-only"> {{ entry.kind_label }} effective {{ date(entry.effective_date) }}</span>
                        </button>
                    </div>
                </div>
            </li>
        </ol>
    </Card>
</template>
