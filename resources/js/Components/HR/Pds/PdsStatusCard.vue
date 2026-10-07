<script setup>
import { computed } from 'vue';
import Card from '../../UI/Card.vue';
import StatusBadge from '../../UI/StatusBadge.vue';
import { formatDateTime } from '../../../Utils/datetime';
import { pdsStates } from './pdsStates';

/**
 * Where a PDS stands (PdsSubmissions::status): state, what it means,
 * HR's remarks when returned, and when it was last verified. Actions
 * (sign, print) come in through the default slot.
 */
const props = defineProps({
    status: { type: Object, required: true },
    // HR sees the same states described about the employee, not "you".
    forHr: { type: Boolean, default: false },
});

const state = computed(() => pdsStates[props.status.state]);

const text = computed(() =>
    props.forHr
        ? {
              draft: 'The employee hasn\'t signed and submitted their PDS yet.',
              submitted: 'Signed and awaiting verification.',
              changed_since_submitted: 'The employee changed their PDS after submitting it.',
              returned: 'Returned to the employee for changes.',
              verified: 'Verified and filed in the 201 file.',
              changed_since_verified: 'The employee changed their PDS after it was verified.',
          }[props.status.state]
        : state.value.text,
);
</script>

<template>
    <Card title="Status" icon="checkCircle">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1">
                <StatusBadge :status="state.badge" :label="state.label" />
                <p class="text-sm text-text">{{ text }}</p>
                <p v-if="status.last_verified_at" class="text-xs text-text-muted">
                    Last verified {{ formatDateTime(status.last_verified_at) }}
                </p>
                <div v-if="status.state === 'returned' && status.latest?.remarks" class="mt-2 rounded-md border border-danger-border bg-danger-bg px-3 py-2 text-sm text-text">
                    <p class="text-xs font-semibold text-danger uppercase">HR's remarks<template v-if="status.latest.reviewer"> — {{ status.latest.reviewer }}</template></p>
                    <p class="mt-1 whitespace-pre-line">{{ status.latest.remarks }}</p>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                <slot :can-sign="state.canSign" />
            </div>
        </div>
    </Card>
</template>
