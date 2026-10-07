<script setup>
import { computed } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Button from '../../Components/UI/Button.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * System Health: the same checks as `php artisan imis:health`. "Fail"
 * means something is broken; "Check" means it works but needs attention.
 */
const props = defineProps({
    checks: { type: Array, required: true },
    overall: { type: String, required: true },
    checkedAt: { type: String, required: true },
});

const groups = computed(() => props.checks.reduce((all, check) => {
    (all[check.group] ??= []).push(check);

    return all;
}, {}));

const badge = { ok: { status: 'success', label: 'OK' }, warn: { status: 'warning', label: 'Check' }, fail: { status: 'critical', label: 'Fail' } };
const summary = {
    ok: 'Everything is in place and working.',
    warn: 'Working, with items to look at.',
    fail: 'Something needs fixing.',
};
</script>

<template>
    <Head title="System Health" />

    <PageHeader title="System Health" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'System' }, { label: 'System Health' }]">
        <template #actions>
            <Button variant="secondary" @click="router.reload({ only: ['checks', 'overall', 'checkedAt'] })">Check again</Button>
        </template>
    </PageHeader>

    <div class="mb-4 flex flex-wrap items-center gap-3">
        <StatusBadge :status="badge[overall].status" :label="badge[overall].label" />
        <p class="text-sm text-text">{{ summary[overall] }}</p>
        <p class="text-sm text-text-muted">Checked {{ formatDateTime(checkedAt) }}</p>
    </div>

    <div class="space-y-6">
        <Card v-for="(items, group) in groups" :key="group" :title="group" icon="activity">
            <ul class="divide-y divide-border">
                <li v-for="check in items" :key="check.label" class="flex flex-wrap items-center justify-between gap-3 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-text">{{ check.label }}</p>
                        <p class="text-sm text-text-muted">{{ check.detail }}</p>
                    </div>
                    <StatusBadge :status="badge[check.status].status" :label="badge[check.status].label" />
                </li>
            </ul>
        </Card>
    </div>
</template>
