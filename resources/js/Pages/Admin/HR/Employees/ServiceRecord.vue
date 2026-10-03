<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../../Components/UI/AdminCard.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import ServiceRecordTable from '../../../../Components/HR/ServiceRecordTable.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-5: someone's Service Record for HR, built from their plantilla
 * periods and reassignment history. "Print certified copy" opens the
 * printable form with the certifying officer from System Settings.
 */
defineProps({
    employee: { type: Object, required: true },
    person: { type: Object, required: true },
    lines: { type: Array, required: true },
    printUrl: { type: String, required: true },
});
</script>

<template>
    <Head :title="`Service Record — ${employee.name}`" />

    <PageHeader
        :title="`Service Record — ${employee.name}`"
        :breadcrumbs="[
            { label: 'Home', href: '/admin' },
            { label: 'Employees', href: '/admin/hr/employees' },
            { label: employee.name, href: `/admin/hr/employees/${employee.id}` },
            { label: 'Service Record' },
        ]"
    >
        <template #actions>
            <a
                :href="printUrl"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center gap-2 rounded-md bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary-hover focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2"
            >
                <Icon name="printer" size="sm" />Print certified copy
            </a>
        </template>
    </PageHeader>

    <AdminCard title="Service" icon="clipboard" :subtitle="`Born ${person.date_of_birth ? formatCalendarDate(person.date_of_birth) : '—'}${person.place_of_birth ? ` in ${person.place_of_birth}` : ''}`">
        <ServiceRecordTable :lines="lines" />
        <p class="mt-4 text-xs text-text-muted">
            Lines come from the plantilla (appointments, step increments) and break where a reassignment or a new salary schedule takes
            effect. Leave without pay will come with the Leave module.
        </p>
    </AdminCard>
</template>
