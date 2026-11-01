<script setup>
import { computed } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import AccountLayout from '../../Layouts/AccountLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import Icon from '../../Components/UI/Icon.vue';
import Table from '../../Components/UI/Table.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Pagination from '../../Components/UI/Pagination.vue';
import Select from '../../Components/Forms/Select.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: [PortalLayout, AccountLayout] });

/**
 * Admin-2: who else opened or printed your HR records, and when — the
 * personal-data access log kept under RA 10173. Your own visits and
 * reports covering everyone aren't listed.
 */
const props = defineProps({
    entries: { type: Object, default: null /** Laravel paginator; null without an employee record */ },
    years: { type: Array, required: true },
    year: { type: Number, default: null },
    keptMonths: { type: Number, required: true },
});

const yearOptions = [{ value: '', label: 'All years' }, ...props.years.map((year) => ({ value: String(year), label: String(year) }))];
const filterYear = (value) => router.get('/my/record-access', value ? { year: value } : {}, { preserveState: true, preserveScroll: true });

// Entries arrive newest first, so page 1's first row is the latest access.
const total = computed(() => props.entries?.total ?? props.entries?.data.length ?? 0);
const latest = computed(() => (props.entries?.current_page === 1 ? props.entries.data[0] : null));
const peopleOnPage = computed(() => new Set((props.entries?.data ?? []).map((entry) => entry.who)).size);

const stats = computed(() => [
    { label: props.year ? `Accesses in ${props.year}` : 'Total accesses', value: total.value, icon: 'eye', tile: 'bg-info-bg text-primary' },
    { label: 'People on this page', value: peopleOnPage.value, icon: 'users', tile: 'bg-info-bg text-primary' },
    {
        label: 'Retention',
        value: props.keptMonths ? `${props.keptMonths} mo` : 'Not set',
        icon: 'calendar',
        tile: props.keptMonths ? 'bg-success-bg text-success' : 'bg-warning-bg text-warning',
    },
]);

const initial = (name) => (name || '?').trim().charAt(0).toUpperCase();
</script>

<template>
    <Head title="Who Accessed My Record" />

    <PageHeader
        title="Who Accessed My Record"
        description="Every time someone other than you opened or printed your HR records — your employee record, Personal Data Sheet, 201 file, Service Record, leave, training or payslips — it is logged here, under the Data Privacy Act (RA 10173)."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Who Accessed My Record' }]"
    />

    <Card v-if="!entries">
        <EmptyState icon="shield" title="You have no HR record yet" description="Once HR creates your employee record, anyone who opens it will be listed here." />
    </Card>

    <div v-else class="space-y-6">
        <!-- Data-privacy banner: your right to know, in the CSC navy. -->
        <div class="relative overflow-hidden rounded-lg bg-linear-to-br from-csc-blue via-csc-blue to-csc-blue-dark p-6 text-white">
            <span class="pointer-events-none absolute -top-10 -right-10 h-40 w-40 rounded-full border border-white/10" aria-hidden="true" />
            <span class="pointer-events-none absolute -right-2 -bottom-16 h-40 w-40 rounded-full border border-white/10" aria-hidden="true" />
            <div class="relative flex items-center gap-5">
                <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-full bg-white/15 ring-1 ring-white/25" aria-hidden="true">
                    <Icon name="shield" size="lg" />
                </span>
                <div class="min-w-0">
                    <p class="text-sm font-semibold">Your data, your right to know</p>
                    <p v-if="latest" class="mt-1 text-sm leading-relaxed text-white/80">
                        Most recent access: <span class="font-medium text-white">{{ latest.who }}</span>
                        — {{ latest.what }}, <time :datetime="latest.at">{{ formatDateTime(latest.at) }}</time>.
                    </p>
                    <p v-else class="mt-1 text-sm leading-relaxed text-white/80">
                        {{ total ? 'Showing who opened or printed your records, newest first.' : 'No one else has opened your records so far.' }}
                    </p>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div v-for="stat in stats" :key="stat.label" class="flex items-center gap-3.5 rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary/40">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md" :class="stat.tile" aria-hidden="true">
                    <Icon :name="stat.icon" />
                </span>
                <div class="min-w-0">
                    <p class="text-xl leading-none font-semibold text-text tabular-nums">{{ stat.value }}</p>
                    <p class="mt-1 truncate text-xs text-text-muted">{{ stat.label }}</p>
                </div>
            </div>
        </div>

        <Card title="Access log" icon="clipboard" description="Newest first.">
            <template #actions>
                <div v-if="years.length" class="w-40">
                    <label for="access-year" class="sr-only">Year</label>
                    <Select id="access-year" :model-value="year ? String(year) : ''" :options="yearOptions" @update:model-value="filterYear" />
                </div>
            </template>

            <Table :empty="!entries.data.length">
                <thead>
                    <tr>
                        <th>When</th>
                        <th>Who</th>
                        <th>What</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries.data" :key="entry.id" class="transition-colors hover:bg-surface-muted/60">
                        <td class="whitespace-nowrap">
                            <time :datetime="entry.at">{{ formatDateTime(entry.at) }}</time>
                        </td>
                        <td>
                            <div class="flex items-center gap-3">
                                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-info-bg text-xs font-semibold text-primary" aria-hidden="true">
                                    {{ initial(entry.who) }}
                                </span>
                                <span class="min-w-0">
                                    <span class="block font-medium">{{ entry.who }}</span>
                                    <span v-if="entry.position" class="block text-xs text-text-muted">{{ entry.position }}</span>
                                </span>
                            </div>
                        </td>
                        <td><Badge tone="info">{{ entry.what }}</Badge></td>
                        <td class="text-text-muted">{{ entry.detail }}</td>
                    </tr>
                </tbody>
                <template #empty>
                    <EmptyState
                        icon="shield"
                        :title="year ? `No one else opened your records in ${year}` : 'No one else has opened your records'"
                        description="When HR staff, your supervisor or management open or print your HR records, it will show here."
                    />
                </template>
            </Table>

            <Pagination
                v-if="entries.last_page > 1"
                class="mt-6"
                :prev-url="entries.prev_page_url"
                :next-url="entries.next_page_url"
                :current-page="entries.current_page"
                :last-page="entries.last_page"
            />

            <template #footer>
                <p class="flex items-start gap-2 text-xs text-text-muted">
                    <Icon name="info" size="sm" class="shrink-0" />
                    <span>
                        {{ keptMonths ? `Entries are kept for ${keptMonths} months.` : 'Entries are kept until the office sets a retention period.' }}
                        Something here you didn't expect? Ask the Human Resource Division or the Data Protection Officer — see the
                        <Link href="/privacy" class="text-primary hover:underline">privacy notice</Link>.
                    </span>
                </p>
            </template>
        </Card>
    </div>
</template>
