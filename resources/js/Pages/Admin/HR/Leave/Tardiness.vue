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
const charge = useForm({ employee_id: '', month: props.month, charge: 'VL', health_certified: false });
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

const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;
const absenceText = (row) =>
    [row.absences ? plural(row.absences, 'absence') : null, row.half_day_absences ? `${plural(row.half_day_absences, 'half-day absence')}` : null].filter(Boolean).join(' and ');
const total = computed(() => props.rows.reduce((sum, row) => sum + row.minutes, 0));
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
            <p v-if="!finished" class="mb-3 text-sm text-warning-fg">This month has not ended yet. Figures are for review only; charging opens when it ends.</p>
            <div class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Tardiness and undertime by employee</caption>
                    <thead class="border-b border-border text-xs text-text-muted">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">Employee</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Late</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Undertime</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Total</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">Days (Table IV)</th>
                            <th scope="col" class="px-3 py-2 text-right font-medium">VL at month end</th>
                            <th scope="col" class="py-2 pl-3 font-medium">Charge</th>
                            <th v-if="canManage" scope="col" class="py-2 pl-3 text-right font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in rows" :key="row.employee_id">
                            <td class="py-2 pr-4">
                                <a :href="`/admin/hr/employees/${row.employee_id}/dtr?month=${month}`" class="font-medium text-link hover:underline">{{ row.name }}</a>
                                <div v-if="row.absences || row.half_day_absences" class="text-xs text-text-muted">
                                    {{ absenceText(row) }}, without pay: not charged here
                                </div>
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ hm(row.late_minutes) }}</td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ hm(row.undertime_minutes) }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ hm(row.minutes) }}</td>
                            <td class="px-3 py-2 text-right font-medium tabular-nums">{{ row.minutes ? days(row.days) : '—' }}</td>
                            <td class="px-3 py-2 text-right tabular-nums" :class="!row.deduction && row.without_pay > 0 ? 'text-danger-fg' : ''">
                                {{ days(row.available) }}
                                <div v-if="!row.deduction && row.without_pay > 0" class="text-xs">{{ days(row.without_pay) }} day without pay</div>
                            </td>
                            <td class="py-2 pl-3">
                                <template v-if="row.deduction">
                                    <span class="inline-flex rounded-full bg-success-bg px-2.5 py-0.5 text-xs font-semibold text-success-fg">Charged {{ days(row.deduction.days) }} to {{ row.deduction.type }}</span>
                                    <div v-if="row.deduction.days_without_pay > 0" class="mt-1 text-xs text-danger-fg">{{ days(row.deduction.days_without_pay) }} day without pay: credits used up</div>
                                    <div class="mt-1 text-xs text-text-muted">{{ formatDateTime(row.deduction.posted_at) }}</div>
                                    <div v-if="row.deduction.dtr_changed" class="mt-1 text-xs text-warning-fg">The DTR has changed since: now {{ hm(row.minutes) }}. Reverse and charge again to correct it.</div>
                                </template>
                                <span v-else-if="row.card_managed" class="text-xs text-text-muted">Leave card comes from HR's workbook: record it there.</span>
                                <span v-else class="text-text-muted">Not charged</span>
                            </td>
                            <td v-if="canManage" class="py-2 pl-3 text-right">
                                <ActionButton v-if="row.deduction" icon="refresh" tone="danger" :label="`Reverse the charge for ${row.name}`" @click="openReverse(row)" />
                                <ActionButton v-else-if="finished && row.minutes && !row.card_managed" icon="plus" :label="`Charge ${row.name}`" @click="openCharge(row)" />
                            </td>
                        </tr>
                        <tr v-if="!rows.length">
                            <td colspan="8" class="py-4 text-center text-text-muted">No tardiness or undertime for {{ label }}.</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="rows.length" class="border-t border-border text-xs text-text-muted">
                        <tr>
                            <td class="py-2 pr-4">All employees</td>
                            <td colspan="2"></td>
                            <td class="px-3 py-2 text-right tabular-nums">{{ hm(total) }}</td>
                            <td colspan="4"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
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
            <label v-if="charge.charge === 'SL'" class="flex items-start gap-2 text-sm text-text">
                <input v-model="charge.health_certified" type="checkbox" class="mt-1" />
                <span>The undertime is for health reasons, and a medical certificate and a leave application are on file.</span>
            </label>
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
