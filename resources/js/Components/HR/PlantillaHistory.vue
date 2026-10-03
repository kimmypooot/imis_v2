<script setup>
import { Link } from '@inertiajs/vue3';
import AdminCard from '../UI/AdminCard.vue';
import StatusBadge from '../UI/StatusBadge.vue';
import { formatCalendarDate } from '../../Utils/datetime';
import { formatPeso } from '../../Utils/money';

/**
 * HR-4: the plantilla items someone has held, newest first — item,
 * position, grade and step, status, nature, period and monthly salary.
 * Shared by the HR record page and "My Employee Record". `linkItems`
 * links item numbers to the plantilla (for plantilla viewers).
 */
defineProps({
    periods: { type: Array, required: true },
    linkItems: { type: Boolean, default: false },
});

const period = (row) => `${row.date_from ? formatCalendarDate(row.date_from) : 'Start not set'} – ${row.date_to ? formatCalendarDate(row.date_to) : 'present'}`;
</script>

<template>
    <AdminCard title="Plantilla" icon="grid" subtitle="Positions held, with the salary in the schedule then in force.">
        <p v-if="periods.length === 0" class="text-sm text-text-muted">Not appointed to a plantilla item yet.</p>
        <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-text-muted">
                    <tr>
                        <th scope="col" class="py-2 pr-4 font-medium">Item</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Position</th>
                        <th scope="col" class="py-2 pr-4 font-medium">SG / Step</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Status</th>
                        <th scope="col" class="py-2 pr-4 font-medium">Period</th>
                        <th scope="col" class="py-2 text-right font-medium">Monthly salary</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in periods" :key="row.id">
                        <td class="py-2 pr-4 align-top">
                            <Link v-if="linkItems" :href="`/admin/hr/plantilla/${row.item_id}`" class="font-medium text-primary hover:underline">{{ row.item_number || 'No item no.' }}</Link>
                            <span v-else class="font-medium text-text">{{ row.item_number || 'No item no.' }}</span>
                        </td>
                        <td class="py-2 pr-4 align-top text-text">
                            {{ row.position_title }}
                            <div class="text-xs text-text-muted">{{ row.nature_label }}</div>
                        </td>
                        <td class="py-2 pr-4 align-top tabular-nums text-text">{{ row.salary_grade ?? '—' }} / {{ row.step ?? '—' }}</td>
                        <td class="py-2 pr-4 align-top">
                            <StatusBadge :status="row.current ? 'active' : 'logged_out'" :label="row.current ? `${row.status_label ?? 'Incomplete'} · current` : row.status_label ?? 'Incomplete'" />
                        </td>
                        <td class="py-2 pr-4 align-top text-text">
                            <span class="whitespace-nowrap">{{ period(row) }}</span>
                            <div v-if="row.end_reason" class="text-xs text-text-muted">{{ row.end_reason }}</div>
                        </td>
                        <td class="py-2 text-right align-top tabular-nums text-text">{{ formatPeso(row.monthly_salary) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AdminCard>
</template>
