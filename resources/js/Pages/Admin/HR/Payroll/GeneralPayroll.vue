<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

/**
 * HR-8: the General Payroll for a month, laid out as the accounting office
 * signs it: landscape on long bond paper (13 × 8.5 in), people grouped by
 * office, one column per deduction anyone has, and a column to sign for the
 * amount received. Print or "Save as PDF" from the browser. No app layout.
 * The employer's shares (GSIS, PhilHealth, Pag-IBIG) are not payroll lines
 * in IMIS, so they are not shown.
 */
const props = defineProps({
    run: { type: Object, required: true },
    payroll: { type: Object, required: true },
    organization: { type: String, default: null },
    address: { type: String, default: null },
});

const money = (value) => Number(value ?? 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const cell = (amounts, key) => (amounts?.[key] ? money(amounts[key]) : '');
const quincena = (value) => (value ? new Date(`${value.slice(0, 10)}T00:00:00`).toLocaleDateString('en-PH', { month: 'long', day: 'numeric' }) : '');

const columnCount = computed(() => props.payroll.columns.length);
const printed = new Date().toLocaleString('en-PH', { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' });
let counter = 0;
const numbered = (groups) => groups.map((group) => ({ ...group, rows: group.rows.map((row) => ({ ...row, no: ++counter })) }));
const groups = computed(() => {
    counter = 0;

    return numbered(props.payroll.groups);
});
const print = () => window.print();
</script>

<template>
    <Head :title="`General Payroll — ${run.period}`" />

    <div class="gp-toolbar">
        <p>
            <strong>General Payroll</strong> — {{ run.period }}. Print on long bond paper, landscape; choose “Save as PDF” to keep a copy.
        </p>
        <button type="button" @click="print">Print</button>
    </div>

    <main class="gp">
        <section class="sheet" aria-label="General Payroll">
            <header class="agency">
                <p>Republic of the Philippines</p>
                <p class="agency-name">{{ (organization || '').toUpperCase() }}</p>
                <p v-if="address">{{ address }}</p>
                <h1>GENERAL PAYROLL</h1>
                <p>
                    We hereby acknowledge to have received the sums herein specified opposite our respective names, being full compensation for our
                    services for the period <strong>{{ run.period }}</strong>, except as noted otherwise in the Remarks column.
                </p>
                <p v-if="run.status !== 'finalized'" class="draft">DRAFT — NOT FINAL</p>
            </header>

            <table class="register">
                <thead>
                    <tr>
                        <th rowspan="2" class="no">No.</th>
                        <th rowspan="2" class="name">Name / Designation</th>
                        <th rowspan="2">Salary per month</th>
                        <th rowspan="2">PERA &amp; allowances</th>
                        <th rowspan="2">Gross amount</th>
                        <th v-if="columnCount" :colspan="columnCount">Deductions</th>
                        <th rowspan="2">Total deductions</th>
                        <th rowspan="2">Net amount</th>
                        <th colspan="2">Net, released</th>
                        <th rowspan="2" class="sign">Signature of payee</th>
                    </tr>
                    <tr>
                        <th v-for="column in payroll.columns" :key="column.key" class="deduction">{{ column.label }}</th>
                        <th>{{ quincena(run.first_release_on) }}</th>
                        <th>{{ quincena(run.second_release_on) }}</th>
                    </tr>
                </thead>
                <template v-for="group in groups" :key="group.office">
                    <tbody>
                        <tr class="office">
                            <th :colspan="10 + columnCount">{{ group.office }}</th>
                        </tr>
                        <tr v-for="row in group.rows" :key="row.no">
                            <td class="no">{{ row.no }}</td>
                            <td class="name"><strong>{{ row.name }}</strong><span class="position">{{ row.position }}</span></td>
                            <td class="num">{{ money(row.basic) }}</td>
                            <td class="num">{{ money(row.allowances) }}</td>
                            <td class="num">{{ money(row.gross) }}</td>
                            <td v-for="column in payroll.columns" :key="column.key" class="num">{{ cell(row.columns, column.key) }}</td>
                            <td class="num">{{ money(row.deductions) }}</td>
                            <td class="num strong">{{ money(row.net) }}</td>
                            <td class="num">{{ money(row.first_half) }}</td>
                            <td class="num">{{ money(row.second_half) }}</td>
                            <td class="sign"></td>
                        </tr>
                        <tr class="subtotal">
                            <th colspan="2">Total, {{ group.office }}</th>
                            <td class="num">{{ money(group.totals.basic) }}</td>
                            <td class="num">{{ money(group.totals.allowances) }}</td>
                            <td class="num">{{ money(group.totals.gross) }}</td>
                            <td v-for="column in payroll.columns" :key="column.key" class="num">{{ cell(group.totals.columns, column.key) }}</td>
                            <td class="num">{{ money(group.totals.deductions) }}</td>
                            <td class="num">{{ money(group.totals.net) }}</td>
                            <td class="num">{{ money(group.totals.first_half) }}</td>
                            <td class="num">{{ money(group.totals.second_half) }}</td>
                            <td></td>
                        </tr>
                    </tbody>
                </template>
                <tfoot>
                    <tr class="grand">
                        <th colspan="2">GRAND TOTAL</th>
                        <td class="num">{{ money(payroll.totals.basic) }}</td>
                        <td class="num">{{ money(payroll.totals.allowances) }}</td>
                        <td class="num">{{ money(payroll.totals.gross) }}</td>
                        <td v-for="column in payroll.columns" :key="column.key" class="num">{{ cell(payroll.totals.columns, column.key) }}</td>
                        <td class="num">{{ money(payroll.totals.deductions) }}</td>
                        <td class="num">{{ money(payroll.totals.net) }}</td>
                        <td class="num">{{ money(payroll.totals.first_half) }}</td>
                        <td class="num">{{ money(payroll.totals.second_half) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>

            <section class="certifications">
                <div>
                    <p><strong>(1)</strong> I CERTIFY on my official oath that the above Payroll is correct.</p>
                    <p class="line"></p>
                    <p class="caption">Head, Human Resource Division / Certifying Officer</p>
                </div>
                <div>
                    <p><strong>(2)</strong> APPROVED for payment.</p>
                    <p class="line"></p>
                    <p class="caption">Head of Office</p>
                </div>
                <div>
                    <p><strong>(3)</strong> I CERTIFY that funds are available in the amount of <strong>₱{{ money(payroll.totals.gross) }}</strong>.</p>
                    <p class="line"></p>
                    <p class="caption">Accountant</p>
                </div>
            </section>

            <footer class="foot">
                <p>Printed {{ printed }} from IMIS. {{ payroll.groups.reduce((total, group) => total + group.rows.length, 0) }} payees. Cancelled payslips are not listed.</p>
            </footer>
        </section>
    </main>
</template>

<style scoped>
.gp-toolbar {
    position: sticky; top: 0; z-index: 10;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: 0.6rem 1rem; background: var(--color-surface); border-bottom: 1px solid var(--color-border);
    font: 13px/1.4 system-ui, sans-serif; color: var(--color-text);
}
.gp-toolbar button { padding: 0.4rem 1rem; border-radius: 0.375rem; background: var(--color-primary); color: #fff; font-weight: 600; }
.gp { background: var(--color-surface-muted); padding: 0.3in 0; min-height: 100vh; }
.sheet {
    width: 13in; margin: 0 auto; padding: 0.3in; box-sizing: border-box;
    background: #fff; color: #000; font: 7.5pt/1.25 Arial, Helvetica, sans-serif; border: 1px solid #ccc;
}
.agency { text-align: center; margin-bottom: 0.1in; }
.agency p { margin: 0; }
.agency-name { font-weight: 700; font-size: 10pt; }
h1 { font-size: 12pt; letter-spacing: 0.25em; margin: 0.06in 0; }
.draft { font-weight: 700; color: #b00; letter-spacing: 0.05em; }
table { border-collapse: collapse; width: 100%; }
.register th, .register td { border: 1px solid #000; padding: 1px 3px; vertical-align: middle; }
.register thead th { text-align: center; font-weight: 700; background: #f2f2f2; }
.register th.deduction { font-weight: 600; font-size: 6.5pt; }
.name { text-align: left; min-width: 1.7in; }
.name .position { display: block; font-size: 6.5pt; color: #333; }
.no { width: 0.3in; text-align: center; }
.sign { min-width: 1.1in; }
.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.strong { font-weight: 700; }
.office th { text-align: left; background: #e8e8e8; font-weight: 700; }
.subtotal th, .subtotal td { font-weight: 700; background: #f7f7f7; }
.grand th, .grand td { font-weight: 700; border-top: 2px solid #000; }
.certifications { display: grid; grid-template-columns: repeat(3, 1fr); gap: 0.3in; margin-top: 0.2in; break-inside: avoid; }
.certifications p { margin: 0; }
.line { border-bottom: 1px solid #000; margin-top: 0.35in !important; }
.caption { text-align: center; font-size: 6.5pt; }
.foot { margin-top: 0.15in; font-size: 6.5pt; color: #333; }
.foot p { margin: 0; }
thead { display: table-header-group; }
tr { break-inside: avoid; }

@media print {
    @page { size: 13in 8.5in; margin: 0.25in; }
    .gp-toolbar { display: none; }
    .gp { background: none; padding: 0; min-height: 0; }
    .sheet { border: 0; margin: 0; padding: 0; width: auto; }
}
</style>
