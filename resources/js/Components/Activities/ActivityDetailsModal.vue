<script setup>
import { ref, watch } from 'vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import Skeleton from '@cscro8/ui/Components/UI/Skeleton.vue';
import { formatCalendarDate, formatDateTime } from '../../Utils/datetime';
import { activityStatusLabel, activityTone, monthName, timeText } from './activityDisplay';

/**
 * One activity's details, loaded from GET /activities/{id} when opened:
 * every field, its office and status, PALD's returned note and the move
 * history. Its office's posters (and PALD) get Edit, Move, Cancel and Mark
 * done; PALD also Return and Remove. The page runs each action.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    activityId: { type: Number, default: null },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'edit', 'move', 'cancel', 'done', 'return', 'remove']);

const activity = ref(null);
const loading = ref(false);
const error = ref('');

const load = async () => {
    activity.value = null;
    error.value = '';
    if (!props.open || !props.activityId) return;
    loading.value = true;
    const target = props.activityId;
    try {
        const response = await fetch(`/activities/${target}`, { headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!response.ok) throw new Error(String(response.status));
        const data = await response.json();
        if (target !== props.activityId) return;
        activity.value = data.activity;
    } catch {
        if (target === props.activityId) error.value = 'The activity could not be loaded.';
    } finally {
        if (target === props.activityId) loading.value = false;
    }
};
watch(() => [props.open, props.activityId], load, { immediate: true });

const when = (item) => {
    if (!item.starts_on) return `${monthName(item.year, item.month)} (date to be announced)`;
    const days = item.ends_on && item.ends_on !== item.starts_on ? `${formatCalendarDate(item.starts_on)} to ${formatCalendarDate(item.ends_on)}` : formatCalendarDate(item.starts_on);
    const time = timeText(item);
    return time ? `${days}, ${time}` : days;
};

const moveWhen = (move, side) => {
    const on = move[`${side}_starts_on`];
    if (!on) return 'month only';
    const until = move[`${side}_ends_on`];
    const startsAt = move[`${side}_starts_at`];
    const endsAt = move[`${side}_ends_at`];
    const time = startsAt ? timeText({ starts_at: startsAt, ends_at: endsAt }) : '';
    return `${formatCalendarDate(on)}${until && until !== on ? ` to ${formatCalendarDate(until)}` : ''}${time ? `, ${time}` : ''}`;
};

const open = (event) => emit(event, activity.value);
</script>

<template>
    <Modal :open="open" :title="activity ? activity.title : 'Activity'" tone="primary" icon="calendar" size="lg" @close="emit('close')">
        <div v-if="loading" role="status" aria-live="polite" class="space-y-3">
            <span class="sr-only">Loading the activity…</span>
            <Skeleton class="h-5 w-1/2" />
            <Skeleton class="h-4 w-3/4" />
            <Skeleton class="h-4 w-2/3" />
        </div>
        <Alert v-else-if="error" tone="danger">{{ error }}</Alert>
        <div v-else-if="activity" class="space-y-5">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <StatusBadge :status="activityTone(activity)" :label="activityStatusLabel(activity)" />
                    <StatusBadge v-if="activity.has_moved" status="info" label="Moved" />
                    <StatusBadge v-if="activity.unplanned" status="warning" label="Unplanned" />
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <template v-if="activity.can_update">
                        <ActionButton icon="pencil" label="Edit" @click="open('edit')" />
                        <ActionButton v-if="activity.status !== 'cancelled'" icon="calendar" label="Move to another date" @click="open('move')" />
                        <ActionButton v-if="activity.starts_on && activity.status === 'scheduled'" icon="check" label="Mark done" tone="success" @click="open('done')" />
                        <ActionButton v-if="activity.status !== 'cancelled' && activity.status !== 'done'" icon="xCircle" label="Cancel the activity" tone="warning" @click="open('cancel')" />
                    </template>
                    <template v-if="canManage">
                        <ActionButton icon="arrowLeft" label="Return to the office" tone="warning" @click="open('return')" />
                        <ActionButton icon="trash" label="Remove" tone="danger" @click="open('remove')" />
                    </template>
                </div>
            </div>

            <Alert v-if="activity.returned_note" tone="warning" title="Returned by PALD">{{ activity.returned_note }}</Alert>
            <Alert v-if="activity.status === 'cancelled' && activity.cancel_reason" tone="info" title="Cancelled">{{ activity.cancel_reason }}</Alert>

            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <dt class="text-xs font-medium text-text-muted">When</dt>
                    <dd class="text-text">{{ when(activity) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-text-muted">Office</dt>
                    <dd class="text-text">{{ activity.office?.name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-text-muted">Mode</dt>
                    <dd class="text-text">{{ activity.mode_label }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-text-muted">Venue</dt>
                    <dd class="text-text">{{ activity.venue || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-text-muted">Participants</dt>
                    <dd class="text-text">{{ activity.participants || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-text-muted">Responsible</dt>
                    <dd class="text-text">{{ activity.responsible || activity.office?.short_name || '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-text-muted">Posted by</dt>
                    <dd class="text-text">{{ activity.created_by || '—' }}</dd>
                </div>
                <div v-if="activity.remarks" class="sm:col-span-2">
                    <dt class="text-xs font-medium text-text-muted">Remarks</dt>
                    <dd class="whitespace-pre-line text-text">{{ activity.remarks }}</dd>
                </div>
                <div v-if="activity.series" class="sm:col-span-2">
                    <dt class="text-xs font-medium text-text-muted">Repeats</dt>
                    <dd class="text-text">{{ activity.series.frequency === 'daily' ? 'Every weekday' : activity.series.frequency === 'weekly' ? 'Weekly' : 'Monthly' }} until {{ formatCalendarDate(activity.series.until) }}</dd>
                </div>
            </dl>

            <section v-if="activity.moves?.length">
                <h3 class="mb-2 text-sm font-semibold text-text">Moves</h3>
                <ul class="divide-y divide-border rounded-md border border-border text-sm">
                    <li v-for="(move, index) in activity.moves" :key="index" class="px-3 py-2">
                        <div class="text-text">From {{ moveWhen(move, 'from') }} to {{ moveWhen(move, 'to') }}</div>
                        <div class="text-xs text-text-muted">
                            <template v-if="move.reason">{{ move.reason }} · </template>{{ move.moved_by ?? 'Someone' }}<template v-if="move.moved_at">, {{ formatDateTime(move.moved_at) }}</template>
                        </div>
                    </li>
                </ul>
            </section>
        </div>
    </Modal>
</template>
