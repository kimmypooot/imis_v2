<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import ApprovalTrail from '@cscro8/ui/Components/UI/ApprovalTrail.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import SalnEditor from '../../Components/HR/Saln/SalnEditor.vue';
import SalnHistory from '../../Components/HR/Saln/SalnHistory.vue';
import SalnFileUpload from '../../Components/HR/Saln/SalnFileUpload.vue';
import { formatCalendarDate } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * One of your SALNs: encode it, prepare the printout, and — once the SALN
 * Committee clears it and you've sworn to it — upload the signed copy.
 */
const props = defineProps({
    saln: { type: Object, required: true },
    options: { type: Object, required: true },
    canFile: { type: Boolean, required: true },
});

const tones = { draft: 'draft', generated: 'info', received: 'info', returned: 'warning', cleared: 'active', filed: 'active' };
const NEXT = {
    draft: 'Fill in every part, then prepare the printout.',
    generated: 'Print the latest printout, sign every page, and hand it to HRD. If you change anything, print again and hand in the new one.',
    received: 'HRD has your hard copy. The SALN Committee is reviewing it.',
    returned: 'The SALN Committee returned it. Correct it, print it again and hand the new copy to HRD.',
    cleared: 'Cleared. Swear to it before the administering officer, then upload the signed copy below (or HRD will).',
    filed: 'Filed in your 201 File. Nothing more to do.',
};
</script>

<template>
    <Head :title="`SALN ${saln.reference}`" />

    <PageHeader
        :title="`SALN — ${saln.type_label}`"
        :description="`As of ${formatCalendarDate(saln.reckoning_date)} · ${saln.reference}`"
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My SALN', href: '/my/saln' }, { label: saln.reference }]"
    >
        <template #actions>
            <StatusBadge :status="tones[saln.status]" :label="saln.status_label" />
            <Button
                v-if="saln.current_version && saln.status !== 'draft'"
                :href="`/my/saln/${saln.id}/versions/${saln.current_version}/print`"
                external
                icon="printer"
                variant="outline"
                size="sm"
            >
                Print v{{ saln.current_version }}
            </Button>
        </template>
    </PageHeader>

    <!-- Where it is, and what to do next. -->
    <Card class="mb-6" title="Where it is" icon="activity" :description="NEXT[saln.status]">
        <p v-if="saln.returned_remarks" class="mb-5 rounded-md border border-warning-border bg-warning-bg px-3 py-2 text-sm text-text">
            <strong>Committee's remarks:</strong> {{ saln.returned_remarks }}
        </p>
        <ApprovalTrail :trail="saln.trail" label="SALN progress" />
    </Card>

    <!-- The printouts and upload sit above; the form gets the full width. -->
    <div class="mb-6 grid gap-6" :class="{ 'lg:grid-cols-2': canFile }">
        <SalnFileUpload v-if="canFile" :url="`/my/saln/${saln.id}/file`" :max-kilobytes="options.maxKilobytes" :reference="saln.reference" />
        <SalnHistory :saln="saln" :print-base="`/my/saln/${saln.id}/versions`" />
    </div>
    <SalnEditor :saln="saln" :options="options" :update-url="`/my/saln/${saln.id}`" :generate-url="`/my/saln/${saln.id}/generate`" />
</template>
