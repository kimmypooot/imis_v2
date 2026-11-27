<script setup>
import { computed, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import BusyRegion from '@cscro8/ui/Components/UI/BusyRegion.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import FilterButtons from '@cscro8/ui/Components/UI/FilterButtons.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import { useBusy } from '../../Composables/useBusy';
import { formatCalendarDate, formatDateTime } from '../../Utils/datetime';
import { monthName, timeText } from '../../Components/Activities/activityDisplay';

defineOptions({ layout: PortalLayout });

/**
 * Calendar of Activities, PALD's management page: what changed lately
 * (from the audit log), clashes in a month, plan versus actual for a year
 * by office, the year's plan cut-off, and the year's removed activities.
 */
const props = defineProps({
    // "What changed" covers the last 7 or 30 days.
    days: { type: Number, required: true },
    // The year for plan vs actual, the cut-off and the removed list.
    year: { type: Number, required: true },
    // The month (YYYY-MM) for clashes.
    month: { type: String, required: true },
    changes: { type: Array, required: true },
    clashes: { type: Array, required: true },
    planVersusActual: { type: Array, required: true },
    cutoff: { type: String, required: true },
    cutoffIsDefault: { type: Boolean, default: false },
    removed: { type: Array, required: true },
});

const { busy, handlers } = useBusy();
const visit = (query) => {
    router.get('/activities/manage', { days: props.days, year: props.year, month: props.month, ...query }, { preserveScroll: true, preserveState: true, ...handlers });
};

const daysOptions = [
    { value: 7, label: 'Last 7 days' },
    { value: 30, label: 'Last 30 days' },
];
const thisYear = new Date().getFullYear();
const yearOptions = computed(() => {
    const years = new Set([thisYear - 2, thisYear - 1, thisYear, thisYear + 1, thisYear + 2, props.year]);
    return [...years].sort((a, b) => a - b).map((value) => ({ value: String(value), label: String(value) }));
});
// Visits keep the page's state, so anything read from props stays computed.
const clashTitle = computed(() => `Clashes, ${monthName(...props.month.split('-').map(Number))}`);

const changeColumns = [
    { key: 'when', label: 'When' },
    { key: 'event', label: 'What' },
    { key: 'description', label: 'Details' },
    { key: 'office', label: 'Office', value: (row) => row.office ?? '' },
    { key: 'who', label: 'Who', value: (row) => row.who ?? '' },
    { key: 'link', label: '', sortable: false, align: 'right' },
];
const planColumns = [
    { key: 'office', label: 'Office' },
    { key: 'planned', label: 'Planned', align: 'right' },
    { key: 'done', label: 'Done', align: 'right' },
    { key: 'cancelled', label: 'Cancelled', align: 'right' },
    { key: 'moved', label: 'Moved', align: 'right' },
    { key: 'unplanned', label: 'Unplanned', align: 'right' },
    { key: 'open', label: 'Open', align: 'right' },
];
const removedColumns = [
    { key: 'title', label: 'Activity' },
    { key: 'office', label: 'Office', value: (row) => row.office ?? '' },
    { key: 'date', label: 'Date' },
    { key: 'reason', label: 'Reason', value: (row) => row.reason ?? '' },
    { key: 'removed_at', label: 'Removed', value: (row) => row.removed_at ?? '' },
];

const totals = computed(() =>
    ['planned', 'done', 'cancelled', 'moved', 'unplanned', 'open'].reduce((sum, key) => ({ ...sum, [key]: props.planVersusActual.reduce((total, row) => total + row[key], 0) }), {}),
);

// A date (YYYY-MM-DD) or a month only (YYYY-MM).
const dateText = (value) => (value && value.length === 7 ? monthName(...value.split('-').map(Number)) : formatCalendarDate(value));
const clashWhen = (activity) => {
    const span = activity.ends_on && activity.ends_on !== activity.starts_on ? `${formatCalendarDate(activity.starts_on)} – ${formatCalendarDate(activity.ends_on)}` : formatCalendarDate(activity.starts_on);
    const time = timeText(activity);
    return time ? `${span}, ${time}` : span;
};
const clashLink = (activity) => `/activities?month=${activity.starts_on.slice(0, 7)}`;

const cutoffForm = useForm({ year: props.year, cuts_off_on: props.cutoff });
watch(
    () => [props.year, props.cutoff],
    () => {
        cutoffForm.defaults({ year: props.year, cuts_off_on: props.cutoff });
        cutoffForm.reset();
        cutoffForm.clearErrors();
    },
);
const saveCutoff = () => cutoffForm.put('/activities/cutoff', { preserveScroll: true });
</script>

<template>
    <Head title="Manage Calendar of Activities" />

    <PageHeader
        title="Manage Calendar of Activities"
        description="What changed, clashes, plan versus actual and the plan cut-off."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Calendar of Activities', href: '/activities' }, { label: 'Manage' }]"
    >
        <template #actions>
            <Button variant="secondary" icon="calendar" href="/activities">Open the calendar</Button>
        </template>
    </PageHeader>

    <BusyRegion :busy="busy" class="space-y-6">
        <Card title="What changed" icon="clipboard" description="Activities added, edited, moved, cancelled, done, returned or removed, newest first.">
            <template #actions>
                <FilterButtons :model-value="days" label="Show" variant="segmented" :options="daysOptions" @update:model-value="(value) => visit({ days: value })" />
            </template>
            <DataTable
                :columns="changeColumns"
                :rows="changes"
                :caption="`Calendar of Activities changes in the last ${days} days`"
                :numbered="false"
                :exportable="false"
                :default-page-size="25"
                empty-text="Nothing changed in this period."
            >
                <template #cell-when="{ row }">
                    <span class="whitespace-nowrap text-text-muted">{{ formatDateTime(row.when) }}</span>
                </template>
                <template #cell-event="{ row }">
                    <span class="font-medium text-text">{{ row.event }}</span>
                </template>
                <template #cell-office="{ row }">{{ row.office ?? '—' }}</template>
                <template #cell-who="{ row }">{{ row.who ?? '—' }}</template>
                <template #cell-link="{ row }">
                    <Link v-if="row.link" :href="row.link" class="whitespace-nowrap text-sm font-medium text-link hover:underline">Open month</Link>
                </template>
            </DataTable>
        </Card>

        <Card :title="clashTitle" icon="exclamation" description="Same venue or same office at an overlapping date and time. A warning only; the offices decide.">
            <template #actions>
                <div class="w-44">
                    <TextInput id="clash-month" type="month" label="Month" :model-value="month" @update:model-value="(value) => value && visit({ month: value })" />
                </div>
            </template>
            <ul v-if="clashes.length" class="divide-y divide-border">
                <li v-for="pair in clashes" :key="pair.activity.id" class="py-3 text-sm">
                    <p class="text-text">
                        <Link :href="clashLink(pair.activity)" class="font-medium text-link hover:underline">“{{ pair.activity.title }}”</Link>
                        <span class="text-text-muted"> — {{ clashWhen(pair.activity) }}<template v-if="pair.activity.venue">, {{ pair.activity.venue }}</template><template v-if="pair.activity.office"> ({{ pair.activity.office }})</template></span>
                    </p>
                    <p class="mt-1 text-xs font-medium uppercase tracking-wide text-warning-fg">Clashes with</p>
                    <ul class="mt-1 list-disc space-y-0.5 pl-5">
                        <li v-for="other in pair.with" :key="other.id" class="text-text">
                            “{{ other.title }}”
                            <span class="text-text-muted"> — {{ clashWhen(other) }}<template v-if="other.venue">, {{ other.venue }}</template><template v-if="other.office"> ({{ other.office }})</template></span>
                        </li>
                    </ul>
                </li>
            </ul>
            <EmptyState v-else icon="check" title="No clashes this month" />
        </Card>

        <div class="flex flex-wrap items-end gap-3 rounded-md bg-surface-muted p-3">
            <div class="min-w-32">
                <label for="manage-year" class="mb-1 block text-xs font-medium text-text-muted">Year</label>
                <Select id="manage-year" :model-value="String(year)" :options="yearOptions" @update:model-value="(value) => visit({ year: value })" />
            </div>
            <p class="pb-2 text-xs text-text-muted">Plan vs actual, the plan cut-off and the removed list are for this year.</p>
        </div>

        <div class="grid gap-6 xl:grid-cols-3">
            <Card :title="`Plan vs actual, ${year}`" icon="chartBar" class="xl:col-span-2" description="Removed activities are not counted. Open: neither done nor cancelled.">
                <DataTable
                    :columns="planColumns"
                    :rows="planVersusActual"
                    row-key="office"
                    :caption="`Plan versus actual by office, ${year}`"
                    :export-name="`calendar-of-activities-plan-vs-actual-${year}`"
                    :numbered="false"
                    :searchable="false"
                    :page-size-select="false"
                    :default-page-size="100"
                    empty-text="No activities for this year yet."
                >
                    <template #cell-office="{ row }">
                        <span class="font-medium text-text">{{ row.office }}</span>
                    </template>
                </DataTable>
                <p v-if="planVersusActual.length > 1" class="mt-3 text-sm text-text-muted">
                    All offices: {{ totals.planned }} planned, {{ totals.done }} done, {{ totals.cancelled }} cancelled, {{ totals.moved }} moved, {{ totals.unplanned }} unplanned, {{ totals.open }} open.
                </p>
            </Card>

            <Card :title="`Plan cut-off, ${year}`" icon="calendar" description="Activities added after this date are flagged unplanned. Ones already saved keep their flag.">
                <form class="space-y-4" @submit.prevent="saveCutoff">
                    <TextInput
                        id="cutoff-date"
                        v-model="cutoffForm.cuts_off_on"
                        type="date"
                        label="Cut-off date"
                        required
                        :min="`${year}-01-01`"
                        :max="`${year}-12-31`"
                        :helper-text="cutoffIsDefault ? 'Not set yet; the default is 31 January.' : null"
                        :error="cutoffForm.errors.cuts_off_on ?? cutoffForm.errors.year"
                    />
                    <Button type="submit" :loading="cutoffForm.processing" :disabled="!cutoffForm.isDirty && !cutoffIsDefault">Save cut-off</Button>
                </form>
            </Card>
        </div>

        <Card :title="`Removed, ${year}`" icon="trash" description="Activities PALD removed, with the reason. The office was told.">
            <DataTable
                :columns="removedColumns"
                :rows="removed"
                :caption="`Removed activities, ${year}`"
                :numbered="false"
                :exportable="false"
                :default-page-size="10"
                empty-text="No activities were removed this year."
            >
                <template #cell-title="{ row }">
                    <span class="font-medium text-text">{{ row.title }}</span>
                </template>
                <template #cell-office="{ row }">{{ row.office ?? '—' }}</template>
                <template #cell-date="{ row }">
                    <span class="whitespace-nowrap">{{ dateText(row.date) }}</span>
                </template>
                <template #cell-removed_at="{ row }">
                    <span class="whitespace-nowrap text-text-muted">{{ formatDateTime(row.removed_at) }}</span>
                    <span v-if="row.removed_by" class="block text-xs text-text-muted">by {{ row.removed_by }}</span>
                </template>
            </DataTable>
        </Card>
    </BusyRegion>
</template>
