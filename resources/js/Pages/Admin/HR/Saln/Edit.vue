<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import SalnEditor from '../../../../Components/HR/Saln/SalnEditor.vue';
import SalnHistory from '../../../../Components/HR/Saln/SalnHistory.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HRD encoding a SALN on an employee's behalf: someone who can't sign in
 * (a separated employee's account is switched off) or who asked for help.
 * Every save and printout records who did it.
 */
defineProps({
    saln: { type: Object, required: true },
    options: { type: Object, required: true },
});
</script>

<template>
    <Head :title="`SALN ${saln.reference}`" />

    <PageHeader
        :title="`Encode the SALN of ${saln.employee.name}`"
        :description="`${saln.type_label} as of ${formatCalendarDate(saln.reckoning_date)} · ${saln.reference}`"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'SALN Compliance', href: '/admin/hr/saln' }, { label: saln.reference, href: `/admin/hr/saln/${saln.id}` }, { label: 'Encode' }]"
    />

    <div class="mb-6 flex items-start gap-2 rounded-md border border-border bg-surface px-4 py-3 text-sm text-text">
        <Icon name="info" class="mt-0.5 h-4 w-4 shrink-0 text-link" />
        <p>You are encoding on the employee's behalf. They still sign and swear to the printout themselves.</p>
    </div>

    <SalnHistory class="mb-6" :saln="saln" :print-base="`/admin/hr/saln/${saln.id}/versions`" />
    <SalnEditor :saln="saln" :options="options" :update-url="`/admin/hr/saln/${saln.id}`" :generate-url="`/admin/hr/saln/${saln.id}/generate`" />
</template>
