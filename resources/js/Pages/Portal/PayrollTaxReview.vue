<script setup>
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import QueueSummary from '../../Components/Portal/QueueSummary.vue';
import { daysSince, formatDateTime } from '../../Utils/datetime';
import { formatPeso } from '../../Utils/money';

defineOptions({ layout: PortalLayout });

/**
 * HR-8: Accounting's queue. HRD submits each month's payroll here; the
 * Accountant III reviews it, enters each person's withholding tax and
 * returns it to HRD to finalize.
 */
defineProps({
    waiting: { type: Array, required: true },
    returned: { type: Array, required: true },
});

const ageClass = (iso) => (daysSince(iso) >= 5 ? 'bg-danger-bg text-danger-fg' : daysSince(iso) >= 2 ? 'bg-warning-bg text-warning-fg' : 'bg-surface-muted text-text-muted');
const ageLabel = (iso) => (daysSince(iso) === 0 ? 'today' : `${daysSince(iso)} d`);
</script>

<template>
    <Head title="Payroll Tax Review" />

    <PageHeader
        title="Payroll Tax Review"
        description="Payrolls HRD submitted to Accounting. Review each one, enter the withholding tax, then return it to HRD to finalize."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Payroll Tax Review' }]"
    />

    <QueueSummary :since="waiting.map((run) => run.submitted_at)" noun="payroll" :late-after="2" class="mb-6" />

    <Card :title="`Waiting for you (${waiting.length})`" icon="checkCircle" class="mb-6">
        <EmptyState v-if="!waiting.length" icon="check" title="Nothing waiting" description="When HRD submits a payroll for withholding tax, it appears here and under the bell." />
        <ul v-else class="divide-y divide-border">
            <li v-for="run in waiting" :key="run.id" class="flex flex-wrap items-center gap-x-4 gap-y-3 py-4 first:pt-0 last:pb-0">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-info-bg text-link"><Icon name="database" /></span>
                <div class="min-w-0 flex-1 basis-64">
                    <p class="font-medium text-text">Payroll for {{ run.period }}</p>
                    <p class="text-sm text-text-muted tabular-nums">
                        {{ run.totals.people }} {{ run.totals.people === 1 ? 'person' : 'people' }} · Gross {{ formatPeso(run.totals.gross) }}
                    </p>
                    <p class="mt-1 flex flex-wrap items-center gap-2 text-xs text-text-muted">
                        <span class="whitespace-nowrap">Submitted {{ formatDateTime(run.submitted_at) }}<template v-if="run.submitted_by"> by {{ run.submitted_by }}</template></span>
                        <span class="rounded-full px-2 py-0.5 font-medium" :class="ageClass(run.submitted_at)">{{ ageLabel(run.submitted_at) }}</span>
                    </p>
                </div>
                <Button size="sm" variant="success" :href="`/admin/hr/payroll/runs/${run.id}`"><Icon name="arrowRight" size="sm" />Review &amp; enter tax<span class="sr-only"> for {{ run.period }}</span></Button>
            </li>
        </ul>
    </Card>

    <Card v-if="returned.length" title="Recently returned to HRD" icon="clipboard">
        <ul class="divide-y divide-border text-sm">
            <li v-for="run in returned" :key="run.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                <Link :href="`/admin/hr/payroll/runs/${run.id}`" class="font-medium text-text hover:text-link">{{ run.period }}</Link>
                <span class="flex items-center gap-3 text-text-muted">
                    <span>Returned {{ formatDateTime(run.returned_at) }}<template v-if="run.returned_by"> by {{ run.returned_by }}</template></span>
                    <StatusBadge :status="run.status === 'finalized' ? 'active' : 'info'" :label="run.status_label" />
                </span>
            </li>
        </ul>
    </Card>
</template>
