<script setup>
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import Checkbox from '@cscro8/ui/Components/Forms/Checkbox.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import { formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-6 / DTR: a month's tardiness and undertime, charged to leave credits.
 * The minutes come from each employee's DTR (late plus undertime), are
 * converted with CSC Table IV (MC No. 14, s. 1999) on the month's total, and
 * come off Vacation Leave (Omnibus Rules on Leave, Rule XVI Sec. 34). Sick
 * Leave only for undertime for health reasons, supported by a medical
 * certificate and a leave application. HR reviews, then charges; a charge
 * can be reversed.
 */
const props = defineProps({
    month: { type: String, required: true },
    label: { type: String, required: true },
    finished: { type: Boolean, required: true },
    rows: { type: Array, required: true },
    conversionTable: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});

// CSC Table IV, for reference: hidden until HR asks to see it.
const showTable = ref(false);
const hourRows = computed(() => Object.entries(props.conversionTable.hours));
const minuteBlocks = computed(() => {
    const entries = Object.entries(props.conversionTable.minutes);
    return [entries.slice(0, 20), entries.slice(20, 40), entries.slice(40, 60)];
});

const hm = (minutes) => (minutes ? `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}` : '—');
const days = (value) => Number(value).toFixed(3);
const changeMonth = (event) => event.target.value && router.get('/admin/hr/leave/tardiness', { month: event.target.value }, { preserveScroll: true });

const charging = ref(null);
const charge = useForm({ employee_id: '', month: props.month, charge: 'VL', health_certified: false, post_anyway: false });
const chargeOptions = [
    { value: 'VL', label: 'Vacation Leave (the rule)' },
    { value: 'SL', label: 'Sick Leave (health reasons only)' },
];
const openCharge = (row) => {
    charging.value = row;
    charge.reset();
    charge.clearErrors();
    charge.employee_id = row.employee_id;
    charge.month = props.month;
};
const submitCharge = () => charge.post('/admin/hr/leave/tardiness', { preserveScroll: true, onSuccess: () => (charging.value = null) });

const reversing = ref(null);
const reversal = useForm({ reason: '' });
const openReverse = (row) => {
    reversing.value = row;
    reversal.reset();
    reversal.clearErrors();
};
const submitReverse = () => reversal.post(`/admin/hr/leave/tardiness/${reversing.value.deduction.id}/reverse`, { preserveScroll: true, onSuccess: () => (reversing.value = null) });

// Days the employee entered: expand to see them; HR can verify them when the supervisor can't.
const opened = ref([]);
const toggleOpen = (id) => (opened.value = opened.value.includes(id) ? opened.value.filter((item) => item !== id) : [...opened.value, id]);
const verifyAsHr = async (row) => {
    const ok = await confirm({ title: `Verify ${row.name}'s entered days?`, text: 'Their supervisor or office head normally verifies these. Verify them yourself only if you have checked them.', confirmText: 'Verify' });
    if (ok) router.post('/admin/hr/leave/tardiness/verify', { employee_id: row.employee_id, month: props.month }, { preserveScroll: true });
};

// Rows that need no decision: something to charge, nothing unverified, no workbook card.
const readyCount = computed(() => props.rows.filter((row) => !row.deduction && row.minutes && !row.card_managed && !row.unverified_days).length);
// Charged months whose DTR has changed since (an adjustment or a late import).
const changedCount = computed(() => props.rows.filter((row) => row.deduction?.dtr_changed && !row.card_managed && !row.unverified_days).length);
const workbookCount = computed(() => props.rows.filter((row) => row.card_managed && !row.deduction).length);
const syncCount = computed(() => readyCount.value + changedCount.value);
const syncWithDtr = async () => {
    const parts = [
        readyCount.value ? `charge ${plural(readyCount.value, 'employee')} to Vacation Leave` : null,
        changedCount.value ? `re-charge ${plural(changedCount.value, 'employee')} whose DTR changed after they were charged` : null,
    ].filter(Boolean);
    const ok = await confirm({
        title: `Sync ${props.label} with the DTR?`,
        text: `This will ${parts.join(' and ')}. A re-charge reverses the old entry and posts the new total. Rows that need a decision stay in the list.`,
        confirmText: 'Sync',
    });
    if (ok) router.post('/admin/hr/leave/tardiness/sync', { month: props.month }, { preserveScroll: true });
};

const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;
const absenceText = (row) =>
    [row.absences ? plural(row.absences, 'absence') : null, row.half_day_absences ? `${plural(row.half_day_absences, 'half-day absence')}` : null].filter(Boolean).join(' and ');
const total = computed(() => props.rows.reduce((sum, row) => sum + row.minutes, 0));

// Minutes and days fold into one cell; hidden columns keep each figure in the export.
const columns = computed(() => [
    { key: 'name', label: 'Employee' },
    { key: 'time', label: 'Tardiness & undertime', sortable: false, export: false },
    { key: 'available', label: 'VL at month end', align: 'right', value: (row) => days(row.available) },
    { key: 'charge', label: 'Charge', sortable: false, value: (row) => (row.deduction ? `Charged ${days(row.deduction.days)} to ${row.deduction.type}` : row.card_managed ? 'On the workbook' : 'Not charged') },
    ...(props.canManage ? [{ key: 'actions', label: '', sortable: false, export: false, align: 'right' }] : []),
    { key: 'late_minutes', label: 'Late', hidden: true, value: (row) => hm(row.late_minutes) },
    { key: 'undertime_minutes', label: 'Undertime', hidden: true, value: (row) => hm(row.undertime_minutes) },
    { key: 'minutes', label: 'Total', hidden: true, value: (row) => hm(row.minutes) },
    { key: 'days', label: 'Days (Table IV)', hidden: true, value: (row) => (row.minutes ? days(row.days) : '') },
]);
</script>

<template>
    <Head title="Tardiness and undertime" />

    <PageHeader
        title="Tardiness and undertime"
        :description="`${label}: late and undertime minutes from each DTR, converted to days of leave credits.`"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Leave', href: '/admin/hr/leave' }, { label: 'Tardiness and undertime' }]"
    >
        <template #actions>
            <label class="flex items-center gap-2 text-sm text-text">
                Month
                <input type="month" class="filter-control" :value="month" aria-label="Month" @change="changeMonth" />
            </label>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <Card title="How it is worked out" icon="info">
            <ul class="list-disc space-y-1 pl-5 text-sm text-text">
                <li>Minutes late plus undertime minutes from the DTR for the month. Absences are not charged here.</li>
                <li>The month's total is converted with CSC Table IV, <em>Conversion of Working Hours/Minutes into Fractions of a Day</em> (CSC MC No. 14, s. 1999). For example 2 hours 20 minutes is .250 + .042 = .292 day.</li>
                <li>It comes off <strong>Vacation Leave</strong> (Omnibus Rules on Leave, Rule XVI Sec. 34). Sick Leave is charged only when the undertime is for health reasons, supported by a medical certificate and a leave application.</li>
                <li>The charge is dated the last day of the month, once the month has ended. A charge can be reversed; the leave card keeps both entries.</li>
            </ul>
        </Card>

        <Card title="CSC Table IV (reference)" icon="documentText">
            <template #actions>
                <Button variant="secondary" :icon="showTable ? 'eyeSlash' : 'eye'" :aria-expanded="showTable" aria-controls="table-iv" @click="showTable = !showTable">
                    {{ showTable ? 'Hide the table' : 'Show the table' }}
                </Button>
            </template>
            <p class="text-sm text-text-muted">Conversion of Working Hours/Minutes into Fractions of a Day (CSC MC No. 14, s. 1999). These are the values used for every charge.</p>
            <div v-show="showTable" id="table-iv" class="mt-4 space-y-4">
                <div class="overflow-x-auto">
                    <table class="text-left text-sm">
                        <caption class="mb-1 text-left text-xs font-medium text-text-muted">Hours</caption>
                        <thead class="border-b border-border text-xs text-text-muted">
                            <tr>
                                <th v-for="[hours] in hourRows" :key="hours" scope="col" class="px-4 py-1.5 text-right font-medium">{{ hours }} {{ hours === '1' ? 'hour' : 'hours' }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td v-for="[hours, day] in hourRows" :key="hours" class="px-4 py-1.5 text-right tabular-nums">{{ Number(day).toFixed(3) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="grid gap-6 md:grid-cols-3">
                    <table v-for="(block, index) in minuteBlocks" :key="index" class="text-left text-sm">
                        <caption class="sr-only">Minutes {{ block[0][0] }} to {{ block[block.length - 1][0] }}</caption>
                        <thead class="border-b border-border text-xs text-text-muted">
                            <tr>
                                <th scope="col" class="py-1.5 pr-4 font-medium">Minutes</th>
                                <th scope="col" class="px-3 py-1.5 text-right font-medium">Day</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-for="[minutes, day] in block" :key="minutes">
                                <td class="py-1 pr-4 tabular-nums">{{ minutes }}</td>
                                <td class="px-3 py-1 text-right tabular-nums">{{ Number(day).toFixed(3) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-text-muted">The month's total is read off this table: whole hours first, then the minutes left over. Eight hours or more carries into whole days.</p>
            </div>
        </Card>

        <Card :title="`${label}: ${rows.length} ${rows.length === 1 ? 'employee' : 'employees'}`" icon="clock">
            <template v-if="canManage" #actions>
                <Button icon="check" :disabled="!finished || !syncCount" @click="syncWithDtr">Sync with DTR ({{ syncCount }})</Button>
            </template>
            <p v-if="!finished" class="mb-3 text-sm text-warning-fg">This month has not ended yet. Figures are for review only; syncing opens when it ends.</p>
            <p v-else-if="canManage && !syncCount" class="mb-3 text-sm text-text-muted">
                Nothing to sync: {{ workbookCount ? `${plural(workbookCount, 'employee')} ${workbookCount === 1 ? 'is' : 'are'} already covered by HR's leave card workbook (record their tardiness there), and the rest ` : 'every row ' }}{{ workbookCount ? 'are' : 'is' }} already charged, has nothing to charge, or still has unverified days.
            </p>
            <DataTable
                :columns="columns"
                :rows="rows"
                row-key="employee_id"
                :caption="`Tardiness and undertime by employee, ${label}`"
                :export-name="`imis-tardiness-${month}`"
                :numbered="false"
                :empty-text="`No tardiness or undertime for ${label}.`"
            >
                <template #cell-name="{ row }">
                    <PersonCell :name="row.name" :photo-url="row.photo_url" :href="`/admin/hr/employees/${row.employee_id}/dtr?month=${month}`" :subtitle="row.absences || row.half_day_absences ? `${absenceText(row)}, without pay: not charged here` : null">
                        <div v-if="row.entered_days.length" class="mt-1 text-xs">
                            <button type="button" class="text-left text-link hover:underline" :aria-expanded="opened.includes(row.employee_id)" @click="toggleOpen(row.employee_id)">
                                {{ plural(row.entered_days.length, 'day') }} entered by the employee{{ row.entered_minutes ? `, ${hm(row.entered_minutes)} late or short` : '' }}{{ row.unverified_days ? `; ${row.unverified_days} not verified` : '; all verified' }}
                            </button>
                            <ul v-if="opened.includes(row.employee_id)" class="mt-1 space-y-0.5 text-text-muted">
                                <li v-for="day in row.entered_days" :key="day.date">
                                    {{ day.date }} · {{ day.time_in || '—' }} to {{ day.time_out || '—' }}{{ day.minutes ? ` (${day.minutes} min)` : '' }} · {{ day.remark }}
                                    <span :class="day.verified ? 'text-success-fg' : 'text-warning-fg'">{{ day.verified ? 'Verified' : 'Not verified' }}</span>
                                </li>
                            </ul>
                            <ActionButton v-if="canManage && row.unverified_days" icon="check" :label="`Verify ${row.name}'s entered days as HR`" @click="verifyAsHr(row)" />
                        </div>
                        <div v-if="row.habitual.tardiness" class="mt-1 text-xs text-warning-fg">Possible habitual tardiness (CSC MC No. 23, s. 1998): {{ row.habitual.tardiness }}.</div>
                        <div v-if="row.habitual.absenteeism" class="mt-1 text-xs text-warning-fg">Possible habitual absenteeism (CSC MC No. 23, s. 1998): {{ row.habitual.absenteeism }}.</div>
                    </PersonCell>
                </template>
                <template #cell-time="{ row }">
                    <CellFacts
                        class="tabular-nums"
                        :facts="[
                            { label: 'Late / undertime', value: `${hm(row.late_minutes)} / ${hm(row.undertime_minutes)}` },
                            { label: 'Total', value: hm(row.minutes) },
                            { label: 'Days (Table IV)', value: row.minutes ? days(row.days) : '—' },
                        ]"
                    />
                </template>
                <template #cell-available="{ row }">
                    <span class="tabular-nums" :class="!row.deduction && row.without_pay > 0 ? 'text-danger-fg' : ''">{{ days(row.available) }}</span>
                    <div v-if="!row.deduction && row.without_pay > 0" class="text-xs text-danger-fg">{{ days(row.without_pay) }} day without pay</div>
                </template>
                <template #cell-charge="{ row }">
                    <template v-if="row.deduction">
                        <Badge tone="success" pill>Charged {{ days(row.deduction.days) }} to {{ row.deduction.type }}</Badge>
                        <div v-if="row.deduction.days_without_pay > 0" class="mt-1 text-xs text-danger-fg">{{ days(row.deduction.days_without_pay) }} day without pay: credits used up</div>
                        <div class="mt-1 text-xs text-text-muted">{{ formatDateTime(row.deduction.posted_at) }}</div>
                        <div v-if="row.deduction.dtr_changed" class="mt-1 text-xs text-warning-fg">The DTR has changed since: now {{ hm(row.minutes) }}. Reverse and charge again to correct it.</div>
                    </template>
                    <span v-else-if="row.card_managed" class="text-xs text-text-muted">HR's leave card workbook already covers {{ label }}: record it there.</span>
                    <template v-else>
                        <span class="text-text-muted">Not charged</span>
                        <div v-if="row.card_pending" class="mt-1 text-xs text-text-muted">The workbook does not reach {{ label }} yet: charged here, and settled when the workbook catches up.</div>
                    </template>
                </template>
                <template #cell-actions="{ row }">
                    <span class="inline-flex">
                        <ActionButton v-if="row.deduction" icon="refresh" tone="danger" :label="`Reverse the charge for ${row.name}`" @click="openReverse(row)" />
                        <ActionButton v-else-if="finished && row.minutes && !row.card_managed" icon="plus" :label="`Charge ${row.name}`" @click="openCharge(row)" />
                    </span>
                </template>
            </DataTable>
            <p v-if="rows.length" class="mt-3 text-xs text-text-muted">All employees: {{ hm(total) }} of tardiness and undertime.</p>
        </Card>
    </div>

    <Modal :open="charging !== null" :title="charging ? `Charge ${charging.name}` : 'Charge'" icon="plus" tone="success" @close="charging = null">
        <form id="tardiness-charge-form" class="space-y-4" @submit.prevent="submitCharge">
            <p v-if="charging" class="text-sm text-text">
                {{ hm(charging.minutes) }} of tardiness and undertime in {{ label }} is <strong>{{ days(charging.days) }} day</strong> by CSC Table IV. It will be posted to the leave card, dated the last day of the month.
            </p>
            <p v-if="charging && charging.without_pay > 0 && charge.charge === 'VL'" class="rounded-md bg-warning-bg p-3 text-sm text-warning-fg">
                Vacation Leave credits at the end of the month are only {{ days(charging.available) }}. That much is taken off the credits; the other <strong>{{ days(charging.without_pay) }} day</strong> is recorded as without pay, for payroll.
            </p>
            <div>
                <InputLabel for="tardiness-charge" required>Charge to</InputLabel>
                <Select id="tardiness-charge" v-model="charge.charge" :options="chargeOptions" required :invalid="!!charge.errors.charge" />
            </div>
            <div v-if="charge.charge === 'SL'">
                <Checkbox id="tardiness-health" v-model="charge.health_certified" label="The undertime is for health reasons" />
                <p class="mt-1 pl-6 text-xs text-text-muted">A medical certificate and a leave application are on file.</p>
            </div>
            <div v-if="charging && charging.unverified_days">
                <Checkbox id="tardiness-post-anyway" v-model="charge.post_anyway" label="Charge anyway" />
                <p class="mt-1 pl-6 text-xs text-text-muted">{{ plural(charging.unverified_days, 'day') }} the employee entered {{ charging.unverified_days === 1 ? 'is' : 'are' }} not verified yet. This is recorded in the audit log.</p>
            </div>
            <InputError :message="charge.errors.charge || charge.errors.month" />
        </form>
        <template #footer>
            <Button variant="secondary" icon="close" @click="charging = null">Cancel</Button>
            <Button type="submit" form="tardiness-charge-form" variant="success" :loading="charge.processing">Charge</Button>
        </template>
    </Modal>

    <Modal :open="reversing !== null" title="Reverse this charge?" tone="danger" @close="reversing = null">
        <form id="tardiness-reverse-form" class="space-y-3" @submit.prevent="submitReverse">
            <p v-if="reversing" class="text-sm text-text">
                {{ reversing.name }}: {{ days(reversing.deduction.days) }} day charged to {{ reversing.deduction.type }} for {{ label }}. An equal and opposite entry is posted today; the original stays on the leave card.
            </p>
            <TextInput id="tardiness-reverse-reason" v-model="reversal.reason" label="Reason" required maxlength="255" :error="reversal.errors.reason" />
        </form>
        <template #footer>
            <Button variant="secondary" icon="close" @click="reversing = null">Cancel</Button>
            <Button type="submit" form="tardiness-reverse-form" variant="danger" :loading="reversal.processing">Reverse</Button>
        </template>
    </Modal>
</template>
