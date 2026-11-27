<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import BusyRegion from '@cscro8/ui/Components/UI/BusyRegion.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Tooltip from '@cscro8/ui/Components/UI/Tooltip.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import FilterButtons from '@cscro8/ui/Components/UI/FilterButtons.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import MonthGrid from '../../Components/Calendar/MonthGrid.vue';
import ActivityDetailsModal from '../../Components/Activities/ActivityDetailsModal.vue';
import ActivityForm from '../../Components/Activities/ActivityForm.vue';
import MoveActivityModal from '../../Components/Activities/MoveActivityModal.vue';
import ReasonModal from '../../Components/Activities/ReasonModal.vue';
import { useBusy } from '../../Composables/useBusy';
import { formatCalendarDate } from '../../Utils/datetime';
import { activityChipClass, activityOn, activityStatusLabel, activityTone, shortTime, timeText } from '../../Components/Activities/activityDisplay';

defineOptions({ layout: PortalLayout });

/**
 * Calendar of Activities: the month as a calendar or as the monthly sheet
 * (the printed layout), for every office or one, and by mode. Posters add
 * and keep their office's activities; PALD any office's, and may return or
 * remove one. Saving warns about clashes without blocking.
 */
const props = defineProps({
    month: { type: String, required: true },
    view: { type: String, required: true },
    filters: { type: Object, required: true },
    // The office the filter resolves to (for Print and Excel), or null for all.
    officeId: { type: Number, default: null },
    myOfficeId: { type: Number, default: null },
    activities: { type: Array, required: true },
    // MonthSheet::build() in the list view, else null.
    sheet: { type: Object, default: null },
    holidays: { type: Array, required: true },
    offices: { type: Array, required: true },
    postOffices: { type: Array, required: true },
    modeOptions: { type: Array, required: true },
    canPost: { type: Boolean, default: false },
    canManage: { type: Boolean, default: false },
    clashes: { type: Array, default: () => [] },
});

const MAX_PER_DAY = 3;

const pad = (n) => String(n).padStart(2, '0');
const todayDate = new Date();
const today = `${todayDate.getFullYear()}-${pad(todayDate.getMonth() + 1)}-${pad(todayDate.getDate())}`;
const [year, monthNumber] = props.month.split('-').map(Number);
const title = new Date(year, monthNumber - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });

// Navigation keeps the filters; empty ones are left out of the URL.
const { busy, handlers } = useBusy();
const visit = (changes) => {
    const query = { month: props.month, view: props.view, office: props.filters.office, mode: props.filters.mode, ...changes };
    router.get('/activities', Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== '' && value !== undefined)), {
        preserveScroll: true,
        ...handlers,
    });
};
const shift = (by) => {
    const target = new Date(year, monthNumber - 1 + by, 1);
    visit({ month: `${target.getFullYear()}-${pad(target.getMonth() + 1)}` });
};

const viewOptions = [
    { value: 'grid', label: 'Calendar' },
    { value: 'list', label: 'List' },
];
const officeOptions = computed(() => [
    { value: '', label: 'All offices' },
    ...(props.myOfficeId ? [{ value: 'mine', label: 'My office' }] : []),
    ...props.offices.map((office) => ({ value: String(office.id), label: office.short_name ? `${office.short_name} — ${office.name}` : office.name })),
]);
const modeFilterOptions = computed(() => [{ value: '', label: 'All modes' }, ...props.modeOptions]);

const sheetQuery = computed(() => {
    const query = new URLSearchParams({ month: props.month });
    if (props.officeId) query.set('office', String(props.officeId));
    if (props.filters.mode) query.set('mode', props.filters.mode);
    return query.toString();
});

// Calendar view: activities by day; the chosen day is listed below the grid.
const dated = computed(() => props.activities.filter((activity) => activity.starts_on));
const monthOnly = computed(() => props.activities.filter((activity) => !activity.starts_on));
const activitiesOn = (iso) => dated.value.filter((activity) => activityOn(activity, iso));
// From the dashboard: ?day=YYYY-MM-DD opens that day and ?activity=id its details.
const linked = new URLSearchParams(usePage().url.split('?')[1] ?? '');
const linkedDay = /^\d{4}-\d{2}-\d{2}$/.test(linked.get('day') ?? '') && linked.get('day').startsWith(props.month) ? linked.get('day') : null;
const selected = ref(linkedDay ?? (props.month === today.slice(0, 7) ? today : null));
const selectedActivities = computed(() => (selected.value ? activitiesOn(selected.value) : []));
const holidaysOn = (iso) => props.holidays.filter((holiday) => holiday.date === iso);
const chipLabel = (activity) => [shortTime(activity), activity.title].filter(Boolean).join(' · ');

// Clash warnings from the last save; dismissing hides them until the next save.
const showClashes = ref(true);
watch(() => props.clashes, () => (showClashes.value = true));

// The modals: details, then the form or action it opens.
const detailsId = ref(Number(linked.get('activity')) || null);
const editing = ref(null);
const formOpen = ref(false);
const moving = ref(null);
const reasonAction = ref(null);

const openDetails = (activity) => (detailsId.value = activity.id);
const addActivity = () => {
    editing.value = null;
    formOpen.value = true;
};
const reasonActions = {
    cancel: { title: 'Cancel the activity', field: 'reason', label: 'Why is it cancelled?', helperText: 'It stays on the calendar, struck through, with this reason.', submitLabel: 'Cancel activity', path: 'cancel', method: 'post', danger: true },
    return: { title: 'Return to the office', field: 'note', label: 'Note to the office', helperText: 'The office sees “Returned by PALD” with this note until they edit the activity.', submitLabel: 'Return', path: 'return', method: 'post', danger: false },
    remove: { title: 'Remove the activity', field: 'reason', label: 'Why is it removed?', helperText: 'The office is told, with this reason.', submitLabel: 'Remove', path: '', method: 'delete', danger: true },
};
const onDetailsAction = (action, activity) => {
    detailsId.value = null;
    if (action === 'edit') {
        editing.value = activity;
        formOpen.value = true;
    } else if (action === 'move') {
        moving.value = activity;
    } else if (action === 'done') {
        router.post(`/activities/${activity.id}/done`, {}, { preserveScroll: true });
    } else {
        const config = reasonActions[action];
        reasonAction.value = { ...config, title: `${config.title}: “${activity.title}”`, url: `/activities/${activity.id}${config.path ? `/${config.path}` : ''}` };
    }
};
</script>

<template>
    <Head title="Calendar of Activities" />

    <PageHeader title="Calendar of Activities" :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Calendar of Activities' }]">
        <template #actions>
            <Button variant="secondary" aria-label="Previous month" @click="shift(-1)">‹</Button>
            <Button variant="secondary" aria-label="Next month" @click="shift(1)">›</Button>
            <Button variant="secondary" icon="printer" :href="`/activities/sheet/print?${sheetQuery}`" external>Print</Button>
            <Button variant="secondary" icon="download" :href="`/activities/sheet/excel?${sheetQuery}`" external>Excel</Button>
            <Button v-if="canPost" icon="plus" @click="addActivity">Add activity</Button>
        </template>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-end gap-3 rounded-md bg-surface-muted p-3">
        <div>
            <span class="mb-1 block text-xs font-medium text-text-muted">View</span>
            <FilterButtons :model-value="view" label="View" variant="segmented" :options="viewOptions" @update:model-value="(value) => visit({ view: value })" />
        </div>
        <div class="min-w-48">
            <label for="filter-office" class="mb-1 block text-xs font-medium text-text-muted">Office</label>
            <Select id="filter-office" :model-value="filters.office ?? ''" :options="officeOptions" @update:model-value="(value) => visit({ office: value })" />
        </div>
        <div class="min-w-40">
            <label for="filter-mode" class="mb-1 block text-xs font-medium text-text-muted">Mode</label>
            <Select id="filter-mode" :model-value="filters.mode ?? ''" :options="modeFilterOptions" @update:model-value="(value) => visit({ mode: value })" />
        </div>
    </div>

    <Alert v-if="clashes.length && showClashes" tone="warning" title="Saved, but it clashes with" dismissible class="mb-4" @dismiss="showClashes = false">
        <ul class="list-disc pl-5">
            <li v-for="clash in clashes" :key="clash.id">
                “{{ clash.title }}” on {{ formatCalendarDate(clash.date) }}<template v-if="clash.office"> ({{ clash.office }})</template>
            </li>
        </ul>
        <p class="mt-1">Same venue or same office at an overlapping time. Move one if they can't both happen.</p>
    </Alert>

    <BusyRegion :busy="busy">
        <template v-if="view === 'grid'">
            <Card :title="title" icon="calendar">
                <MonthGrid :month="month" :holidays="holidays" :selected="selected" :today="today" select-label="show its activities" :caption="`Activities and holidays, ${title}`" @select="selected = $event">
                    <template #day="{ iso }">
                        <Tooltip v-for="activity in activitiesOn(iso).slice(0, MAX_PER_DAY)" :key="activity.id" :label="`${chipLabel(activity)} (${activityStatusLabel(activity)})`" class="mt-0.5 w-full">
                            <button
                                type="button"
                                class="block w-full min-w-0 flex-1 truncate rounded-sm border px-1 text-left text-2xs focus:outline-none focus:ring-2 focus:ring-focus-ring"
                                :class="activityChipClass(activity)"
                                :aria-label="`${chipLabel(activity)}, ${activityStatusLabel(activity)}: details`"
                                @click="openDetails(activity)"
                            >
                                {{ chipLabel(activity) }}
                            </button>
                        </Tooltip>
                        <button v-if="activitiesOn(iso).length > MAX_PER_DAY" type="button" class="mt-0.5 text-2xs font-medium text-link hover:underline" @click="selected = iso">
                            +{{ activitiesOn(iso).length - MAX_PER_DAY }} more
                        </button>
                    </template>
                </MonthGrid>
                <p class="mt-3 text-xs text-text-muted">Click an activity for its details, or a day for its list. Light blue chip without a border: holiday.</p>
            </Card>

            <Card v-if="selected" class="mt-6" icon="calendar" :title="formatCalendarDate(selected)">
                <ul v-if="holidaysOn(selected).length" class="mb-2 space-y-1 text-sm text-info-fg">
                    <li v-for="holiday in holidaysOn(selected)" :key="holiday.name">{{ holiday.name }}<template v-if="holiday.day_off"> (holiday)</template></li>
                </ul>
                <p v-if="!selectedActivities.length" class="text-sm text-text-muted">No activities on this day.</p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="activity in selectedActivities" :key="activity.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <button type="button" class="text-left font-medium text-link hover:underline" :class="{ 'line-through': activity.status === 'cancelled' }" @click="openDetails(activity)">{{ activity.title }}</button>
                            <div class="text-xs text-text-muted">
                                {{ [timeText(activity), activity.office?.short_name ?? activity.office?.name, activity.mode_label, activity.venue].filter(Boolean).join(' · ') }}
                            </div>
                        </div>
                        <StatusBadge :status="activityTone(activity)" :label="activityStatusLabel(activity)" />
                    </li>
                </ul>
            </Card>

            <Card class="mt-6" icon="clock" title="Dates to be announced">
                <p v-if="!monthOnly.length" class="text-sm text-text-muted">No activities without a date this month.</p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="activity in monthOnly" :key="activity.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                        <div class="min-w-0">
                            <button type="button" class="text-left font-medium text-link hover:underline" :class="{ 'line-through': activity.status === 'cancelled' }" @click="openDetails(activity)">{{ activity.title }}</button>
                            <div class="text-xs text-text-muted">{{ [activity.office?.short_name ?? activity.office?.name, activity.mode_label, activity.venue].filter(Boolean).join(' · ') }}</div>
                        </div>
                        <StatusBadge :status="activityTone(activity)" :label="activityStatusLabel(activity)" />
                    </li>
                </ul>
            </Card>
        </template>

        <Card v-else-if="sheet" :title="sheet.title" icon="documentText">
            <EmptyState v-if="!sheet.days.length && !sheet.tba.length" icon="calendar" title="No activities this month" description="Nothing is on the calendar for this month with these filters." />
            <div v-else class="overflow-x-auto">
                <table class="w-full min-w-[56rem] table-fixed border-collapse text-sm">
                    <caption class="sr-only">{{ sheet.title }}</caption>
                    <colgroup>
                        <col class="w-[13%]" />
                        <col class="w-[9%]" />
                        <col class="w-[17%]" />
                        <col class="w-[14%]" />
                        <col class="w-[25%]" />
                        <col class="w-[12%]" />
                        <col class="w-[10%]" />
                    </colgroup>
                    <thead>
                        <tr class="text-left text-xs font-semibold text-text-muted">
                            <th v-for="heading in ['Date/Time', 'Mode', 'Venue', 'Participants', 'Activity', 'Responsible Office/Personnel/ Committee', 'Remarks']" :key="heading" scope="col" class="border border-border px-2 py-2">{{ heading }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="day in sheet.days" :key="day.label">
                            <tr>
                                <th colspan="7" scope="colgroup" class="border border-border bg-surface-muted px-2 py-1.5 text-left font-semibold text-text">{{ day.label }}</th>
                            </tr>
                            <tr v-for="(row, index) in day.rows" :key="index" class="align-top" :class="row.time === 'HOLIDAY' ? 'text-info-fg' : 'text-text'">
                                <td class="border border-border px-2 py-1.5" :class="{ 'font-semibold': row.time === 'HOLIDAY' }">{{ row.time }}</td>
                                <td class="border border-border px-2 py-1.5">{{ row.mode }}</td>
                                <td class="border border-border px-2 py-1.5">{{ row.venue }}</td>
                                <td class="border border-border px-2 py-1.5">{{ row.participants }}</td>
                                <td class="border border-border px-2 py-1.5" :class="{ 'text-text-muted line-through': row.cancelled }">{{ row.activity }}</td>
                                <td class="border border-border px-2 py-1.5">{{ row.responsible }}</td>
                                <td class="border border-border px-2 py-1.5 text-text-muted">{{ row.remarks }}</td>
                            </tr>
                        </template>
                        <template v-if="sheet.tba.length">
                            <tr>
                                <th colspan="7" scope="colgroup" class="border border-border bg-surface-muted px-2 py-1.5 text-left font-semibold text-text">Dates to be announced</th>
                            </tr>
                            <tr v-for="(row, index) in sheet.tba" :key="`tba-${index}`" class="align-top text-text">
                                <td class="border border-border px-2 py-1.5">{{ row.time }}</td>
                                <td class="border border-border px-2 py-1.5">{{ row.mode }}</td>
                                <td class="border border-border px-2 py-1.5">{{ row.venue }}</td>
                                <td class="border border-border px-2 py-1.5">{{ row.participants }}</td>
                                <td class="border border-border px-2 py-1.5" :class="{ 'text-text-muted line-through': row.cancelled }">{{ row.activity }}</td>
                                <td class="border border-border px-2 py-1.5">{{ row.responsible }}</td>
                                <td class="border border-border px-2 py-1.5 text-text-muted">{{ row.remarks }}</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-text-muted">The same layout as the printed sheet. Switch to Calendar to open an activity.</p>
        </Card>
    </BusyRegion>

    <ActivityDetailsModal
        :open="detailsId !== null"
        :activity-id="detailsId"
        :can-manage="canManage"
        @close="detailsId = null"
        @edit="onDetailsAction('edit', $event)"
        @move="onDetailsAction('move', $event)"
        @done="onDetailsAction('done', $event)"
        @cancel="onDetailsAction('cancel', $event)"
        @return="onDetailsAction('return', $event)"
        @remove="onDetailsAction('remove', $event)"
    />
    <ActivityForm
        v-if="canPost"
        :open="formOpen"
        :activity="editing"
        :offices="postOffices"
        :mode-options="modeOptions"
        :my-office-id="myOfficeId"
        :default-date="selected"
        :default-month="month"
        @close="formOpen = false"
    />
    <MoveActivityModal :open="moving !== null" :activity="moving" @close="moving = null" />
    <ReasonModal
        :open="reasonAction !== null"
        :title="reasonAction?.title ?? ''"
        :url="reasonAction?.url ?? null"
        :field="reasonAction?.field ?? 'reason'"
        :label="reasonAction?.label ?? 'Reason'"
        :helper-text="reasonAction?.helperText ?? null"
        :submit-label="reasonAction?.submitLabel ?? 'Save'"
        :method="reasonAction?.method ?? 'post'"
        :danger="reasonAction?.danger ?? false"
        @close="reasonAction = null"
    />
</template>
