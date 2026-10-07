<script setup>
import { formatCalendarDate } from '../../Utils/datetime';
import { formatPeso } from '../../Utils/money';

/**
 * HR-5: Service Record lines on screen, in the standard CSC columns.
 * Shared by HR's page and "My Service Record"; the printable form is
 * HR/ServiceRecordPrint. HR's page adds an `actions` slot for the lines
 * it entered by hand (other agencies).
 */
defineProps({
    lines: { type: Array, required: true },
});

const date = (value) => (value ? formatCalendarDate(value) : null);
</script>

<template>
    <p v-if="lines.length === 0" class="text-sm text-text-muted">
        No service lines yet. They come from the plantilla: once HR appoints this person to an item, their service shows here.
    </p>
    <div v-else class="overflow-x-auto">
        <table class="w-full border-collapse text-sm">
            <caption class="sr-only">Service Record</caption>
            <thead class="text-xs text-text-muted">
                <tr class="border-b border-border">
                    <th scope="colgroup" colspan="2" class="px-2 py-1.5 text-center font-semibold">Service (inclusive dates)</th>
                    <th scope="colgroup" colspan="3" class="px-2 py-1.5 text-center font-semibold">Record of appointment</th>
                    <th scope="colgroup" colspan="2" class="px-2 py-1.5 text-center font-semibold">Office entity / division</th>
                    <th scope="col" rowspan="2" class="px-2 py-1.5 text-center font-semibold">Leave w/o pay</th>
                    <th scope="colgroup" colspan="2" class="px-2 py-1.5 text-center font-semibold">Separation</th>
                    <th v-if="$slots.actions" scope="col" rowspan="2" class="px-2 py-1.5"><span class="sr-only">Actions</span></th>
                </tr>
                <tr class="border-b border-border text-left">
                    <th scope="col" class="px-2 py-1.5 font-medium">From</th>
                    <th scope="col" class="px-2 py-1.5 font-medium">To</th>
                    <th scope="col" class="px-2 py-1.5 font-medium">Designation</th>
                    <th scope="col" class="px-2 py-1.5 font-medium">Status</th>
                    <th scope="col" class="px-2 py-1.5 text-right font-medium">Salary (annual)</th>
                    <th scope="col" class="px-2 py-1.5 font-medium">Station / place of assignment</th>
                    <th scope="col" class="px-2 py-1.5 font-medium">Branch</th>
                    <th scope="col" class="px-2 py-1.5 font-medium">Date</th>
                    <th scope="col" class="px-2 py-1.5 font-medium">Cause</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr v-for="(line, index) in lines" :key="index" class="align-top">
                    <td class="whitespace-nowrap px-2 py-2 text-text">{{ date(line.date_from) }}</td>
                    <td class="whitespace-nowrap px-2 py-2 text-text">{{ date(line.date_to) ?? 'Present' }}</td>
                    <td class="px-2 py-2 text-text">
                        {{ line.designation }}
                        <div v-if="line.salary_grade || line.step || line.item_number" class="text-xs text-text-muted">
                            {{ [line.salary_grade && `SG ${line.salary_grade}`, line.step && `step ${line.step}`].filter(Boolean).join(', ') }}<template v-if="line.item_number"> · {{ line.item_number }}</template>
                        </div>
                        <div v-if="line.source === 'entry'" class="text-xs text-text-muted">Other agency (entered by HR)</div>
                    </td>
                    <td class="px-2 py-2 text-text">{{ line.status }}</td>
                    <td class="whitespace-nowrap px-2 py-2 text-right tabular-nums text-text">{{ formatPeso(line.annual_salary) }}</td>
                    <td class="px-2 py-2 text-text">{{ line.station || '—' }}</td>
                    <td class="px-2 py-2 text-text">{{ line.branch }}</td>
                    <td class="px-2 py-2 text-text-muted">{{ line.leave_without_pay ?? '—' }}</td>
                    <td class="whitespace-nowrap px-2 py-2 text-text">{{ date(line.separation_date) ?? '' }}</td>
                    <td class="px-2 py-2 text-text">{{ line.separation_cause ?? '' }}</td>
                    <td v-if="$slots.actions" class="whitespace-nowrap px-2 py-2 text-right"><slot name="actions" :line="line" /></td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
