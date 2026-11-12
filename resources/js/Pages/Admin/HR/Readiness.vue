<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import StatusBadge from '../../../Components/UI/StatusBadge.vue';

defineOptions({ layout: AdminLayout });

/**
 * HR-7: the cutover checklist before the legacy LMS and ldms are
 * switched off. "To do" items block the cutover; "Check" items are worth
 * fixing but don't.
 */
const props = defineProps({
    checks: { type: Array, required: true },
});

const groups = computed(() => props.checks.reduce((all, check) => {
    (all[check.group] ??= []).push(check);

    return all;
}, {}));

const badge = { ok: { status: 'success', label: 'Ready' }, warn: { status: 'warning', label: 'Check' }, todo: { status: 'critical', label: 'To Do' } };
const remaining = computed(() => props.checks.filter((check) => check.status === 'todo').length);
</script>

<template>
    <Head title="Cutover Readiness" />

    <PageHeader
        title="Cutover Readiness"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Cutover Readiness' }]"
    />

    <p class="mb-4 text-sm text-text-muted">
        <template v-if="remaining">{{ remaining }} item(s) to do before the legacy LMS and ldms can be retired.</template>
        <template v-else>Nothing blocking. Import from the legacy LMS, compare a few leave cards, then run <code>imis:retire-legacy-hr --apply</code>.</template>
    </p>

    <div class="space-y-6">
        <Card v-for="(items, group) in groups" :key="group" :title="group" icon="checkCircle">
            <ul class="divide-y divide-border">
                <li v-for="check in items" :key="check.label" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-text">{{ check.label }}</p>
                        <p class="text-sm text-text-muted">{{ check.detail }}</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <Link v-if="check.href && check.status !== 'ok'" :href="check.href" class="text-sm font-medium text-link hover:underline">Fix</Link>
                        <StatusBadge :status="badge[check.status].status" :label="badge[check.status].label" />
                    </div>
                </li>
            </ul>
        </Card>
    </div>
</template>
