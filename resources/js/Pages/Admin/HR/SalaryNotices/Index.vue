<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Tabs from '@cscro8/ui/Components/UI/Tabs.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * Notices of Salary Adjustment (NOSA) and of Step Increment (NOSI). HRD
 * generates them, prints them on the office's templates (signature space
 * left blank), has them signed, then files the signed copies in the 201
 * File, one at a time or many at once by HRD's file names. All figures
 * and wording come from the server.
 */
const props = defineProps({
    tab: { type: String, required: true },
    filters: { type: Object, required: true },
    years: { type: Array, required: true },
    notices: { type: Array, required: true },
    signatories: { type: Array, required: true },
    schedules: { type: Array, required: true },
    nosa: { type: Object, default: null },
    due: { type: Array, required: true },
    recorded: { type: Array, required: true },
    today: { type: String, required: true },
});

const visit = (query) => router.get('/admin/hr/salary-notices', query, { preserveScroll: true });
const currentTab = computed({
    get: () => props.tab,
    set: (key) => visit({ tab: key }),
});
const tabs = [
    { key: 'notices', label: 'Notices', icon: 'documentText' },
    { key: 'nosa', label: 'Generate NOSA', icon: 'plus' },
    { key: 'nosi', label: 'Step increments due', icon: 'clock' },
];

const signatoryOptions = computed(() => props.signatories.map((option) => ({ value: option.value, label: option.name ? `${option.name} — ${option.label}` : `${option.label} (nobody holds the item)` })));
const printUrl = (ids) => `/admin/hr/salary-notices/print?${ids.map((id) => `ids[]=${id}`).join('&')}`;
const openPrint = (ids) => window.open(printUrl(ids), '_blank', 'noopener');

// ── The register ─────────────────────────────────────────────────────
const selected = ref([]);
const yearOptions = computed(() => props.years.map((year) => ({ value: year, label: String(year) })));
const typeOptions = [
    { value: '', label: 'NOSA and NOSI' },
    { value: 'nosa', label: 'NOSA only' },
    { value: 'nosi', label: 'NOSI only' },
];
const filterBy = (changes) => visit({ tab: 'notices', year: props.filters.year, type: props.filters.type ?? '', ...changes });

const columns = [
    { key: 'name', label: 'Employee' },
    { key: 'type_code', label: 'Notice' },
    { key: 'effective_date', label: 'Effective' },
    { key: 'change', label: 'Salary', sortable: false },
    { key: 'state', label: 'Signed copy', value: (row) => (row.filed ? 'In the 201 File' : 'Not yet filed') },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '9rem' },
];

const destroy = async (row) => {
    const extra = row.type === 'nosi' ? ' The step increment stays on the Plantilla.' : '';
    if (await confirm({ title: `Delete the ${row.type_code} of ${row.name}?`, text: `You can generate it again.${extra}`, tone: 'danger', confirmText: 'Delete' })) {
        router.delete(`/admin/hr/salary-notices/${row.id}`, { preserveScroll: true });
    }
};

// One signed copy.
const filing = ref(null);
const fileForm = useForm({ file: null });
const startFiling = (row) => {
    fileForm.reset();
    fileForm.clearErrors();
    filing.value = row;
};
const submitFile = () =>
    fileForm.post(`/admin/hr/salary-notices/${filing.value.id}/file`, { preserveScroll: true, forceFormData: true, onSuccess: () => (filing.value = null) });

// Many signed copies, matched by file name.
const bulkOpen = ref(false);
const bulkForm = useForm({ files: [] });
const submitBulk = () =>
    bulkForm.post('/admin/hr/salary-notices/file', { preserveScroll: true, forceFormData: true, onSuccess: () => (bulkOpen.value = false) });

// ── NOSA batch ───────────────────────────────────────────────────────
const scheduleOptions = computed(() => props.schedules.map((schedule) => ({ value: schedule.id, label: `${schedule.name} (from ${formatCalendarDate(schedule.effective_date)})` })));
const schedule = computed(() => props.schedules.find((item) => item.id === props.nosa?.schedule_id) ?? null);
const scheduleReady = computed(() => Boolean(schedule.value?.reference && schedule.value?.issuance_date));
const nosaForm = useForm({ schedule_id: props.nosa?.schedule_id ?? null, notice_date: props.today, signatory: 'director_iv' });
const pickSchedule = (id) => visit({ tab: 'nosa', schedule: id });
const generateNosa = () => nosaForm.post('/admin/hr/salary-notices/nosa', { preserveScroll: true });

const readyColumns = [
    { key: 'name', label: 'Employee' },
    { key: 'grade', label: 'SG / Step', value: (row) => `${row.salary_grade} / ${row.step}` },
    { key: 'from_salary', label: 'As of the day before', align: 'right' },
    { key: 'to_salary', label: 'Adjusted', align: 'right' },
];
const skippedColumns = [
    { key: 'name', label: 'Employee' },
    { key: 'reason', label: 'Why no notice' },
];

// ── NOSI ─────────────────────────────────────────────────────────────
const dueColumns = [
    { key: 'name', label: 'Employee' },
    { key: 'grade', label: 'SG / Step', value: (row) => `${row.salary_grade} / ${row.step} → ${row.next_step}` },
    { key: 'due', label: 'Due' },
    { key: 'next_salary', label: 'New salary', align: 'right' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '6rem' },
];
const recordedColumns = [
    { key: 'name', label: 'Employee' },
    { key: 'grade', label: 'SG / Step', value: (row) => `${row.salary_grade} / ${row.step}` },
    { key: 'effective_date', label: 'Effective' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '6rem' },
];

const nosiRow = ref(null);
const nosiForm = useForm({ appointment_id: null, record: true, effective_date: '', notice_date: props.today, signatory: 'director_iv' });
const startNosi = (row, record) => {
    nosiForm.reset();
    nosiForm.clearErrors();
    nosiForm.appointment_id = row.appointment_id;
    nosiForm.record = record;
    nosiForm.effective_date = record ? row.due : row.effective_date;
    nosiRow.value = { ...row, record };
};
const submitNosi = () => nosiForm.post('/admin/hr/salary-notices/nosi', { preserveScroll: true, onSuccess: () => (nosiRow.value = null) });
</script>

<template>
    <Head title="NOSA & NOSI" />

    <PageHeader
        title="NOSA & NOSI"
        description="Generate Notices of Salary Adjustment and of Step Increment, print them for signing, then file the signed copies in each employee's 201 File."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'NOSA & NOSI' }]"
    />

    <Tabs v-model="currentTab" :tabs="tabs">
        <!-- The register -->
        <template #notices>
            <Card title="Notices" icon="documentText" description="Printing someone else's notice is logged. Directors' notices come from the Central Office.">
                <template #actions>
                    <Select id="notice-year" :model-value="filters.year" :options="yearOptions" aria-label="Year" @update:model-value="(year) => filterBy({ year })" />
                    <Select id="notice-type" :model-value="filters.type ?? ''" :options="typeOptions" aria-label="Notice" @update:model-value="(type) => filterBy({ type })" />
                    <Button variant="secondary" icon="upload" @click="bulkOpen = true">File signed copies</Button>
                </template>

                <DataTable
                    v-model:selected="selected"
                    :columns="columns"
                    :rows="notices"
                    selectable
                    caption="Salary notices"
                    :export-name="`imis-salary-notices-${filters.year}`"
                    empty-text="No notices for this year yet."
                >
                    <template #bulk="{ selected: ids, clear }">
                        <Button icon="printer" @click="openPrint(ids)">Print {{ ids.length }}</Button>
                        <Button variant="ghost" @click="clear">Clear</Button>
                    </template>
                    <template #cell-name="{ row }"><PersonCell :name="row.name" :photo-url="row.photo_url" :subtitle="row.position" /></template>
                    <template #cell-effective_date="{ row }"><span class="whitespace-nowrap">{{ formatCalendarDate(row.effective_date) }}</span></template>
                    <template #cell-change="{ row }">
                        <span class="whitespace-nowrap">{{ formatPeso(row.from_salary) }} → {{ formatPeso(row.to_salary) }}</span>
                        <p class="text-xs text-text-muted">SG {{ row.salary_grade }}, Step {{ row.from_step }}{{ row.to_step !== row.from_step ? ` → ${row.to_step}` : '' }} · +{{ formatPeso(row.difference) }}</p>
                    </template>
                    <template #cell-state="{ row }">
                        <StatusBadge :status="row.filed ? 'active' : 'warning'" :label="row.filed ? 'In the 201 File' : 'Not yet filed'" />
                    </template>
                    <template #cell-actions="{ row }">
                        <div class="flex justify-center gap-1">
                            <ActionButton icon="printer" tone="neutral" :label="`Print the ${row.type_code} of ${row.name}`" :href="printUrl([row.id])" external />
                            <template v-if="!row.filed && !row.own">
                                <ActionButton icon="upload" tone="primary" :label="`File the signed ${row.type_code} of ${row.name}`" @click="startFiling(row)" />
                                <ActionButton icon="trash" tone="danger" :label="`Delete the ${row.type_code} of ${row.name}`" @click="destroy(row)" />
                            </template>
                            <ActionButton v-if="row.filed" icon="eye" tone="neutral" :label="`Open the 201 File of ${row.name}`" :href="`/admin/hr/employees/${row.employee_id}/documents`" />
                        </div>
                    </template>
                </DataTable>
            </Card>
        </template>

        <!-- A NOSA for everyone when a salary schedule takes effect -->
        <template #nosa>
            <Card title="Generate the NOSA batch" icon="plus" description="One notice for everyone holding a plantilla item when the schedule takes effect: the same grade and step at the new rate (NBC §5.1).">
                <div v-if="!schedules.length" class="text-sm text-text-muted">
                    No salary schedules yet. <Link href="/admin/hr/salary-schedules" class="text-link hover:underline">Enter the new schedule</Link> from the issuance first.
                </div>
                <!-- A narrow date column, so the signatory's name and position show in full. -->
                <form v-else class="grid gap-4 sm:grid-cols-[minmax(0,1fr)_11rem_minmax(0,1.6fr)]" @submit.prevent="generateNosa">
                    <div>
                        <InputLabel for="nosa-schedule">Salary schedule</InputLabel>
                        <Select id="nosa-schedule" v-model="nosaForm.schedule_id" class="mt-1 block w-full" :options="scheduleOptions" @update:model-value="pickSchedule" />
                        <InputError :message="nosaForm.errors.schedule || nosaForm.errors.schedule_id" />
                    </div>
                    <div>
                        <TextInput id="nosa-date" v-model="nosaForm.notice_date" type="date" label="Date of the notices" required :error="nosaForm.errors.notice_date" />
                    </div>
                    <div>
                        <InputLabel for="nosa-signatory">Signatory</InputLabel>
                        <Select id="nosa-signatory" v-model="nosaForm.signatory" class="mt-1 block w-full" :options="signatoryOptions" />
                        <InputError :message="nosaForm.errors.signatory" />
                    </div>

                    <p v-if="schedule && scheduleReady" class="text-sm text-text-muted sm:col-span-3">
                        The notices will read: “Pursuant to {{ schedule.reference }} dated {{ formatCalendarDate(schedule.issuance_date) }}, implementing {{ schedule.implements || 'Executive Order No. 64 s. 2024 dated 02 August 2024' }}, your salary is hereby adjusted effective {{ formatCalendarDate(schedule.effective_date) }} …”
                    </p>
                    <p v-else-if="schedule" class="rounded-md border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text sm:col-span-3">
                        The NOSA quotes the issuance. Enter this schedule's reference and the date of the issuance under
                        <Link href="/admin/hr/salary-schedules" class="font-medium text-link hover:underline">Salary Schedules</Link> first.
                    </p>

                    <div class="sm:col-span-3">
                        <Button type="submit" icon="plus" :loading="nosaForm.processing" :disabled="!scheduleReady || !nosa?.ready.length">
                            Generate {{ nosa?.ready.length ?? 0 }} notices
                        </Button>
                    </div>
                </form>
            </Card>

            <Card v-if="nosa" :title="`Ready (${nosa.ready.length})`" icon="checkCircle" class="mt-6">
                <DataTable :columns="readyColumns" :rows="nosa.ready" row-key="appointment_id" caption="Ready for a notice" :exportable="false" empty-text="Nobody is ready under this schedule.">
                    <template #cell-name="{ row }"><PersonCell :name="row.name" :photo-url="row.photo_url" :subtitle="`${row.position}${row.item_number ? ' · ' + row.item_number : ''}`" /></template>
                    <template #cell-from_salary="{ row }">{{ formatPeso(row.from_salary) }}</template>
                    <template #cell-to_salary="{ row }">{{ formatPeso(row.to_salary) }}</template>
                </DataTable>
            </Card>

            <Card v-if="nosa?.skipped.length" :title="`No notice (${nosa.skipped.length})`" icon="exclamation" class="mt-6" description="Not in this batch. Fix the Plantilla or the schedule and come back, or prepare these by hand.">
                <DataTable :columns="skippedColumns" :rows="nosa.skipped" row-key="appointment_id" caption="Left out of the batch" :exportable="false">
                    <template #cell-name="{ row }"><PersonCell :name="row.name" :photo-url="row.photo_url" :subtitle="row.position" /></template>
                </DataTable>
            </Card>
        </template>

        <!-- NOSI: step increments due for length of service -->
        <template #nosi>
            <Card
                title="Due within 60 days"
                icon="clock"
                description="Three years in the present position, moved back by leave without pay over 15 days. Check the ratings and any administrative case before recording: IMIS can't see those."
            >
                <DataTable :columns="dueColumns" :rows="due" row-key="appointment_id" caption="Step increments due" export-name="imis-step-increments-due" empty-text="Nobody is due in the next 60 days.">
                    <template #cell-name="{ row }"><PersonCell :name="row.name" :photo-url="row.photo_url" :subtitle="`${row.position}${row.item_number ? ' · ' + row.item_number : ''}`" /></template>
                    <template #cell-due="{ row }">
                        <span class="whitespace-nowrap" :class="{ 'font-semibold text-warning-fg': row.overdue }">{{ formatCalendarDate(row.due) }}</span>
                        <p class="text-xs text-text-muted">{{ row.working }}</p>
                    </template>
                    <template #cell-next_salary="{ row }">
                        <span class="whitespace-nowrap">{{ row.next_salary !== null ? formatPeso(row.next_salary) : '—' }}</span>
                        <p v-if="row.salary !== null" class="text-xs text-text-muted">now {{ formatPeso(row.salary) }}</p>
                    </template>
                    <template #cell-actions="{ row }">
                        <ActionButton v-if="row.user_id !== $page.props.auth.user.id" icon="plus" tone="primary" :label="`Record the step increment of ${row.name} and generate the NOSI`" @click="startNosi(row, true)" />
                    </template>
                </DataTable>
            </Card>

            <Card title="Recorded without a notice" icon="documentText" class="mt-6" description="Step increments recorded on the Plantilla in the last year that have no NOSI yet.">
                <DataTable :columns="recordedColumns" :rows="recorded" row-key="appointment_id" caption="Step increments without a notice" :exportable="false" empty-text="Every recent step increment has its notice.">
                    <template #cell-name="{ row }"><PersonCell :name="row.name" :photo-url="row.photo_url" :subtitle="row.position" /></template>
                    <template #cell-effective_date="{ row }"><span class="whitespace-nowrap">{{ formatCalendarDate(row.effective_date) }}</span></template>
                    <template #cell-actions="{ row }">
                        <ActionButton v-if="row.user_id !== $page.props.auth.user.id" icon="documentText" tone="primary" :label="`Generate the NOSI of ${row.name}`" @click="startNosi(row, false)" />
                    </template>
                </DataTable>
            </Card>
        </template>
    </Tabs>

    <!-- NOSI: record and generate, or generate for a recorded increment -->
    <Modal :open="nosiRow !== null" :title="nosiRow?.record ? 'Record the step increment' : 'Generate the NOSI'" icon="documentText" @close="nosiRow = null">
        <form v-if="nosiRow" id="nosi-form" class="grid gap-4" @submit.prevent="submitNosi">
            <p class="text-sm text-text">
                <strong>{{ nosiRow.name }}</strong>, {{ nosiRow.position }}, SG {{ nosiRow.salary_grade }}:
                <template v-if="nosiRow.record">Step {{ nosiRow.step }} → Step {{ nosiRow.next_step }}. The Plantilla records it from the effective date.</template>
                <template v-else>Step {{ nosiRow.step }} from {{ formatCalendarDate(nosiRow.effective_date) }}.</template>
            </p>
            <div v-if="nosiRow.record">
                <TextInput id="nosi-effective" v-model="nosiForm.effective_date" type="date" label="Effective date" required :error="nosiForm.errors.effective_date || nosiForm.errors.date_from || nosiForm.errors.step" />
                <p class="mt-1 text-xs text-text-muted">{{ nosiRow.working }}</p>
            </div>
            <div>
                <TextInput id="nosi-date" v-model="nosiForm.notice_date" type="date" label="Date of the notice" required :error="nosiForm.errors.notice_date" />
            </div>
            <div>
                <InputLabel for="nosi-signatory">Signatory</InputLabel>
                <Select id="nosi-signatory" v-model="nosiForm.signatory" class="mt-1 block w-full" :options="signatoryOptions" />
                <InputError :message="nosiForm.errors.signatory || nosiForm.errors.appointment_id" />
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" @click="nosiRow = null">Cancel</Button>
            <Button type="submit" form="nosi-form" :loading="nosiForm.processing">{{ nosiRow?.record ? 'Record and generate' : 'Generate' }}</Button>
        </template>
    </Modal>

    <!-- One signed copy -->
    <Modal :open="filing !== null" title="File the signed copy" icon="upload" @close="filing = null">
        <form v-if="filing" id="file-form" class="grid gap-3" @submit.prevent="submitFile">
            <p class="text-sm text-text">The signed {{ filing.type_code }} of <strong>{{ filing.name }}</strong> goes to their 201 File, where they can open it.</p>
            <div>
                <InputLabel for="file-input">Signed copy (PDF, JPG or PNG, up to 10 MB)</InputLabel>
                <input id="file-input" type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm" required @change="fileForm.file = $event.target.files[0]" />
                <InputError :message="fileForm.errors.file" />
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" @click="filing = null">Cancel</Button>
            <Button type="submit" form="file-form" :loading="fileForm.processing">File in the 201 File</Button>
        </template>
    </Modal>

    <!-- Many signed copies -->
    <Modal :open="bulkOpen" title="File signed copies" icon="upload" @close="bulkOpen = false">
        <form id="bulk-form" class="grid gap-3" @submit.prevent="submitBulk">
            <p class="text-sm text-text">
                Choose the signed files. Each is matched to its notice by name, the way the print page names them:
                <code>NOSA_Uy, Rey Albert.pdf</code>, <code>NOSI_Aguillon, Otelia Fe.pdf</code>. Files that match no notice are listed so you can file them one by one.
            </p>
            <div>
                <InputLabel for="bulk-input">Signed copies (PDF, JPG or PNG, up to 10 MB each)</InputLabel>
                <input id="bulk-input" type="file" multiple accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm" required @change="bulkForm.files = Array.from($event.target.files)" />
                <InputError :message="bulkForm.errors.files || Object.entries(bulkForm.errors).find(([key]) => key.startsWith('files.'))?.[1]" />
            </div>
        </form>
        <template #footer>
            <Button variant="ghost" @click="bulkOpen = false">Cancel</Button>
            <Button type="submit" form="bulk-form" :loading="bulkForm.processing">File {{ bulkForm.files.length || '' }} copies</Button>
        </template>
    </Modal>
</template>
