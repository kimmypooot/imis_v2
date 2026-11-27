<script setup>
import { Head } from '@inertiajs/vue3';

/**
 * Calendar of Activities: the printable monthly sheet in the reference
 * layout (title, seven columns, a date row per day, holidays as HOLIDAY
 * rows, "Dates to be announced" last). Landscape, Poppins; the header row
 * repeats on every printed page. No app layout: print or "Save as PDF"
 * from the browser. Colors are tokens, so it reads in the dark theme on
 * screen while printing stays light. Cancelled activities are struck
 * through, with the reason in Remarks.
 */
defineProps({
    sheet: { type: Object, required: true },
    month: { type: String, required: true },
    office: { type: String, default: null },
});

const columns = [
    { label: 'Date/Time', width: '13%' },
    { label: 'Mode', width: '9%' },
    { label: 'Venue', width: '17%' },
    { label: 'Participants', width: '14%' },
    { label: 'Activity', width: '25%' },
    { label: 'Responsible Office/Personnel/ Committee', width: '12%' },
    { label: 'Remarks', width: '10%' },
];

const cells = ['time', 'mode', 'venue', 'participants', 'activity', 'responsible', 'remarks'];

const print = () => window.print();
</script>

<template>
    <div class="coa-page">
        <Head :title="`Calendar of Activities — ${month}`" />

        <div class="coa-toolbar">
            <p>
                <strong>Calendar of Activities</strong><template v-if="office"> — {{ office }}</template>.
                Use your browser's print dialog; choose “Save as PDF” to keep a copy. Set the paper to landscape.
            </p>
            <button type="button" @click="print">Print</button>
        </div>

        <main class="coa">
            <section class="sheet">
                <h1 class="title">{{ sheet.title }}</h1>
                <table>
                    <colgroup>
                        <col v-for="column in columns" :key="column.label" :style="{ width: column.width }" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th v-for="column in columns" :key="column.label">{{ column.label }}</th>
                        </tr>
                    </thead>
                    <tbody v-for="(day, index) in sheet.days" :key="index">
                        <tr class="date">
                            <td colspan="7">{{ day.label }}</td>
                        </tr>
                        <tr v-for="(row, rowIndex) in day.rows" :key="rowIndex" :class="{ holiday: row.time === 'HOLIDAY', cancelled: row.cancelled }">
                            <td v-for="cell in cells" :key="cell" :class="cell">{{ row[cell] }}</td>
                        </tr>
                    </tbody>
                    <tbody v-if="sheet.tba.length">
                        <tr class="date">
                            <td colspan="7">Dates to be announced</td>
                        </tr>
                        <tr v-for="(row, rowIndex) in sheet.tba" :key="rowIndex" :class="{ cancelled: row.cancelled }">
                            <td v-for="cell in cells" :key="cell" :class="cell">{{ row[cell] }}</td>
                        </tr>
                    </tbody>
                    <tbody v-if="!sheet.days.length && !sheet.tba.length">
                        <tr>
                            <td colspan="7" class="empty">No activities this month.</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </main>
    </div>
</template>

<style scoped>
.coa-page { min-height: 100vh; background: var(--color-surface-muted); color: var(--color-text); }
.coa-toolbar {
    position: sticky; top: 0; z-index: 10;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: 0.6rem 1rem; background: var(--color-surface); border-bottom: 1px solid var(--color-border);
    font: 13px/1.4 var(--font-sans); color: var(--color-text);
}
.coa-toolbar p { margin: 0; }
.coa-toolbar button {
    padding: 0.4rem 1rem; border-radius: 0.375rem; background: var(--color-primary); color: #fff; font-weight: 600;
}
.coa { padding: 0.3in 1rem; }
.sheet {
    max-width: 11.69in; margin: 0 auto; padding: 0.4in; box-sizing: border-box;
    background: var(--color-surface); color: var(--color-text);
    font: 9pt/1.35 var(--font-sans); box-shadow: 0 1px 4px rgb(0 0 0 / 0.15);
}
table { width: 100%; border-collapse: collapse; table-layout: fixed; }
thead { display: table-header-group; }
th, td {
    border: 1px solid var(--color-border-strong); padding: 3px 5px; vertical-align: top;
    overflow-wrap: anywhere; white-space: normal;
}
th { font-weight: 600; text-align: center; vertical-align: middle; }
.title { margin: 0 0 0.12in; text-align: center; font-size: 12pt; font-weight: 700; }
tr { break-inside: avoid; }
.date td { background: var(--color-info-bg); font-weight: 600; break-after: avoid; }
.holiday td { font-weight: 600; }
.holiday .time { color: var(--color-danger-fg); }
.cancelled td:not(.remarks) { text-decoration: line-through; }
.empty { text-align: center; font-style: italic; color: var(--color-text-muted); padding: 0.3in; }

@media print {
    @page { size: A4 landscape; margin: 0.4in; }
    .coa-page { background: none; min-height: 0; }
    .coa-toolbar { display: none; }
    .coa { padding: 0; }
    .sheet { max-width: none; padding: 0; box-shadow: none; }
    .date td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
}
</style>
