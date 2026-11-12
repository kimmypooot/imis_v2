<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import BusyRegion from '../../Components/UI/BusyRegion.vue';
import { useBusy } from '../../Composables/useBusy';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import Card from '../../Components/UI/Card.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import LeaveCard from '../../Components/HR/LeaveCard.vue';

defineOptions({ layout: PortalLayout });

/**
 * HR-6a self-service: your leave credits and leave card, read-only, with
 * shortcuts to everything else about leave (filing, tracking, the
 * calendar, monetization, flexible work).
 */
defineProps({
    hasRecord: { type: Boolean, required: true },
    year: { type: Number, default: null },
    years: { type: Array, default: () => [] },
    balances: { type: Array, default: () => [] },
    card: { type: Object, default: null },
    monthly: { type: Object, default: null },
});

const { busy, handlers } = useBusy();
const changeYear = (value) => router.get('/my/leave', { year: value }, { preserveScroll: true, ...handlers });

const shortcuts = [
    { label: 'My applications', hint: 'Track and print what you filed', href: '/my/leave/applications', icon: 'clipboard' },
    { label: 'Leave calendar', hint: 'Your leave, colleagues and holidays', href: '/my/leave/calendar', icon: 'calendar' },
    { label: 'Monetization', hint: 'Convert leave credits to cash', href: '/my/leave/monetization', icon: 'database' },
    { label: 'Flexible work', hint: 'Arrangements and weekly reports', href: '/my/flexible-work', icon: 'desktop' },
];
</script>

<template>
    <Head title="My Leave" />

    <PageHeader
        title="My Leave"
        description="Your leave credits as HR keeps them. If something looks wrong, contact the HR office."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Leave' }]"
    >
        <template v-if="hasRecord" #actions>
            <Button variant="success" href="/my/leave/apply"><Icon name="plus" size="sm" />File leave</Button>
        </template>
    </PageHeader>

    <div v-if="hasRecord" class="space-y-6">
        <nav aria-label="Leave shortcuts" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Link
                v-for="shortcut in shortcuts"
                :key="shortcut.href"
                :href="shortcut.href"
                class="group flex items-center gap-3.5 rounded-lg border border-border bg-surface p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            >
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-info-bg text-link transition-transform duration-200 group-hover:scale-105" aria-hidden="true">
                    <Icon :name="shortcut.icon" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-sm font-medium text-text">{{ shortcut.label }}</span>
                    <span class="block text-xs text-text-muted">{{ shortcut.hint }}</span>
                </span>
                <Icon name="arrowRight" size="sm" class="shrink-0 text-text-muted transition-transform group-hover:translate-x-0.5 group-hover:text-link" />
            </Link>
        </nav>

        <BusyRegion :busy="busy">
        <LeaveCard :year="year" :years="years" :balances="balances" :card="card" :monthly="monthly" @year="changeYear" />
    </BusyRegion>
    </div>
    <Card v-else>
        <EmptyState
            icon="clipboard"
            title="No employee record yet"
            description="HR hasn't set up your employee record. Contact the HR office if you think this is a mistake."
        />
    </Card>
</template>
