<script setup>
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Tabs from '@cscro8/ui/Components/UI/Tabs.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * DTR: work schedules and who follows which. A schedule says when work
 * starts, the latest arrival before someone is late, how many minutes make
 * a full day, an optional break, and any rest weekday besides the weekend
 * (for example Friday for a compressed work week). Time before the start
 * never counts toward the day.
 */
const props = defineProps({
    schedules: { type: Array, required: true },
    assignments: { type: Array, required: true },
    employees: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
});

const weekdays = [
    { value: 1, label: 'Mon' },
    { value: 2, label: 'Tue' },
    { value: 3, label: 'Wed' },
    { value: 4, label: 'Thu' },
    { value: 5, label: 'Fri' },
];

const blank = () => ({ name: '', start_time: '08:00', latest_arrival: '08:00', required_minutes: 480, break_start: '12:00', break_minutes: 60, rest_weekdays: [] });
const editing = ref(null);
const scheduleForm = useForm(blank());

const startNew = () => {
    editing.value = null;
    scheduleForm.defaults(blank()).reset();
    scheduleForm.clearErrors();
};
const edit = (schedule) => {
    editing.value = schedule.id;
    scheduleForm.defaults({ ...schedule }).reset();
    scheduleForm.clearErrors();
};
const saveSchedule = () => {
    const options = { preserveScroll: true, onSuccess: startNew };
    if (editing.value) {
        scheduleForm.put(`/admin/hr/dtr/schedules/${editing.value}`, options);
    } else {
        scheduleForm.post('/admin/hr/dtr/schedules', options);
    }
};

// Apply one schedule to many employees at once. Applying from a date ends the current assignment the day before.
const assignForm = useForm({ employee_ids: [], work_schedule_id: '', valid_from: '', valid_to: '', replace: true });
const search = ref('');
const office = ref('');
const onlyUnscheduled = ref(false);
const offices = computed(() => [...new Set(props.employees.map((employee) => employee.office).filter(Boolean))].sort((a, b) => a.localeCompare(b)));
const unscheduled = computed(() => props.employees.filter((employee) => !employee.schedule).length);
const shown = computed(() => props.employees.filter((employee) => (!office.value || employee.office === office.value)
    && (!onlyUnscheduled.value || !employee.schedule)
    && (!search.value.trim() || employee.name.toLowerCase().includes(search.value.trim().toLowerCase()))));
const chosen = computed(() => new Set(assignForm.employee_ids));
const allShownChosen = computed(() => shown.value.length > 0 && shown.value.every((employee) => chosen.value.has(employee.id)));
const toggleShown = () => {
    const ids = new Set(assignForm.employee_ids);
    for (const employee of shown.value) {
        if (allShownChosen.value) ids.delete(employee.id);
        else ids.add(employee.id);
    }
    assignForm.employee_ids = [...ids];
};
const clearChosen = () => {
    assignForm.employee_ids = [];
};
// What the button is still waiting for, so it never sits greyed out without saying why.
const missing = computed(() => [
    !assignForm.employee_ids.length ? 'an employee' : null,
    !assignForm.work_schedule_id ? 'a schedule' : null,
    !assignForm.valid_from ? 'a From date' : null,
].filter(Boolean));
const assign = () => assignForm.post('/admin/hr/dtr/schedules/assign-many', { preserveScroll: true, onSuccess: () => assignForm.reset('employee_ids') });

const actionErrors = ref('');
const endAssignment = (row, value) => {
    actionErrors.value = '';
    router.patch(`/admin/hr/dtr/schedules/assignments/${row.id}`, { valid_to: value || null }, { preserveScroll: true, onError: (errors) => (actionErrors.value = Object.values(errors)[0] ?? '') });
};
const remove = async (row) => {
    const ok = await confirm({ title: `Remove ${row.employee}'s schedule assignment?`, text: 'Days it covered will show no schedule until another is assigned.', confirmText: 'Remove' });
    if (ok) {
        router.delete(`/admin/hr/dtr/schedules/assignments/${row.id}`, { preserveScroll: true });
    }
};

// Filters for "Who follows which": current assignments by default, so ended ones don't crowd the table.
const rowSearch = ref('');
const rowSchedule = ref('');
const rowOffice = ref('');
const includeEnded = ref(false);
const todayKey = new Date().toLocaleDateString('en-CA');
const isCurrent = (row) => !row.valid_to || row.valid_to >= todayKey;
const rowOffices = computed(() => [...new Set(props.assignments.map((row) => row.office).filter(Boolean))].sort((a, b) => a.localeCompare(b)));
const visibleRows = computed(() => props.assignments.filter((row) => (includeEnded.value || isCurrent(row))
    && (!rowSchedule.value || row.schedule === rowSchedule.value)
    && (!rowOffice.value || row.office === rowOffice.value)
    && (!rowSearch.value.trim() || row.employee.toLowerCase().includes(rowSearch.value.trim().toLowerCase()))));
watch([rowSearch, rowSchedule, rowOffice, includeEnded], () => {
    pickedRows.value = [];
});

// Tick several rows of "Who follows which" and remove them together.
const pickedRows = ref([]);
const allRowsPicked = computed(() => visibleRows.value.length > 0 && visibleRows.value.every((row) => pickedRows.value.includes(row.id)));
const toggleAllRows = () => {
    pickedRows.value = allRowsPicked.value ? [] : visibleRows.value.map((row) => row.id);
};
const bulkEnd = ref('');
const ending = ref(false);
const endPicked = () => {
    actionErrors.value = '';
    router.post('/admin/hr/dtr/schedules/assignments/end-many', { assignment_ids: pickedRows.value, valid_to: bulkEnd.value }, {
        preserveScroll: true,
        onStart: () => (ending.value = true),
        onFinish: () => (ending.value = false),
        onSuccess: () => {
            pickedRows.value = [];
            bulkEnd.value = '';
        },
        onError: (errors) => (actionErrors.value = Object.values(errors)[0] ?? ''),
    });
};
const removing = ref(false);
const removePicked = async () => {
    const count = pickedRows.value.length;
    const ok = await confirm({ title: `Remove ${count} schedule ${count === 1 ? 'assignment' : 'assignments'}?`, text: 'Days they covered will show no schedule until another is assigned.', confirmText: 'Remove' });
    if (ok) {
        actionErrors.value = '';
        router.post('/admin/hr/dtr/schedules/assignments/remove-many', { assignment_ids: pickedRows.value }, {
            preserveScroll: true,
            onStart: () => (removing.value = true),
            onFinish: () => (removing.value = false),
            onSuccess: () => (pickedRows.value = []),
            onError: (errors) => (actionErrors.value = Object.values(errors)[0] ?? ''),
        });
    }
};

const tab = ref('schedules');
const tabs = computed(() => [
    { key: 'schedules', label: 'Schedules', icon: 'clock' },
    ...(props.canManage ? [{ key: 'assign', label: 'Assign', icon: 'users', badge: unscheduled.value || null }] : []),
    { key: 'assignments', label: 'Who follows which', icon: 'clipboard' },
]);
const assignUnscheduled = () => {
    onlyUnscheduled.value = true;
    tab.value = 'assign';
};

const hours = (minutes) => `${Math.floor(minutes / 60)}h${minutes % 60 ? ` ${minutes % 60}m` : ''}`;
const rest = (days) => (days.length ? days.map((day) => weekdays.find((item) => item.value === day)?.label).join(', ') : 'Weekends only');
</script>

<template>
    <Head title="Work schedules" />

    <PageHeader
        title="Work schedules"
        description="Hours, late rule and rest days for the Daily Time Record. Each employee follows one schedule at a time."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Work schedules' }]"
    />

    <div v-if="canManage && unscheduled" class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-md border border-warning-border bg-warning-bg px-4 py-3 text-sm text-warning-fg" role="status">
        <span><strong>{{ unscheduled }}</strong> {{ unscheduled === 1 ? 'employee has' : 'employees have' }} no schedule today, so the DTR shows their punches but no hours.</span>
        <Button type="button" size="sm" variant="secondary" @click="assignUnscheduled">Assign them one</Button>
    </div>

    <Tabs v-model="tab" :tabs="tabs">
    <template #schedules>
    <div class="space-y-6 pt-4">
        <Card title="Schedules" icon="clock">
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Work schedules</caption>
                    <thead class="border-b border-border text-xs text-text-muted">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">Schedule</th>
                            <th scope="col" class="px-3 py-2 font-medium">Work starts</th>
                            <th scope="col" class="px-3 py-2 font-medium">Late after</th>
                            <th scope="col" class="px-3 py-2 font-medium">Full day</th>
                            <th scope="col" class="px-3 py-2 font-medium">Break</th>
                            <th scope="col" class="px-3 py-2 font-medium">Work week</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Assigned</th>
                            <th v-if="canManage" scope="col" class="py-2 pl-3 text-right font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="schedule in schedules" :key="schedule.id">
                            <td class="py-2 pr-4 font-medium">{{ schedule.name }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ schedule.start_time }}</td>
                            <td class="px-3 py-2 tabular-nums">{{ schedule.latest_arrival }}</td>
                            <td class="px-3 py-2">{{ hours(schedule.required_minutes) }}</td>
                            <td class="px-3 py-2">{{ schedule.break_start ? `${schedule.break_minutes} min from ${schedule.break_start}` : 'None' }}</td>
                            <td class="px-3 py-2">
                                <ol class="flex gap-1" :aria-label="`Work week: ${rest(schedule.rest_weekdays)} rest`">
                                    <li v-for="day in weekdays" :key="day.value" class="flex h-6 w-9 items-center justify-center rounded-sm text-2xs font-medium" :class="schedule.rest_weekdays.includes(day.value) ? 'bg-surface-muted text-text-muted line-through' : 'bg-primary/10 text-link'" :title="schedule.rest_weekdays.includes(day.value) ? `${day.label}: rest day` : `${day.label}: work`">{{ day.label }}</li>
                                </ol>
                                <span class="mt-1 block text-xs text-text-muted">{{ rest(schedule.rest_weekdays) }}</span>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ schedule.assigned_count }}</td>
                            <td v-if="canManage" class="py-2 pl-3 text-right">
                                <ActionButton icon="pencil" :label="`Edit ${schedule.name}`" @click="edit(schedule)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>

        <Card v-if="canManage" :title="editing ? 'Edit the schedule' : 'Add a schedule'" icon="plus">
            <form class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3" @submit.prevent="saveSchedule">
                <div class="sm:col-span-2 lg:col-span-3">
                    <InputLabel for="sched-name">Name</InputLabel>
                    <input id="sched-name" v-model="scheduleForm.name" type="text" maxlength="120" class="filter-control mt-1 w-full" required />
                    <InputError :message="scheduleForm.errors.name" />
                </div>
                <div>
                    <InputLabel for="sched-start">Work starts</InputLabel>
                    <input id="sched-start" v-model="scheduleForm.start_time" type="time" class="filter-control mt-1 w-full" required />
                    <InputError :message="scheduleForm.errors.start_time" />
                </div>
                <div>
                    <InputLabel for="sched-late">Late after</InputLabel>
                    <input id="sched-late" v-model="scheduleForm.latest_arrival" type="time" class="filter-control mt-1 w-full" required />
                    <InputError :message="scheduleForm.errors.latest_arrival" />
                </div>
                <div>
                    <InputLabel for="sched-minutes">Full day (minutes)</InputLabel>
                    <input id="sched-minutes" v-model.number="scheduleForm.required_minutes" type="number" min="1" max="1440" class="filter-control mt-1 w-full" required />
                    <InputError :message="scheduleForm.errors.required_minutes" />
                </div>
                <div>
                    <InputLabel for="sched-break-start">Break starts (blank for none)</InputLabel>
                    <input id="sched-break-start" v-model="scheduleForm.break_start" type="time" class="filter-control mt-1 w-full" />
                    <InputError :message="scheduleForm.errors.break_start" />
                </div>
                <div>
                    <InputLabel for="sched-break-minutes">Break (minutes)</InputLabel>
                    <input id="sched-break-minutes" v-model.number="scheduleForm.break_minutes" type="number" min="0" max="600" class="filter-control mt-1 w-full" />
                    <InputError :message="scheduleForm.errors.break_minutes" />
                </div>
                <fieldset>
                    <legend class="text-sm font-medium text-text">Rest days besides the weekend</legend>
                    <div class="mt-2 flex flex-wrap gap-3">
                        <label v-for="day in weekdays" :key="day.value" class="flex items-center gap-1 text-sm">
                            <input v-model="scheduleForm.rest_weekdays" type="checkbox" :value="day.value" />{{ day.label }}
                        </label>
                    </div>
                    <InputError :message="scheduleForm.errors['rest_weekdays.0'] || scheduleForm.errors.rest_weekdays" />
                </fieldset>
                <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-3">
                    <Button type="submit" :loading="scheduleForm.processing">{{ editing ? 'Save' : 'Add schedule' }}</Button>
                    <Button v-if="editing" type="button" variant="secondary" @click="startNew">Cancel</Button>
                </div>
            </form>
        </Card>

    </div>
    </template>

    <template #assign>
    <div class="pt-4">
        <Card v-if="canManage" title="Assign a schedule" icon="users" description="Pick one or many employees, choose a schedule and the dates, and apply it to all of them at once.">
            <form class="grid gap-6 lg:grid-cols-2" @submit.prevent="assign">
                <div>
                    <div class="flex flex-wrap items-end gap-2">
                        <div class="min-w-40 flex-1">
                            <InputLabel for="assign-search">Find an employee</InputLabel>
                            <input id="assign-search" v-model="search" type="search" placeholder="Search by name" class="filter-control mt-1 w-full" autocomplete="off" />
                        </div>
                        <div>
                            <InputLabel for="assign-office">Office</InputLabel>
                            <select id="assign-office" v-model="office" class="filter-control mt-1">
                                <option value="">All offices</option>
                                <option v-for="name in offices" :key="name" :value="name">{{ name }}</option>
                            </select>
                        </div>
                    </div>

                    <label v-if="unscheduled" class="mt-3 flex items-center gap-2 text-sm text-text">
                        <input v-model="onlyUnscheduled" type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                        Only people with no schedule today ({{ unscheduled }})
                    </label>

                    <div class="mt-3 flex flex-wrap items-center justify-between gap-2 text-sm">
                        <label class="inline-flex items-center gap-2 font-medium text-text">
                            <input type="checkbox" :checked="allShownChosen" :disabled="!shown.length" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" @change="toggleShown" />
                            {{ allShownChosen ? 'Unselect' : 'Select' }} all shown ({{ shown.length }})
                        </label>
                        <span class="text-text-muted" aria-live="polite">
                            <strong class="text-text">{{ assignForm.employee_ids.length }}</strong> selected
                            <button v-if="assignForm.employee_ids.length" type="button" class="ml-2 text-link hover:underline" @click="clearChosen">Clear</button>
                        </span>
                    </div>

                    <ul class="mt-2 max-h-80 divide-y divide-border overflow-y-auto rounded-md border border-border" aria-label="Employees">
                        <li v-for="employee in shown" :key="employee.id">
                            <label class="flex cursor-pointer items-center gap-3 px-3 py-2 text-sm hover:bg-surface-muted" :class="chosen.has(employee.id) ? 'bg-primary/5' : ''">
                                <input v-model="assignForm.employee_ids" type="checkbox" :value="employee.id" class="h-4 w-4 shrink-0 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate font-medium text-text">{{ employee.name }}</span>
                                    <span class="block truncate text-xs text-text-muted">{{ employee.office || 'No office' }} · {{ employee.schedule ? `now ${employee.schedule}` : 'no schedule yet' }}</span>
                                </span>
                            </label>
                        </li>
                        <li v-if="!shown.length" class="px-3 py-6 text-center text-sm text-text-muted">No one matches.</li>
                    </ul>
                    <InputError :message="assignForm.errors.employee_ids || assignForm.errors['employee_ids.0']" />
                </div>

                <div class="space-y-3">
                    <div>
                        <InputLabel for="assign-schedule">Schedule</InputLabel>
                        <select id="assign-schedule" v-model="assignForm.work_schedule_id" class="filter-control mt-1 w-full" required>
                            <option value="" disabled>Choose a schedule</option>
                            <option v-for="schedule in schedules" :key="schedule.id" :value="schedule.id">{{ schedule.name }}</option>
                        </select>
                        <InputError :message="assignForm.errors.work_schedule_id" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <InputLabel for="assign-from">From</InputLabel>
                            <input id="assign-from" v-model="assignForm.valid_from" type="date" class="filter-control mt-1 w-full" required />
                            <InputError :message="assignForm.errors.valid_from" />
                        </div>
                        <div>
                            <InputLabel for="assign-to">To (blank for still)</InputLabel>
                            <input id="assign-to" v-model="assignForm.valid_to" type="date" class="filter-control mt-1 w-full" />
                            <InputError :message="assignForm.errors.valid_to" />
                        </div>
                    </div>
                    <label class="flex items-start gap-2 text-sm text-text">
                        <input v-model="assignForm.replace" type="checkbox" class="mt-0.5 h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                        <span>
                            Replace their current schedule from this date
                            <span class="block text-xs text-text-muted">Their current assignment ends the day before. Anyone it can't be applied to cleanly is skipped and named.</span>
                        </span>
                    </label>
                    <div class="flex flex-wrap items-center justify-end gap-3">
                        <p v-if="missing.length" class="text-xs text-text-muted" role="status">To apply, still needed: {{ missing.join(', ') }}.</p>
                        <Button type="submit" icon="check" :loading="assignForm.processing" :disabled="missing.length > 0">
                            Apply to {{ assignForm.employee_ids.length || '…' }} {{ assignForm.employee_ids.length === 1 ? 'employee' : 'employees' }}
                        </Button>
                    </div>
                </div>
            </form>
        </Card>

    </div>
    </template>

    <template #assignments>
    <div class="pt-4">
        <Card title="Who follows which" icon="clipboard">
            <InputError :message="actionErrors" />
            <div class="mb-3 flex flex-wrap items-end gap-2">
                <div class="min-w-40 flex-1">
                    <InputLabel for="row-search">Find an employee</InputLabel>
                    <input id="row-search" v-model="rowSearch" type="search" placeholder="Search by name" class="filter-control mt-1 w-full" autocomplete="off" />
                </div>
                <div>
                    <InputLabel for="row-office">Office</InputLabel>
                    <select id="row-office" v-model="rowOffice" class="filter-control mt-1">
                        <option value="">All offices</option>
                        <option v-for="name in rowOffices" :key="name" :value="name">{{ name }}</option>
                    </select>
                </div>
                <div>
                    <InputLabel for="row-schedule">Schedule</InputLabel>
                    <select id="row-schedule" v-model="rowSchedule" class="filter-control mt-1">
                        <option value="">All schedules</option>
                        <option v-for="item in schedules" :key="item.id" :value="item.name">{{ item.name }}</option>
                    </select>
                </div>
                <label class="flex items-center gap-2 pb-2 text-sm text-text">
                    <input v-model="includeEnded" type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                    Include ended
                </label>
            </div>
            <p class="mb-2 text-xs text-text-muted" aria-live="polite">Showing {{ visibleRows.length }} of {{ assignments.length }}.</p>
            <div v-if="canManage && pickedRows.length" class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-md border border-border bg-surface-muted px-3 py-2 text-sm" aria-live="polite">
                <span><strong>{{ pickedRows.length }}</strong> selected</span>
                <span class="flex items-center gap-3">
                    <button type="button" class="text-link hover:underline" @click="pickedRows = []">Clear</button>
                    <label class="flex items-center gap-2">
                        <span class="sr-only">End date for the selected</span>
                        <input v-model="bulkEnd" type="date" class="filter-control" aria-label="End date for the selected assignments" />
                        <Button type="button" variant="secondary" :loading="ending" :disabled="!bulkEnd" @click="endPicked">End on this date</Button>
                    </label>
                    <Button type="button" icon="trash" variant="danger" :loading="removing" @click="removePicked">Remove selected</Button>
                </span>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Work schedule assignments</caption>
                    <thead class="border-b border-border text-xs text-text-muted">
                        <tr>
                            <th v-if="canManage" scope="col" class="w-8 py-2 pr-2">
                                <input type="checkbox" :checked="allRowsPicked" :disabled="!visibleRows.length" aria-label="Select all assignments" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" @change="toggleAllRows" />
                            </th>
                            <th scope="col" class="py-2 pr-4 font-medium">Employee</th>
                            <th scope="col" class="hidden py-2 pr-4 font-medium sm:table-cell">Schedule</th>
                            <th scope="col" class="hidden py-2 pr-4 font-medium sm:table-cell">From</th>
                            <th scope="col" class="py-2 pr-4 font-medium">To</th>
                            <th v-if="canManage" scope="col" class="py-2 pl-3 text-right font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in visibleRows" :key="row.id" :class="pickedRows.includes(row.id) ? 'bg-primary/5' : ''">
                            <td v-if="canManage" class="py-2 pr-2">
                                <input v-model="pickedRows" type="checkbox" :value="row.id" :aria-label="`Select ${row.employee}'s assignment`" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                            </td>
                            <td class="py-2 pr-4 font-medium">
                                {{ row.employee }}
                                <span class="block text-xs font-normal text-text-muted sm:hidden">{{ row.schedule }} · from {{ formatCalendarDate(row.valid_from) }}</span>
                            </td>
                            <td class="hidden py-2 pr-4 sm:table-cell">{{ row.schedule }}</td>
                            <td class="hidden whitespace-nowrap py-2 pr-4 sm:table-cell">{{ formatCalendarDate(row.valid_from) }}</td>
                            <td class="whitespace-nowrap py-2 pr-4">
                                <template v-if="canManage">
                                    <input type="date" class="filter-control" :value="row.valid_to ?? ''" :min="row.valid_from" :aria-label="`End date for ${row.employee}`" @change="endAssignment(row, $event.target.value)" />
                                </template>
                                <template v-else>{{ row.valid_to ? formatCalendarDate(row.valid_to) : 'Still' }}</template>
                            </td>
                            <td v-if="canManage" class="py-2 pl-3 text-right">
                                <ActionButton icon="trash" tone="danger" :label="`Remove ${row.employee}'s assignment`" @click="remove(row)" />
                            </td>
                        </tr>
                        <tr v-if="!visibleRows.length">
                            <td colspan="6" class="py-4 text-center text-text-muted">{{ assignments.length ? 'No assignment matches these filters.' : 'No one has a schedule yet. Without one, the DTR shows punches but no hours.' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>
    </template>
    </Tabs>
</template>
