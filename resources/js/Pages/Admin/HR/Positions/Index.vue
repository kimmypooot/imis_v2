<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import ActionButton from '../../../../Components/UI/ActionButton.vue';
import Card from '../../../../Components/UI/Card.vue';
import DataTable from '../../../../Components/UI/DataTable.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import { confirm } from '../../../../Composables/useDialog';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-4: each position title's salary grade, level and monthly salary, and
 * its plantilla items with their item numbers and places of assignment.
 * Items of the same title follow the position, so a grade entered (or
 * changed) here adjusts every one of those items and their salary.
 */
const props = defineProps({
    positions: { type: Array, required: true },
    schedule: { type: Object, default: null },
    canManage: { type: Boolean, required: true },
    form: { type: Object, default: null },
});

// "Add item" on a position: saved through the plantilla, staying on this page.
const itemFor = ref(null);
const itemOpen = ref(false);
const addItem = (position) => {
    itemFor.value = position;
    itemOpen.value = true;
};
const itemDefaults = computed(() => ({
    position_title: itemFor.value?.title ?? '',
    item_number: '',
    organizational_unit_id: '',
    authority: '',
    remarks: '',
    stay: true,
}));
const itemFields = computed(() =>
    props.form
        ? [
              { key: 'item_number', label: 'Item number', help: 'Leave blank if it isn’t known yet.' },
              { key: 'organizational_unit_id', label: 'Place of assignment', type: 'select', options: [{ value: '', label: 'Not set' }, ...props.form.officeOptions] },
              { key: 'authority', label: 'Authority (e.g. DBM-approved PSIPOP)', span: 'full' },
              { key: 'remarks', label: 'Remarks', type: 'textarea' },
          ]
        : [],
);

const missingGrade = computed(() => props.positions.filter((position) => position.salary_grade === null).length);

const open = ref(false);
const editing = ref(null);
const edit = (position = null) => {
    editing.value = position;
    open.value = true;
};

const addFromStaff = () => router.post('/admin/hr/positions/from-staff', {}, { preserveScroll: true });

const remove = async (position) => {
    if (await confirm({ title: `Delete ${position.title}?`, text: 'Only a position no plantilla item uses can be deleted.', tone: 'danger', confirmText: 'Delete' })) {
        router.delete(`/admin/hr/positions/${position.id}`, { preserveScroll: true });
    }
};

const fields = computed(() =>
    props.form
        ? [
              { key: 'title', label: 'Position title', required: true, span: 'full', help: 'Renaming it renames the plantilla items of this position too.' },
              {
                  key: 'salary_grade',
                  label: 'Salary grade',
                  type: 'select',
                  options: [{ value: '', label: 'Not set' }, ...props.form.gradeOptions],
                  help: 'Every plantilla item of this position takes this grade.',
              },
              { key: 'level', label: 'Level', type: 'select', options: [{ value: '', label: 'Not set' }, ...props.form.levelOptions] },
              { key: 'remarks', label: 'Remarks', type: 'textarea' },
          ]
        : [],
);

const columns = [
    { key: 'title', label: 'Position' },
    { key: 'salary_grade', label: 'SG', align: 'right', value: (row) => row.salary_grade ?? '' },
    { key: 'monthly_salary', label: 'Monthly salary', align: 'right', value: (row) => row.monthly_salary ?? '' },
    { key: 'level_label', label: 'Level', value: (row) => row.level_label ?? '' },
    {
        key: 'items',
        label: 'Item no. and place of assignment',
        sortable: false,
        value: (row) => row.items.map((item) => `${item.item_number ?? 'No item no.'} (${item.office ?? 'no office'})`).join('; '),
    },
    { key: 'staff_count', label: 'Staff with this title', align: 'right' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center' },
];
</script>

<template>
    <Head title="Positions" />

    <PageHeader title="Positions" :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Positions' }]">
        <template v-if="canManage" #actions>
            <Button variant="secondary" @click="addFromStaff"><Icon name="users" size="sm" />Add titles from staff</Button>
            <Button variant="success" @click="edit()"><Icon name="plus" size="sm" />Add position</Button>
        </template>
    </PageHeader>

    <Card title="Positions and salary grades" icon="clipboard" description="Plantilla items follow the grade and level of their position.">
        <p v-if="missingGrade" class="mb-4 rounded-md bg-warning-bg px-3 py-2 text-sm text-text">
            {{ missingGrade }} {{ missingGrade === 1 ? 'position has' : 'positions have' }} no salary grade yet, so their plantilla items can't be priced.
        </p>
        <p v-if="!schedule" class="mb-4 rounded-md bg-warning-bg px-3 py-2 text-sm text-text">
            No salary schedule is in force yet, so monthly salaries can't be shown. Enter the NBC amounts under
            <Link href="/admin/hr/salary-schedules" class="font-medium text-link hover:underline">Salary Schedules</Link>.
        </p>
        <p v-else class="mb-4 text-xs text-text-muted">Monthly salary is step 1 of the grade under {{ schedule.name }}.</p>

        <DataTable
            :columns="columns"
            :rows="positions"
            caption="Positions"
            export-name="imis-positions"
            :default-sort="{ key: 'title', direction: 'asc' }"
            empty-text="No positions yet. Use “Add titles from staff” to start from the titles on staff accounts."
        >
            <template #cell-title="{ row }">
                {{ row.title }}
                <div v-if="row.remarks" class="text-xs text-text-muted">{{ row.remarks }}</div>
            </template>
            <template #cell-salary_grade="{ row }">
                <span v-if="row.salary_grade" class="tabular-nums">{{ row.salary_grade }}</span>
                <StatusBadge v-else status="warning" label="Not Set" />
            </template>
            <template #cell-monthly_salary="{ row }">
                <span v-if="row.monthly_salary !== null" class="tabular-nums">{{ formatPeso(row.monthly_salary) }}</span>
                <span v-else class="text-text-muted">—</span>
            </template>
            <template #cell-level_label="{ row }">{{ row.level_label || '—' }}</template>
            <template #cell-items="{ row }">
                <ul v-if="row.items.length" class="space-y-1">
                    <li v-for="item in row.items" :key="item.id" class="text-sm">
                        <Link :href="`/admin/hr/plantilla/${item.id}`" class="font-medium text-link hover:underline">{{ item.item_number || 'No item no.' }}</Link>
                        <span class="text-text-muted"> · </span>
                        <span :title="item.office_name">{{ item.office || 'No office' }}</span>
                        <span v-if="item.abolished" class="text-text-muted"> · abolished</span>
                        <span v-else class="text-text-muted"> · {{ item.holder || 'vacant' }}</span>
                        <span v-if="item.incomplete" class="text-xs text-warning-fg"> · incomplete</span>
                        <div v-if="item.serving?.elsewhere" class="text-xs text-info-fg">Serving at {{ item.serving.office }}<template v-if="item.serving.order_number"> ({{ item.serving.order_number }})</template></div>
                    </li>
                </ul>
                <span v-else class="text-sm text-text-muted">No item yet</span>
            </template>
            <template #cell-staff_count="{ row }">
                <span class="tabular-nums">{{ row.staff_count }}</span>
                <div v-if="row.staff_offices.length" class="text-xs text-text-muted">
                    {{ row.staff_offices.map((place) => (place.count > 1 ? `${place.office} (${place.count})` : place.office)).join(', ') }}
                </div>
            </template>
            <template #cell-actions="{ row }">
                <div v-if="canManage" class="flex justify-center gap-1">
                    <ActionButton icon="plus" tone="success" :label="`Add an item to ${row.title}`" @click="addItem(row)" />
                    <ActionButton icon="pencil" :label="`Edit ${row.title}`" @click="edit(row)" />
                    <ActionButton v-if="!row.items_count" icon="trash" tone="danger" :label="`Delete ${row.title}`" @click="remove(row)" />
                </div>
            </template>
        </DataTable>
    </Card>

    <RecordModal
        v-if="canManage"
        :open="open"
        noun="Position"
        :record="editing"
        :fields="fields"
        :defaults="{ title: '', salary_grade: '', level: '', remarks: '' }"
        :nullable="['salary_grade', 'level', 'remarks']"
        store-url="/admin/hr/positions"
        :update-url="(record) => `/admin/hr/positions/${record.id}`"
        @close="open = false"
    />

    <RecordModal
        v-if="canManage"
        :open="itemOpen"
        :noun="`${itemFor?.title ?? ''} item`"
        :record="null"
        :fields="itemFields"
        :defaults="itemDefaults"
        :nullable="['item_number', 'organizational_unit_id', 'authority', 'remarks']"
        store-url="/admin/hr/plantilla"
        :update-url="() => ''"
        @close="itemOpen = false"
    />
</template>
