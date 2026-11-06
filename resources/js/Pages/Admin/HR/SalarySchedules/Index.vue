<script setup>
import InputLabel from '../../../../Components/Forms/InputLabel.vue';
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import Modal from '../../../../Components/Feedback/Modal.vue';
import TextInput from '../../../../Components/Forms/TextInput.vue';
import Select from '../../../../Components/Forms/Select.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import { confirm } from '../../../../Composables/useDialog';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-4: salary schedules — the grade × step monthly salaries as issued
 * (e.g. the 3rd tranche, 2026, under NBC No. 601). HR types the amounts
 * in, or pastes the table copied from the issuance or a spreadsheet. The
 * schedule in force prices the plantilla.
 */
const props = defineProps({
    schedules: { type: Array, required: true },
    selectedId: { type: Number, default: null },
    grid: { type: Object, default: null },
    grades: { type: Number, required: true },
    steps: { type: Number, required: true },
    canManage: { type: Boolean, required: true },
});

const selected = computed(() => props.schedules.find((schedule) => schedule.id === props.selectedId) ?? null);
const gradeList = computed(() => Array.from({ length: props.grades }, (_, index) => index + 1));
const stepList = computed(() => Array.from({ length: props.steps }, (_, index) => index + 1));

const pick = (id) => router.get('/admin/hr/salary-schedules', { schedule: id }, { preserveScroll: true });

// ── Editing the table ────────────────────────────────────────────────
const editing = ref(false);
const table = useForm({ name: '', reference: '', effective_date: '', remarks: '', rates: {} });

const loadTable = () => {
    if (!selected.value) return;
    const rates = {};
    gradeList.value.forEach((grade) => {
        rates[grade] = {};
        stepList.value.forEach((step) => (rates[grade][step] = props.grid?.[grade]?.[step] ?? ''));
    });
    table.defaults({ ...selected.value, remarks: selected.value.remarks ?? '', reference: selected.value.reference ?? '', rates });
    table.reset();
    table.clearErrors();
};

watch(() => props.selectedId, () => (editing.value = false));

const startEditing = () => {
    loadTable();
    editing.value = true;
};

const saveTable = () =>
    table
        .transform((data) => ({
            ...data,
            rates: Object.fromEntries(Object.entries(data.rates).map(([grade, steps]) => [grade, Object.fromEntries(Object.entries(steps).map(([step, value]) => [step, value === '' ? null : value]))])),
        }))
        .put(`/admin/hr/salary-schedules/${props.selectedId}`, { preserveScroll: true, onSuccess: () => (editing.value = false) });

// Paste: one line per grade (SG 1 first), eight amounts per line, as copied
// from the issuance or a spreadsheet. A leading grade number is ignored;
// thousands separators are fine.
const pasteOpen = ref(false);
const pasted = ref('');
const pasteError = ref('');
const applyPaste = () => {
    const lines = pasted.value.split(/\r?\n/).map((line) => line.trim()).filter(Boolean);
    let filled = 0;

    lines.forEach((line, index) => {
        let cells = line.split(/\t|\s{2,}|\s(?=\d)/).map((cell) => cell.replace(/[₱,\s]/g, '')).filter((cell) => cell !== '' && !Number.isNaN(Number(cell)));
        let grade = index + 1;
        if (cells.length === props.steps + 1) {
            grade = Number(cells[0]);
            cells = cells.slice(1);
        }
        if (!table.rates[grade]) return;
        cells.slice(0, props.steps).forEach((value, step) => {
            table.rates[grade][step + 1] = value;
            filled++;
        });
    });

    if (filled === 0) {
        pasteError.value = "Couldn't read any amounts. Paste one line per salary grade with its eight steps.";
        return;
    }
    pasteError.value = '';
    pasteOpen.value = false;
    pasted.value = '';
};

// ── A new schedule ───────────────────────────────────────────────────
const createOpen = ref(false);
const create = useForm({ name: '', reference: '', effective_date: '', remarks: '', copy_from: '' });
const copyOptions = computed(() => [{ value: '', label: 'Start empty' }, ...props.schedules.map((schedule) => ({ value: schedule.id, label: `Copy ${schedule.name}` }))]);
const openCreate = () => {
    create.reset();
    create.clearErrors();
    createOpen.value = true;
};
const submitCreate = () =>
    create
        .transform((data) => ({ ...data, copy_from: data.copy_from || null, reference: data.reference || null, remarks: data.remarks || null }))
        .post('/admin/hr/salary-schedules', { preserveScroll: true, onSuccess: () => (createOpen.value = false) });

const destroy = async () => {
    if (await confirm({ title: `Delete ${selected.value.name}?`, text: 'Its amounts are deleted too. Salaries are then priced by the schedule in force before it.', tone: 'danger', confirmText: 'Delete' })) {
        router.delete(`/admin/hr/salary-schedules/${props.selectedId}`);
    }
};

const cellClass = 'w-28 rounded-sm border border-border bg-surface px-2 py-1 text-right text-sm tabular-nums focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring';
</script>

<template>
    <Head title="Salary Schedules" />

    <PageHeader title="Salary Schedules" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Salary Schedules' }]">
        <template v-if="canManage" #actions>
            <Button variant="success" @click="openCreate"><Icon name="plus" size="sm" />New schedule</Button>
        </template>
    </PageHeader>

    <div class="grid gap-6 xl:grid-cols-4">
        <Card title="Schedules" icon="database" class="xl:col-span-1">
            <p v-if="schedules.length === 0" class="text-sm text-text-muted">
                None yet. Add the schedule in force (e.g. the 3rd tranche, 2026, NBC No. 601) and enter its amounts.
            </p>
            <ul v-else class="divide-y divide-border">
                <li v-for="schedule in schedules" :key="schedule.id">
                    <button
                        type="button"
                        class="w-full rounded-md px-2 py-2.5 text-left hover:bg-surface-muted"
                        :class="schedule.id === selectedId ? 'bg-info-bg' : ''"
                        :aria-current="schedule.id === selectedId ? 'true' : undefined"
                        @click="pick(schedule.id)"
                    >
                        <span class="block font-medium text-text">{{ schedule.name }}</span>
                        <span class="block text-xs text-text-muted">
                            {{ schedule.reference ? `${schedule.reference} · ` : '' }}from {{ formatCalendarDate(schedule.effective_date) }} · {{ schedule.cells }} amounts
                        </span>
                        <StatusBadge v-if="schedule.in_force" status="active" label="In Force" class="mt-1" />
                    </button>
                </li>
            </ul>
        </Card>

        <Card
            v-if="selected"
            :title="selected.name"
            icon="grid"
            :description="`${selected.reference ? selected.reference + ' · ' : ''}Monthly salary by grade and step, in force from ${formatCalendarDate(selected.effective_date)}`"
            class="xl:col-span-3"
        >
            <template v-if="canManage" #actions>
                <template v-if="editing">
                    <Button variant="secondary" @click="pasteOpen = true">Paste table</Button>
                    <Button variant="secondary" @click="editing = false">Cancel</Button>
                    <Button :loading="table.processing" @click="saveTable">Save</Button>
                </template>
                <template v-else>
                    <Button variant="secondary" @click="startEditing"><Icon name="pencil" size="sm" />Edit</Button>
                    <Button variant="danger" @click="destroy">Delete</Button>
                </template>
            </template>

            <div v-if="editing" class="mb-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <TextInput id="schedule-name" v-model="table.name" label="Name" required :invalid="!!table.errors.name" />
                    <InputError :message="table.errors.name" />
                </div>
                <div>
                    <TextInput id="schedule-reference" v-model="table.reference" label="Reference (e.g. NBC No. 601)" :invalid="!!table.errors.reference" />
                    <InputError :message="table.errors.reference" />
                </div>
                <div>
                    <TextInput id="schedule-effective" v-model="table.effective_date" type="date" label="Effective date" required :invalid="!!table.errors.effective_date" />
                    <InputError :message="table.errors.effective_date" />
                </div>
            </div>

            <p v-if="!editing && selected.cells === 0" class="mb-4 rounded-md border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text">
                No amounts yet. Choose <strong>Edit</strong>, then type them in or paste the table from the issuance.
            </p>
            <InputError v-if="Object.keys(table.errors).some((key) => key.startsWith('rates'))" message="Some amounts aren't valid numbers." />

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">{{ selected.name }}: monthly salary by salary grade and step</caption>
                    <thead class="text-xs text-text-muted">
                        <tr>
                            <th scope="col" class="py-2 pr-3 text-left font-medium">SG</th>
                            <th v-for="step in stepList" :key="step" scope="col" class="py-2 pr-3 text-right font-medium">Step {{ step }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="grade in gradeList" :key="grade">
                            <th scope="row" class="py-1.5 pr-3 text-left font-medium text-text">{{ grade }}</th>
                            <td v-for="step in stepList" :key="step" class="py-1.5 pr-3 text-right tabular-nums text-text">
                                <input
                                    v-if="editing"
                                    v-model="table.rates[grade][step]"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    :aria-label="`Salary grade ${grade}, step ${step}`"
                                    :class="cellClass"
                                />
                                <template v-else>{{ grid?.[grade]?.[step] != null ? formatPeso(grid[grade][step]) : '—' }}</template>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>

    <Modal :open="pasteOpen" title="Paste the salary table" icon="clipboard" size="lg" @close="pasteOpen = false">
        <p class="mb-2 text-sm text-text">
            Copy the table from the issuance or a spreadsheet: one line per salary grade (SG 1 first), with the eight steps. A grade number at the start of a line is fine.
            Check the amounts against the issuance before saving.
        </p>
        <label for="paste-table" class="sr-only">Salary table</label>
        <textarea
            id="paste-table"
            v-model="pasted"
            rows="12"
            class="block w-full rounded-md border border-border-strong bg-surface px-3 py-2 font-mono text-xs text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
        />
        <InputError :message="pasteError" />
        <template #footer>
            <Button variant="secondary" @click="pasteOpen = false">Cancel</Button>
            <Button @click="applyPaste">Fill the table</Button>
        </template>
    </Modal>

    <Modal :open="createOpen" title="New salary schedule" icon="plus" tone="success" @close="createOpen = false">
        <form id="schedule-create-form" class="grid gap-4" @submit.prevent="submitCreate">
            <div>
                <TextInput id="new-name" v-model="create.name" label="Name (e.g. Third tranche, 2026)" required :invalid="!!create.errors.name" />
                <InputError :message="create.errors.name" />
            </div>
            <div>
                <TextInput id="new-reference" v-model="create.reference" label="Reference (e.g. NBC No. 601)" :invalid="!!create.errors.reference" />
                <InputError :message="create.errors.reference" />
            </div>
            <div>
                <TextInput id="new-effective" v-model="create.effective_date" type="date" label="Effective date" required :invalid="!!create.errors.effective_date" />
                <InputError :message="create.errors.effective_date" />
            </div>
            <div>
                <InputLabel for="new-copy">Amounts</InputLabel>
                <Select id="new-copy" v-model="create.copy_from" class="mt-1 block w-full" :options="copyOptions" />
                <InputError :message="create.errors.copy_from" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="createOpen = false">Cancel</Button>
            <Button type="submit" form="schedule-create-form" variant="success" :loading="create.processing">Create</Button>
        </template>
    </Modal>
</template>
