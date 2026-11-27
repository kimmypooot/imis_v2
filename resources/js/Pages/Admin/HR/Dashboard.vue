<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import HrMonthBanner from '../../../Components/Admin/HrMonthBanner.vue';
import HrAnalytics from '../../../Components/Admin/HrAnalytics.vue';

defineOptions({ layout: AdminLayout });

/**
 * The Human Resources dashboard, landing page of the HR area: the banner
 * with what is waiting on HR and this month's steps (HrMonthBanner), then
 * the analytics (HrAnalytics). The analytics need `employees.view`;
 * without it `hr` is null and the viewer is pointed at their HR pages.
 */
defineProps({
    hr: { type: Object, default: null },
    // The banner's figures (deferred): waiting on HR, this month's steps, task buttons.
    month: { type: Object, default: null },
    readiness: { type: Object, default: null },
    generatedAt: { type: String, required: true },
});

const refreshing = ref(false);
const refresh = () => {
    refreshing.value = true;
    router.reload({ only: ['hr', 'month', 'generatedAt'], onFinish: () => (refreshing.value = false) });
};
</script>

<template>
    <Head title="HR Dashboard" />

    <!-- The data timestamp and Refresh only apply when there are analytics. -->
    <HrMonthBanner :month="month" :waiting-href="hr ? '#waiting-on-hr' : null" :generated-at="hr ? generatedAt : null" :refreshing="refreshing" @refresh="refresh" />

    <Link
        v-if="readiness"
        href="/admin/hr/readiness"
        class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning-border bg-warning-bg px-4 py-3 text-sm text-text hover:border-warning focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
    >
        <span>
            <strong>Cutover readiness:</strong>
            {{ readiness.remaining }} of {{ readiness.total }} checks still to do before the legacy LMS and ldms can be retired.
        </span>
        <span class="font-medium text-link">Open checklist</span>
    </Link>

    <template v-if="hr">
        <HrAnalytics :hr="hr" />
    </template>

    <Card v-else>
        <EmptyState
            icon="clipboard"
            title="Your HR pages are in the sidebar"
            description="The HR analytics name people and show their birthdays, so they're only for those who can view all employee records."
        />
    </Card>
</template>
