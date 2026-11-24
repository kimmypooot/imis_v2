<script setup>
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import DtrSheetTable from '../../Components/HR/DtrSheetTable.vue';
import DtrPrintChoice from '../../Components/HR/DtrPrintChoice.vue';
import Combobox from '../../Components/Forms/Combobox.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * DTR self-service: your own monthly Daily Time Record from the biometric
 * punches, one sheet per office you served that month. Read-only: ask HRD
 * to correct a day (a missed punch, an event, travel). Once a sheet's last
 * day has passed you can sign it with your saved e-signature; the printed
 * form then carries your signature and a code anyone can validate.
 */
const props = defineProps({
    employee: { type: Object, default: null },
    month: { type: String, required: true },
    label: { type: String, required: true },
    sheets: { type: Array, required: true },
    hasRecord: { type: Boolean, required: true },
    hasSignature: { type: Boolean, default: false },
    earliestEntry: { type: String, default: '' },
    // Months HRD already charged to leave credits: closed to your own entries.
    chargedMonths: { type: Array, default: () => [] },
    /** Who can verify a sheet: [{ value, label, description, group }]. */
    signatoryOptions: { type: Array, default: () => [] },
});

const changeMonth = (event) => event.target.value && router.get('/my/dtr', { month: event.target.value }, { preserveScroll: true });
const printUrl = (sheet) => `/my/dtr/print?from=${sheet.from}&to=${sheet.to}`;
// The sheet whose "Print CS Form 48" is open, to choose a single or double DTR.
const printing = ref(null);
const exportUrl = (sheet) => `/my/dtr/export?from=${sheet.from}&to=${sheet.to}`;

const localToday = (() => {
    const now = new Date();
    return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, '0')}-${String(now.getDate()).padStart(2, '0')}`;
})();
const finished = (sheet) => sheet.to < localToday;
const needsSigning = (sheet) => finished(sheet) && (!sheet.signature || sheet.signature.status !== 'valid');

// Who verifies a sheet (the name under "Office Head Name/Signature"): you pick, because supervisors are sometimes away at short notice.
const picking = ref(null);
const pickForm = useForm({ from: '', to: '', signatory_id: null });
const openPick = (sheet) => {
    picking.value = sheet;
    pickForm.clearErrors();
    pickForm.from = sheet.from;
    pickForm.to = sheet.to;
    pickForm.signatory_id = sheet.signatory?.chosen ? sheet.signatory.id : null;
};
const savePick = () => pickForm.post('/my/dtr/signatory', { preserveScroll: true, onSuccess: () => (picking.value = null) });

const signing = ref(null);
const form = useForm({ from: '', to: '', password: '' });
const openSign = (sheet) => {
    signing.value = sheet;
    form.reset();
    form.clearErrors();
    form.from = sheet.from;
    form.to = sheet.to;
};
// Entering your own times: edit the rows, then certify them as true and correct before they are saved.
const editingFrom = ref(null);
const drafts = reactive({});
const base = {};
const confirming = ref(false);
const adjustForm = useForm({ entries: [], certify: false });

const canEnter = (row) => row.date <= localToday && !props.chargedMonths.includes(row.date.slice(0, 7)) && (!props.earliestEntry || row.date >= props.earliestEntry) && !(row.adjustment && !row.adjustment.by_employee);
const startEdit = (sheet) => {
    Object.keys(drafts).forEach((key) => delete drafts[key]);
    for (const row of sheet.rows) {
        if (canEnter(row)) {
            base[row.date] = { in: row.in ?? '', out: row.out ?? '', remark: row.adjustment?.by_employee ? row.adjustment.remark : '' };
            drafts[row.date] = { ...base[row.date] };
        }
    }
    adjustForm.reset();
    adjustForm.clearErrors();
    editingFrom.value = sheet.from;
};
const stopEdit = () => {
    editingFrom.value = null;
    confirming.value = false;
};
const hadEntry = (sheet, date) => !!sheet.rows.find((row) => row.date === date)?.adjustment?.by_employee;
const changes = computed(() => {
    const sheet = props.sheets.find((item) => item.from === editingFrom.value);
    if (!sheet) return [];

    return Object.keys(drafts)
        .filter((date) => ['in', 'out', 'remark'].some((field) => drafts[date][field] !== base[date][field]))
        // Clearing both times on a day with no entry of yours changes nothing.
        .filter((date) => drafts[date].in || drafts[date].out || hadEntry(sheet, date))
        .map((date) => ({ date, time_in: drafts[date].in || null, time_out: drafts[date].out || null, remark: drafts[date].remark }))
        .sort((a, b) => a.date.localeCompare(b.date));
});
const askToConfirm = () => {
    adjustForm.certify = false;
    adjustForm.clearErrors();
    confirming.value = true;
};
const saveEntries = () => {
    adjustForm.entries = changes.value;
    adjustForm.post('/my/dtr/adjustments', { preserveScroll: true, onSuccess: stopEdit });
};
const clockLabel = (value) => {
    if (!value) return '—';
    const [h, m] = value.split(':').map(Number);

    return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
};
const dayLabel = (date) => new Date(`${date}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric', weekday: 'short' });

const submit = () => form.post('/my/dtr/sign', { preserveScroll: true, onSuccess: () => (signing.value = null) });
</script>

<template>
    <Head title="My DTR" />

    <PageHeader title="My Daily Time Record" :description="label" :breadcrumbs="[{ label: 'Home', href: '/' }, { label: 'My DTR' }]">
        <template v-if="hasRecord" #actions>
            <label class="flex items-center gap-2 text-sm text-text">
                Month
                <input type="month" class="filter-control" :value="month" aria-label="Month" @change="changeMonth" />
            </label>
        </template>
    </PageHeader>

    <Card v-if="!hasRecord">
        <EmptyState icon="clock" title="No employee record yet" description="Your Daily Time Record appears here once HR has set up your employee record and matched your biometric ID." />
    </Card>

    <div v-else class="space-y-6">
        <Card v-for="sheet in sheets" :key="sheet.from" :title="`${sheet.period}${sheet.unit ? ` · ${sheet.unit}` : ''}`" icon="clock">
            <template #actions>
                <template v-if="editingFrom === sheet.from">
                    <Button variant="secondary" icon="close" @click="stopEdit">Cancel</Button>
                    <Button icon="check" :disabled="!changes.length" @click="askToConfirm">Save {{ changes.length || '' }} {{ changes.length === 1 ? 'change' : 'changes' }}</Button>
                </template>
                <Button v-else-if="!editingFrom" variant="secondary" icon="pencil" @click="startEdit(sheet)">Edit my entries</Button>
                <Button v-if="needsSigning(sheet) && hasSignature && !editingFrom" variant="success" icon="pencil" @click="openSign(sheet)">{{ sheet.signature ? 'Sign again' : 'Sign this DTR' }}</Button>
                <Button variant="secondary" icon="printer" @click="printing = sheet">Print CS Form 48</Button>
                <Button variant="secondary" :href="exportUrl(sheet)" external icon="download">Export to Excel</Button>
            </template>
            <p class="mb-3 text-sm text-text-muted">
                Work schedule: <span class="text-text">{{ sheet.schedule ?? 'Not assigned yet' }}</span>
            </p>
            <p class="mb-3 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-text-muted">
                <span>Verified by:</span>
                <template v-if="sheet.signatory">
                    <span class="font-medium text-text">{{ sheet.signatory.name }}</span>
                    <span v-if="sheet.signatory.position">({{ sheet.signatory.position }})</span>
                    <span v-if="!sheet.signatory.chosen">· the office head</span>
                    <span v-if="!sheet.signatory.active" class="text-warning-fg">· no longer active, choose someone else</span>
                </template>
                <span v-else class="text-warning-fg">No one yet: choose who verifies this sheet</span>
                <Button :variant="sheet.signatory?.chosen ? 'secondary' : 'primary'" size="sm" icon="pencil" @click="openPick(sheet)">
                    {{ sheet.signatory?.chosen ? 'Change verifier' : 'Choose verifier' }}
                </Button>
            </p>
            <p v-if="sheet.signature" class="mb-3 text-sm">
                <span
                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold"
                    :class="sheet.signature.status === 'valid' ? 'bg-success-bg text-success-fg' : 'bg-warning-bg text-warning-fg'"
                >
                    {{ sheet.signature.status === 'valid' ? 'Signed' : 'Changed since signed' }}
                </span>
                <span class="ml-2 text-text-muted">{{ formatDateTime(sheet.signature.signed_at) }} · code {{ sheet.signature.code }}</span>
                <span v-if="sheet.signature.status !== 'valid'" class="ml-2 text-warning-fg">This sheet changed after you signed it (an HRD adjustment, or a different person to verify it). Review it and sign again.</span>
            </p>
            <p v-else-if="finished(sheet) && !hasSignature" class="mb-3 text-sm text-text-muted">
                To sign this sheet, first <Link href="/my/employee-record" class="text-link hover:underline">save your e-signature</Link>.
            </p>
            <p v-if="editingFrom === sheet.from" class="mb-3 rounded-md border border-info-border bg-info-bg px-3 py-2 text-sm text-text">
                Enter your actual time in and out for a day where a punch is missing or wrong, with a reason. Your fingerprint punches are never changed: what you enter is kept beside them, shown as "Employee-entered" on the printed form, and HRD can see it. Days HRD adjusted, and months already charged to leave credits, are locked. Your supervisor or office head verifies what you enter. Clear both times to take back an entry of yours.
            </p>
            <DtrSheetTable :rows="sheet.rows" :totals="sheet.totals" :editing="editingFrom === sheet.from" :drafts="drafts" :earliest="earliestEntry" file-leave />
        </Card>
    </div>

    <Modal :open="confirming" title="Is what you entered true and correct?" icon="check" tone="primary" @close="confirming = false">
        <form id="dtr-entries-form" class="space-y-3" @submit.prevent="saveEntries">
            <p class="text-sm text-text">You are about to change {{ changes.length }} {{ changes.length === 1 ? 'day' : 'days' }} on your Daily Time Record:</p>
            <ul class="max-h-56 divide-y divide-border overflow-y-auto rounded-md border border-border text-sm">
                <li v-for="item in changes" :key="item.date" class="px-3 py-2">
                    <span class="font-medium">{{ dayLabel(item.date) }}</span>
                    <span class="ml-2 tabular-nums">{{ item.time_in || item.time_out ? `${clockLabel(item.time_in)} to ${clockLabel(item.time_out)}` : 'Take back my entry' }}</span>
                    <span v-if="item.remark" class="block text-xs text-text-muted">{{ item.remark }}</span>
                </li>
            </ul>
            <label class="flex items-start gap-2 text-sm text-text">
                <input v-model="adjustForm.certify" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                <span>I certify on my honor that the times and reasons I entered are true and correct. I understand that HRD can see them and that a false entry may have consequences.</span>
            </label>
            <InputError :message="adjustForm.errors.certify || adjustForm.errors.entries" />
        </form>
        <template #footer>
            <Button variant="secondary" icon="close" @click="confirming = false">Go back</Button>
            <Button type="submit" form="dtr-entries-form" :loading="adjustForm.processing" :disabled="!adjustForm.certify">Yes, save them</Button>
        </template>
    </Modal>

    <Modal :open="picking !== null" title="Who verifies this DTR?" icon="pencil" @close="picking = null">
        <form id="dtr-signatory-form" class="space-y-3" @submit.prevent="savePick">
            <p v-if="picking" class="text-sm text-text">
                Choose the supervisor or office head who verifies your {{ picking.period }} record. Their name is printed under “Office Head Name/Signature”, with their title (Atty., Dr., Engr. and so on) if their account has one.
            </p>
            <Combobox id="dtr-signatory" v-model="pickForm.signatory_id" inline label="Verified by" :options="signatoryOptions" placeholder="Type a name or position" :invalid="!!pickForm.errors.signatory_id" />
            <p class="text-xs text-text-muted">Leave it empty to use the office head. If you had already signed this sheet, sign it again after changing who verifies it.</p>
            <InputError :message="pickForm.errors.signatory_id || pickForm.errors.to || pickForm.errors.from" />
        </form>
        <template #footer>
            <Button variant="secondary" icon="close" @click="picking = null">Cancel</Button>
            <Button type="submit" form="dtr-signatory-form" icon="check" :loading="pickForm.processing">Save</Button>
        </template>
    </Modal>

    <DtrPrintChoice :url="printing ? printUrl(printing) : null" :period="printing?.period ?? ''" @close="printing = null" />

    <Modal :open="signing !== null" title="Sign your DTR" icon="pencil" tone="success" @close="signing = null">
        <form id="dtr-sign-form" class="space-y-3" @submit.prevent="submit">
            <p v-if="signing" class="text-sm text-text">
                You are certifying on your honor that the {{ signing.period }} record above is a true and correct report of the hours of work you performed. Your saved e-signature and a validation code go on the printed form. If HRD changes the sheet afterwards, the printed copy says it has changed.
            </p>
            <TextInput id="dtr-sign-password" v-model="form.password" type="password" label="Your password" required autocomplete="current-password" :error="form.errors.password" />
            <InputError :message="form.errors.to || form.errors.from || form.errors.signature" />
        </form>
        <template #footer>
            <Button variant="secondary" icon="close" @click="signing = null">Cancel</Button>
            <Button type="submit" form="dtr-sign-form" variant="success" :loading="form.processing">Sign</Button>
        </template>
    </Modal>
</template>
