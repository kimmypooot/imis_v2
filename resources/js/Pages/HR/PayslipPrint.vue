<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

/**
 * HR-8: a printable payslip for one month, on a half sheet of short bond
 * paper (8.5 × 5.5 in, two to an 8.5 × 11 page when printed twice). Print
 * or "Save as PDF" from the browser. No app layout: just the payslip.
 *
 * `request` is the approved request that released it to the employee
 * (null when payroll staff open it directly).
 */
const props = defineProps({
    entry: { type: Object, required: true },
    run: { type: Object, required: true },
    request: { type: Object, default: null },
    organization: { type: String, default: null },
});

const money = (value) => Number(value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const longDate = (value) => (value ? new Date(`${value.slice(0, 10)}T00:00:00`).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' }) : '');

const earnings = computed(() => props.entry.lines.filter((line) => line.kind === 'earning'));
const deductions = computed(() => props.entry.lines.filter((line) => line.kind === 'deduction'));
const printed = new Date().toLocaleString('en-PH', { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' });
const print = () => window.print();
</script>

<template>
    <Head :title="`Payslip — ${run.period}`" />

    <div class="ps-toolbar">
        <p>
            <strong>Payslip</strong> — {{ entry.name }}, {{ run.period }}.
            Use your browser's print dialog; choose “Save as PDF” to keep a copy.
        </p>
        <button type="button" @click="print">Print</button>
    </div>

    <main class="ps">
        <section class="sheet" aria-label="Payslip">
            <header class="agency">
                <p>Republic of the Philippines</p>
                <p class="agency-name">{{ (organization || '').toUpperCase() }}</p>
                <h1>PAYSLIP</h1>
                <p class="period">For the month of {{ run.period }}</p>
                <p v-if="!run.finalized" class="draft">DRAFT — NOT FINAL</p>
            </header>

            <table class="person">
                <tbody>
                    <tr>
                        <th scope="row">Name</th>
                        <td class="strong">{{ entry.name }}</td>
                        <th scope="row">Item No.</th>
                        <td>{{ entry.item_number || '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Position</th>
                        <td>{{ entry.position }}</td>
                        <th scope="row">SG / Step</th>
                        <td>{{ entry.salary_grade ? `${entry.salary_grade} / ${entry.step}` : '—' }}</td>
                    </tr>
                    <tr>
                        <th scope="row">Office</th>
                        <td>{{ entry.office || '—' }}</td>
                        <th scope="row">Releases</th>
                        <td>{{ longDate(run.first_release_on) }} · {{ longDate(run.second_release_on) }}</td>
                    </tr>
                </tbody>
            </table>

            <div class="columns">
                <table class="amounts">
                    <caption>Earnings</caption>
                    <tbody>
                        <tr><td>Basic salary</td><td class="right">{{ money(entry.basic_salary) }}</td></tr>
                        <tr v-for="line in earnings" :key="line.id"><td>{{ line.label }}</td><td class="right">{{ money(line.amount) }}</td></tr>
                    </tbody>
                    <tfoot><tr><th scope="row">Gross pay</th><td class="right">{{ money(entry.gross_pay) }}</td></tr></tfoot>
                </table>

                <table class="amounts">
                    <caption>Deductions</caption>
                    <tbody>
                        <tr v-for="line in deductions" :key="line.id"><td>{{ line.label }}</td><td class="right">{{ money(line.amount) }}</td></tr>
                        <tr v-if="!deductions.length"><td colspan="2" class="muted">None</td></tr>
                    </tbody>
                    <tfoot><tr><th scope="row">Total deductions</th><td class="right">{{ money(entry.total_deductions) }}</td></tr></tfoot>
                </table>
            </div>

            <table class="net">
                <tbody>
                    <tr class="total"><th scope="row">NET PAY</th><td class="right">₱ {{ money(entry.net_pay) }}</td></tr>
                    <tr><th scope="row">15th of the month</th><td class="right">{{ money(entry.first_half) }}</td></tr>
                    <tr><th scope="row">30th of the month</th><td class="right">{{ money(entry.second_half) }}</td></tr>
                </tbody>
            </table>

            <footer class="foot">
                <p v-if="request">
                    Released on request {{ request.reference_number }}, approved by {{ request.approved_by }}<template v-if="request.acting_for"> for {{ request.acting_for }}</template>.
                </p>
                <p>System-generated from the IMIS payroll; no signature required. Printed {{ printed }}.</p>
            </footer>
        </section>
    </main>
</template>

<style scoped>
.ps-toolbar {
    position: sticky; top: 0; z-index: 10;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: 0.6rem 1rem; background: var(--color-surface); border-bottom: 1px solid var(--color-border);
    font: 13px/1.4 system-ui, sans-serif; color: var(--color-text);
}
.ps-toolbar button {
    padding: 0.4rem 1rem; border-radius: 0.375rem; background: var(--color-primary); color: #fff; font-weight: 600;
}
.ps { background: var(--color-surface-muted); padding: 0.3in 0; min-height: 100vh; }
.sheet {
    width: 8.5in; min-height: 5.5in; margin: 0 auto; padding: 0.35in 0.45in; box-sizing: border-box;
    background: #fff; color: #000; font: 9.5pt/1.3 Arial, Helvetica, sans-serif; border: 1px solid #ccc;
    /* A column, so the footer sits on the bottom edge of the half sheet. */
    display: flex; flex-direction: column;
}
.agency { text-align: center; margin-bottom: 0.12in; }
.agency p { margin: 0; }
.agency-name { font-weight: 700; font-size: 10.5pt; }
h1 { font-size: 13pt; letter-spacing: 0.2em; margin: 0.08in 0 0; }
.period { font-size: 9.5pt; }
.draft { font-weight: 700; color: #b00; letter-spacing: 0.05em; }
table { border-collapse: collapse; width: 100%; }
.person th, .person td { padding: 1px 4px; text-align: left; vertical-align: top; }
.person th { font-weight: 700; width: 0.9in; white-space: nowrap; }
.person td { border-bottom: 1px solid #999; }
.strong { font-weight: 700; }
.columns { display: grid; grid-template-columns: 1fr 1fr; gap: 0.25in; margin-top: 0.15in; }
.amounts caption { text-align: left; font-weight: 700; border-bottom: 1px solid #000; padding-bottom: 1px; }
.amounts td, .amounts th { padding: 1px 2px; }
.amounts tfoot th, .amounts tfoot td { border-top: 1px solid #000; font-weight: 700; text-align: left; }
.amounts tfoot td { text-align: right; }
.net { width: 3.4in; margin: 0.15in 0 0 auto; }
.net th, .net td { padding: 1px 4px; text-align: left; }
.net .total th, .net .total td { font-size: 11pt; font-weight: 700; border-top: 2px solid #000; border-bottom: 2px solid #000; }
.right { text-align: right !important; white-space: nowrap; font-variant-numeric: tabular-nums; }
.muted { color: #666; font-style: italic; }
.foot { margin-top: auto; padding-top: 0.2in; font-size: 7.5pt; color: #333; }
.foot p { margin: 0; }

@media print {
    @page { size: 8.5in 11in; margin: 0.25in; }
    .ps-toolbar { display: none; }
    .ps { background: none; padding: 0; min-height: 0; }
    .sheet { border: 1px dashed #999; margin: 0; }
}
</style>
