<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Table from '../../Components/UI/Table.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Pagination from '../../Components/UI/Pagination.vue';
import Select from '../../Components/Forms/Select.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

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
</script>

<template>
    <Head title="Who Accessed My Record" />

    <PageHeader
        title="Who Accessed My Record"
        description="Every time someone other than you opened or printed your HR records — your employee record, Personal Data Sheet, 201 file, Service Record, leave, training or payslips — it is logged here, under the Data Privacy Act (RA 10173)."
        :breadcrumbs="[{ label: 'Who Accessed My Record' }]"
    />

    <Card v-if="!entries">
        <EmptyState icon="shield" title="You have no HR record yet" description="Once HR creates your employee record, anyone who opens it will be listed here." />
    </Card>

    <Card v-else>
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
                <tr v-for="entry in entries.data" :key="entry.id">
                    <td class="whitespace-nowrap">
                        <time :datetime="entry.at">{{ formatDateTime(entry.at) }}</time>
                    </td>
                    <td>
                        <span class="font-medium">{{ entry.who }}</span>
                        <span v-if="entry.position" class="block text-xs text-text-muted">{{ entry.position }}</span>
                    </td>
                    <td>{{ entry.what }}</td>
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
            class="mt-4"
            :prev-url="entries.prev_page_url"
            :next-url="entries.next_page_url"
            :current-page="entries.current_page"
            :last-page="entries.last_page"
        />

        <template #footer>
            <p class="text-xs text-text-muted">
                {{ keptMonths ? `Entries are kept for ${keptMonths} months.` : 'Entries are kept until the office sets a retention period.' }}
                Something here you didn't expect? Ask the Human Resource Division or the Data Protection Officer — see the
                <Link href="/privacy" class="text-primary hover:underline">privacy notice</Link>.
            </p>
        </template>
    </Card>
</template>
