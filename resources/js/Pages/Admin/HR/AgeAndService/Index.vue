<script setup>
import { computed, reactive } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * Everyone in service with their age, length of service (in government
 * and in the CSC), next birthday, retirement dates (60 optional, 65
 * compulsory, RA 8291) and next loyalty award. The HR dashboard's
 * birthday, retirement and loyalty cards link here with a view preset.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    asOf: { type: String, required: true },
    people: { type: Array, required: true },
    officeOptions: { type: Array, required: true },
});

const views = [
    { value: 'all', label: 'Everyone' },
    { value: 'birthdays_month', label: 'Birthdays this month' },
    { value: 'birthdays_30', label: 'Birthdays in the next 30 days' },
    { value: 'retiring_12', label: 'Reaching 60 or 65 in the next 12 months' },
    { value: 'retirement_age', label: 'Aged 60 and over' },
    { value: 'loyalty_year', label: 'Loyalty award this year' },
];

const draft = reactive({ view: props.filters.view, office: props.filters.office ?? '' });

const apply = () =>
    router.get(
        '/admin/hr/age-and-service',
        Object.fromEntries(Object.entries(draft).filter(([key, value]) => value !== null && value !== '' && !(key === 'view' && value === 'all'))),
        { preserveScroll: true },
    );

const viewLabel = computed(() => views.find((view) => view.value === props.filters.view)?.label ?? 'Everyone');

const retirementStatus = {
    past_compulsory: { label: 'Past 65', classes: 'bg-danger-bg text-danger-fg' },
    eligible: { label: 'Eligible (60+)', classes: 'bg-warning-bg text-warning-fg' },
};

const ordinal = (n) => `${n}${['th', 'st', 'nd', 'rd'][n % 100 > 10 && n % 100 < 14 ? 0 : n % 10 < 4 ? n % 10 : 0]}`;
const when = (days) => (days === 0 ? 'today' : days === 1 ? 'tomorrow' : `in ${days} days`);
// Months as a zero-padded number so the table sorts lengths of service correctly.
const months = (value) => (value === null ? '' : String(value).padStart(4, '0'));

// Five columns, each stacking its details, so the table fits the page
// without scrolling sideways. Each sorts on its main figure.
const columns = [
    { key: 'name', label: 'Employee', value: (row) => row.list_name ?? row.name },
    { key: 'age', label: 'Age · Birthday', value: (row) => row.age ?? '' },
    { key: 'government_months', label: 'Service', value: (row) => months(row.government_months) },
    { key: 'next_award_date', label: 'Next loyalty award', value: (row) => row.next_award_date ?? '' },
    { key: 'compulsory_retirement', label: 'Retirement', value: (row) => row.compulsory_retirement ?? '' },
];

const missingBirthdays = computed(() => props.people.filter((person) => !person.birthday).length);
</script>

<template>
    <Head title="Age & Service" />

    <PageHeader
        title="Age & Service"
        description="Everyone in service with their age, length of service, retirement dates and next loyalty award."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Age & Service' }]"
    />

    <div class="space-y-6">
        <Card title="Filters" icon="funnel">
            <form class="flex flex-wrap items-end gap-4" @submit.prevent="apply">
                <div>
                    <label for="filter-view" class="block text-xs font-medium text-text-muted">Show</label>
                    <select id="filter-view" v-model="draft.view" class="filter-control">
                        <option v-for="view in views" :key="view.value" :value="view.value">{{ view.label }}</option>
                    </select>
                </div>
                <div>
                    <label for="filter-office" class="block text-xs font-medium text-text-muted">Office</label>
                    <select id="filter-office" v-model="draft.office" class="filter-control">
                        <option value="">All offices</option>
                        <option v-for="office in officeOptions" :key="office.value" :value="office.value">{{ office.label }}</option>
                    </select>
                </div>
                <Button type="submit" icon="funnel">Apply</Button>
            </form>
        </Card>

        <Card
            :title="viewLabel"
            icon="users"
            :description="`${people.length} ${people.length === 1 ? 'person' : 'people'} · as of ${formatCalendarDate(asOf)} · retirement under RA 8291; loyalty awards at 10 years in the CSC, then every 5`"
        >
            <DataTable
                :columns="columns"
                :rows="people"
                row-key="employee_id"
                :caption="`Age and service, ${viewLabel}, as of ${asOf}`"
                :export-name="`imis-age-and-service-${filters.view}-${asOf}`"
                :default-page-size="50"
                :numbered="false"
                empty-text="No one matches this view."
            >
                <template #cell-name="{ row }">
                    <PersonCell
                        :name="row.name"
                        :photo-url="row.photo_url"
                        :href="`/admin/hr/employees/${row.employee_id}`"
                        :subtitle="[row.position, row.office].filter(Boolean).join(' · ') || '—'"
                    />
                </template>
                <template #cell-age="{ row }">
                    <template v-if="row.birthday">
                        <span class="font-medium">{{ row.age }}</span>
                        <div class="text-xs text-text-muted">{{ formatCalendarDate(row.birthday) }}</div>
                        <div v-if="row.birthday_in_days <= 30" class="text-xs" :class="row.birthday_in_days === 0 ? 'font-semibold text-link' : 'text-text-muted'">
                            Turns {{ row.age + (row.birthday_in_days === 0 ? 0 : 1) }} {{ when(row.birthday_in_days) }}
                        </div>
                    </template>
                    <span v-else class="text-text-muted">No birthday</span>
                </template>
                <template #cell-government_months="{ row }">
                    <CellFacts
                        empty="No appointment date"
                        :facts="[
                            { label: 'Government', value: row.government_label },
                            { label: 'CSC', value: row.csc_label, note: row.csc_since !== row.government_since ? `since ${formatCalendarDate(row.csc_since)}` : null },
                        ]"
                    />
                </template>
                <template #cell-next_award_date="{ row }">
                    <template v-if="row.next_award">
                        <Badge pill :tone="row.award_this_year ? 'success' : 'neutral'">{{ ordinal(row.next_award) }} year</Badge>
                        <div class="text-xs text-text-muted">{{ formatCalendarDate(row.next_award_date) }}</div>
                    </template>
                    <span v-else class="text-text-muted">—</span>
                </template>
                <template #cell-compulsory_retirement="{ row }">
                    <CellFacts
                        :facts="[
                            { label: 'Optional (60)', value: row.optional_retirement && formatCalendarDate(row.optional_retirement) },
                            { label: 'Compulsory (65)', value: row.compulsory_retirement && formatCalendarDate(row.compulsory_retirement) },
                        ]"
                    />
                    <div v-if="row.retirement_status" class="mt-1">
                        <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="retirementStatus[row.retirement_status].classes">{{ retirementStatus[row.retirement_status].label }}</span>
                    </div>
                </template>
            </DataTable>
            <p v-if="missingBirthdays" class="mt-3 text-xs text-text-muted">{{ missingBirthdays }} without a recorded birthday have no age or retirement dates.</p>
        </Card>
    </div>
</template>
