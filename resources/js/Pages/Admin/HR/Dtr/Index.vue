<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import Pagination from '@cscro8/ui/Components/UI/Pagination.vue';

defineOptions({ layout: AdminLayout });

/**
 * DTR management for HRD: everyone's month with what needs attention. The
 * counts above the table are filters; "Open DTR" goes to the employee's
 * sheet, where adjustments and raw punches are managed.
 */
const props = defineProps({
    month: { type: String, required: true },
    label: { type: String, required: true },
    filters: { type: Object, required: true },
    offices: { type: Array, required: true },
    filterLabels: { type: Object, required: true },
    counts: { type: Object, required: true },
    employees: { type: Object, required: true },
    canManage: { type: Boolean, default: false },
});

const search = ref(props.filters.search ?? '');

const go = (changes = {}) => {
    const query = { month: props.month, office: props.filters.office, search: search.value, filter: props.filters.filter, ...changes };
    router.get('/admin/hr/dtr', Object.fromEntries(Object.entries(query).filter(([, value]) => value !== null && value !== undefined && value !== '')), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
};

const hm = (minutes) => (minutes ? `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')}` : '–');
const dtrUrl = (employee) => `/admin/hr/employees/${employee.id}/dtr?month=${props.month}`;
</script>

<template>
    <Head title="DTR Management" />

    <PageHeader
        title="DTR Management"
        :description="`Everyone's Daily Time Record for ${label}, with what needs attention.`"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'DTR Management' }]"
    >
        <template #actions>
            <Button variant="secondary" href="/admin/hr/dtr/import" icon="download">Biometric logs</Button>
            <Button variant="secondary" href="/admin/hr/dtr/schedules" icon="clock">Work schedules</Button>
            <Button variant="secondary" href="/admin/hr/leave/tardiness" icon="clipboard">Tardiness &amp; undertime</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <Card>
            <div class="flex flex-wrap items-end gap-3">
                <label class="text-sm text-text">
                    <span class="mb-1 block text-xs text-text-muted">Month</span>
                    <input type="month" class="filter-control" :value="month" aria-label="Month" @change="(event) => event.target.value && go({ month: event.target.value, page: null })" />
                </label>
                <label class="text-sm text-text">
                    <span class="mb-1 block text-xs text-text-muted">Office</span>
                    <select class="filter-control" :value="filters.office ?? ''" aria-label="Office" @change="(event) => go({ office: event.target.value, page: null })">
                        <option value="">All offices</option>
                        <option v-for="office in offices" :key="office.value" :value="office.value">{{ office.label }}</option>
                    </select>
                </label>
                <form class="min-w-48 flex-1 text-sm text-text" @submit.prevent="go({ page: null })">
                    <label>
                        <span class="mb-1 block text-xs text-text-muted">Search</span>
                        <input v-model="search" type="search" class="filter-control w-full" placeholder="Employee name" aria-label="Search employees" />
                    </label>
                </form>
            </div>

            <div class="mt-4 flex flex-wrap gap-2" role="group" aria-label="Needs attention">
                <button
                    type="button"
                    class="rounded-full border px-3 py-1 text-sm transition"
                    :class="!filters.filter ? 'border-primary bg-info-bg font-semibold text-link' : 'border-border text-text hover:bg-surface-muted'"
                    @click="go({ filter: null, page: null })"
                >
                    Everyone
                </button>
                <button
                    v-for="(text, key) in filterLabels"
                    :key="key"
                    type="button"
                    class="rounded-full border px-3 py-1 text-sm transition"
                    :class="filters.filter === key ? 'border-primary bg-info-bg font-semibold text-link' : 'border-border text-text hover:bg-surface-muted'"
                    :aria-pressed="filters.filter === key"
                    @click="go({ filter: filters.filter === key ? null : key, page: null })"
                >
                    {{ text }}
                    <span class="ml-1 tabular-nums" :class="counts[key] ? 'font-semibold' : 'text-text-muted'">{{ counts[key] }}</span>
                </button>
            </div>
        </Card>

        <Card :title="`${employees.total} ${employees.total === 1 ? 'employee' : 'employees'}`" icon="clock">
            <div v-if="employees.data.length" class="table-card">
                <table class="data-table">
                    <caption class="sr-only">Employees and their DTR for {{ label }}</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="font-medium">Employee</th>
                            <th scope="col" class="font-medium">Biometric ID</th>
                            <th scope="col" class="font-medium">Schedule</th>
                            <th scope="col" class="text-right font-medium">Hours</th>
                            <th scope="col" class="text-right font-medium">Late</th>
                            <th scope="col" class="text-right font-medium">Undertime</th>
                            <th scope="col" class="text-right font-medium">Absent</th>
                            <th scope="col" class="text-right font-medium">Leave</th>
                            <th scope="col" class="font-medium">Notes</th>
                            <th scope="col" class="text-right font-medium"><span class="sr-only">Open</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="employee in employees.data" :key="employee.id">
                            <td>
                                <Link :href="dtrUrl(employee)" class="font-medium text-link hover:underline">{{ employee.name }}</Link>
                                <div class="text-xs text-text-muted">{{ employee.office ?? 'Regional Office' }}</div>
                            </td>
                            <td class="tabular-nums">
                                <span v-if="employee.biometric_id">{{ employee.biometric_id }}</span>
                                <span v-else class="inline-flex rounded-full bg-danger-bg px-2.5 py-0.5 text-xs font-semibold text-danger-fg">None</span>
                            </td>
                            <td>
                                <span v-if="employee.schedule">{{ employee.schedule }}</span>
                                <span v-else class="inline-flex rounded-full bg-warning-bg px-2.5 py-0.5 text-xs font-semibold text-warning-fg">None</span>
                            </td>
                            <td class="text-right tabular-nums">{{ hm(employee.hours_minutes) }}</td>
                            <td class="text-right tabular-nums">{{ hm(employee.late_minutes) }}</td>
                            <td class="text-right tabular-nums">{{ hm(employee.undertime_minutes) }}</td>
                            <td class="text-right tabular-nums">{{ employee.absences || '–' }}</td>
                            <td class="text-right tabular-nums">{{ employee.leave_days || '–' }}</td>
                            <td>
                                <div class="flex flex-wrap gap-1.5">
                                    <span v-if="employee.unverified_days" class="inline-flex rounded-full bg-warning-bg px-2.5 py-0.5 text-xs font-semibold text-warning-fg">{{ employee.unverified_days }} to verify</span>
                                    <span v-if="employee.adjusted_days" class="inline-flex rounded-full bg-info-bg px-2.5 py-0.5 text-xs font-semibold text-link">{{ employee.adjusted_days }} adjusted</span>
                                    <span v-if="employee.charged" class="inline-flex rounded-full bg-success-bg px-2.5 py-0.5 text-xs font-semibold text-success-fg">Charged to leave</span>
                                </div>
                            </td>
                            <td class="text-right">
                                <ActionButton icon="eye" tone="neutral" :label="`Open the DTR of ${employee.name}`" :href="dtrUrl(employee)" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <EmptyState v-else icon="clock" title="No employees match" description="Change the office, the month or the filter above." />

            <Pagination
                v-if="employees.last_page > 1"
                class="mt-6"
                :prev-url="employees.prev_page_url"
                :next-url="employees.next_page_url"
                :current-page="employees.current_page"
                :last-page="employees.last_page"
            />
        </Card>
    </div>
</template>
