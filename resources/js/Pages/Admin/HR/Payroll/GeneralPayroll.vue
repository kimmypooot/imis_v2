<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

/**
 * HR-8: the General Payroll for a month, copied cell for cell from the
 * accounting office's "Final Payroll" sheet: the same columns and widths,
 * the same 59 rows to a page (agency lines, title, acknowledgement, three
 * header rows, the project line, six people on four rows each, the total,
 * the four certifications) and gridlines all over, as the workbook prints
 * them. Everything is scaled together so the page fits 13 × 8.5 in landscape.
 * The workbook's row-number column (AE) and check columns (AF onward) are
 * left out, so AMOUNT RECEIVED is the last column. No app layout.
 */
const props = defineProps({
    run: { type: Object, required: true },
    sheet: { type: Object, required: true },
});

/** Column widths of the workbook, in its own units (Z is "Other deductions", shown only when someone has any). */
const WIDTHS = {
    A: 0.89, B: 44.22, C: 25.44, D: 27.44, E: 25.89, F: 23.22, G: 23.22, H: 19.33, I: 27.89, J: 21.22, K: 19.78, L: 21.22, M: 21.66,
    N: 24.78, O: 21.22, P: 21.22, Q: 19.22, R: 21, S: 26.22, T: 25.44, U: 21.22, V: 21.22, W: 25.11, X: 22.66, Y: 25.33, Z: 25.78,
    AD: 34.22,
};
const FIRST_LINE = ['I', 'J', 'K', 'L', 'M', 'P', 'S', 'T', 'U', 'V', 'W', 'X', 'Y'];
const SECOND_LINE = ['I', 'J', 'K', 'L', 'M', 'P', 'S', 'T', 'V', 'W', 'X'];
/** The columns that hold a figure of their own on the PERA row. */
const FIELDS = { F: 'gsis_ps', G: 'gsis_gov', H: 'ecc', N: 'phic_ps', O: 'phic_gov', Q: 'hdmf_ps', R: 'hdmf_er' };
/** Rows a person takes on a page: a page holds six, with the workbook's blank row after each. */
const BLOCK_ROWS = 4;
const PER_PAGE = 6;

const columns = computed(() => Object.keys(WIDTHS).filter((key) => key !== 'Z' || props.sheet.has_other));
const dataColumns = computed(() => columns.value.filter((key) => !['A', 'B', 'C', 'D', 'E', 'AD'].includes(key)));
const widthTotal = computed(() => columns.value.reduce((total, key) => total + WIDTHS[key], 0));
const width = (key) => `${((WIDTHS[key] / widthTotal.value) * 100).toFixed(3)}%`;
/** How many printed columns lie from one workbook column to another, inclusive (for the merged cells). */
const span = (from, to) => columns.value.indexOf(to) - columns.value.indexOf(from) + 1;
/** The columns after this one, to finish a row. */
const after = (key) => columns.value.length - columns.value.indexOf(key) - 1;
/** Excel's column width in points (7 px a unit plus 5 px padding, at 96 dpi), so the whole sheet scales by one factor. */
const scale = computed(() => (12.5 * 72) / columns.value.reduce((total, key) => total + 0.75 * (7 * WIDTHS[key] + 5), 0));

/** The workbook's accounting format: two decimals, a dash for zero. */
const money = (value) => {
    const number = Number(value ?? 0);

    return Math.abs(number) < 0.005 ? '-' : number.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
};
const upper = (value) => (value || '').toString().toUpperCase();
const pageCount = computed(() => props.sheet.pages.length + 1);
const print = () => window.print();

const inPeraRow = (row, key) => {
    if (FIELDS[key]) {
        return money(row[FIELDS[key]]);
    }
    if (FIRST_LINE.includes(key)) {
        return money(row.first[key]);
    }

    return key === 'Z' ? money(row.other) : '';
};
const inLoanRow = (row, key) => (SECOND_LINE.includes(key) ? money(row.second[key]) : '');
const inTotals = (totals, key) => {
    if (FIELDS[key]) {
        return money(totals[FIELDS[key]]);
    }
    if (FIRST_LINE.includes(key)) {
        return money(totals.columns[key]);
    }

    return key === 'Z' ? money(totals.other) : '';
};
/** Blank rows between the last person and the total row (row 40). */
const padding = (rows) => Math.max(0, (PER_PAGE - rows.length) * BLOCK_ROWS) + 1;
</script>

<template>
    <Head :title="`General Payroll — ${run.period}`" />

    <div class="gp-toolbar">
        <p>
            <strong>General Payroll</strong> — {{ run.period }}. Print on long bond paper, landscape, at 100% scale with no margins; choose “Save as PDF” to keep a copy.
            <span v-if="run.status !== 'finalized'" class="draft-note">{{ run.status_label }}: the payroll is not final.</span>
        </p>
        <button type="button" @click="print">Print</button>
    </div>

    <main class="gp" :style="{ '--k': scale }">
        <section v-for="(page, index) in [...sheet.pages, null]" :key="index" class="sheet" aria-label="General Payroll">
            <table class="grid">
                <colgroup>
                    <col v-for="key in columns" :key="key" :style="{ width: width(key) }" />
                </colgroup>
                <tbody>
                    <!-- rows 1-3: the agency -->
                    <tr><td :colspan="columns.length" class="agency">Republic of the Philippines</td></tr>
                    <tr><td :colspan="columns.length" class="agency">CIVIL SERVICE COMMISSION RO VIII</td></tr>
                    <tr><td :colspan="columns.length" class="agency">Candahug, Palo, Leyte</td></tr>
                    <tr><td v-for="key in columns" :key="key"></td></tr>
                    <!-- row 5: the title -->
                    <tr>
                        <td :colspan="columns.length" class="agency title">G E N E R A L &nbsp;&nbsp; P A Y R O L L</td>
                    </tr>
                    <tr><td v-for="key in columns" :key="key"></td></tr>
                    <!-- rows 7-8: the acknowledgement -->
                    <tr>
                        <td></td>
                        <td :colspan="span('B', 'E')" class="text run">WE HEREBY ACKNOWLEDGE to have received from</td>
                        <td :colspan="span('F', 'I')" class="text under strong centered">{{ upper(sheet.signatories.paying_officer) }}</td>
                        <td :colspan="span('J', 'V')" class="text">the sums herein specified opposite our respective names being full compensation for our services for the period</td>
                        <td :colspan="span('W', 'Y')" class="centered under">{{ sheet.period }}</td>
                        <td :colspan="after('Y')"></td>
                    </tr>
                    <tr>
                        <td></td><td></td>
                        <td :colspan="span('C', 'M')" class="text">except as noted otherwise in the Remarks column.</td>
                        <td :colspan="after('M')"></td>
                    </tr>
                    <tr><td v-for="key in columns" :key="key"></td></tr>

                    <!-- rows 10-12: the column headings -->
                    <tr class="head">
                        <td rowspan="3"></td>
                        <th rowspan="3">NAME</th>
                        <th rowspan="3">DESIGNATION</th>
                        <th rowspan="2">Salary per Month</th>
                        <th rowspan="3">TOTAL AMOUNT</th>
                        <th rowspan="3">GSIS<br />Personal Share</th>
                        <th rowspan="3" class="employer">GSIS<br />Government Share</th>
                        <th rowspan="3" class="employer">GSIS ECC</th>
                        <th rowspan="2">GSIS<br />Consol. Loan</th>
                        <th rowspan="2">GSIS<br />Regular Policy Loan</th>
                        <th rowspan="2">GSIS SOLAR LOAN</th>
                        <th rowspan="2">GSIS MPL_LITE</th>
                        <th rowspan="3">GSIS<br />Multi Purpose Loan (MPL)</th>
                        <th rowspan="3">PHIC<br />Personal Share</th>
                        <th rowspan="3" class="employer">PHIC<br />Government Share</th>
                        <th rowspan="2">GSIS<br />HELP</th>
                        <th rowspan="3">HDMF<br />Personal Share</th>
                        <th rowspan="3" class="employer">HDMF<br />Employer Share</th>
                        <th rowspan="2">HDMF HOUSING LOAN</th>
                        <th rowspan="2">HDMF MP2EF</th>
                        <th rowspan="3">Withholding Tax</th>
                        <th rowspan="2">GSIS<br />EAL/FLEXI</th>
                        <th colspan="2" class="strong">WELFARE FUND</th>
                        <th></th>
                        <th v-if="sheet.has_other" rowspan="3">Other deductions</th>
                        <th rowspan="3" class="strong">AMOUNT RECEIVED</th>
                    </tr>
                    <tr class="head tall">
                        <th>Contribution</th>
                        <th>CSC PAL</th>
                        <th>PINTIG</th>
                    </tr>
                    <tr class="head taller">
                        <th>PERA</th>
                        <th>GSIS Real Estate Loan</th>
                        <th>Emerg Loan</th>
                        <th>CPL</th>
                        <th>OPT_LIFE</th>
                        <th>CEAP</th>
                        <th>MP LOAN</th>
                        <th>SAFE LOAN</th>
                        <th>eCA</th>
                        <th>MPL</th>
                        <th>GPL</th>
                        <th></th>
                        <th></th>
                    </tr>

                    <template v-if="page">
                        <!-- row 13: the project; row 14 blank -->
                        <tr class="data">
                            <td></td>
                            <td class="text strong project">Project {{ page.office }}</td>
                            <td v-for="key in columns.slice(2)" :key="key"></td>
                        </tr>
                        <tr class="data"><td v-for="key in columns" :key="key"></td></tr>
                        <template v-for="row in page.rows" :key="row.no">
                            <tr class="data">
                                <td></td>
                                <td class="text">{{ row.surname }}</td>
                                <td class="text position">{{ row.position }}</td>
                                <td class="num">{{ money(row.basic) }}</td>
                                <td></td>
                                <td v-for="key in dataColumns" :key="key"></td>
                                <td></td>
                            </tr>
                            <tr class="data">
                                <td></td>
                                <td class="text">{{ row.given }}</td>
                                <td></td>
                                <td class="num">{{ money(row.pera) }}</td>
                                <td class="num">{{ money(row.gross) }}</td>
                                <td v-for="key in dataColumns" :key="key" class="num">{{ inPeraRow(row, key) }}</td>
                                <td class="num strong">{{ money(row.net) }}</td>
                            </tr>
                            <tr class="data">
                                <td></td><td></td><td></td><td></td><td></td>
                                <td v-for="key in dataColumns" :key="key" class="num">{{ inLoanRow(row, key) }}</td>
                                <td></td>
                            </tr>
                            <tr class="data"><td v-for="key in columns" :key="key"></td></tr>
                        </template>
                        <tr v-for="n in padding(page.rows)" :key="`pad${n}`" class="data"><td v-for="key in columns" :key="key"></td></tr>
                        <tr class="data total">
                            <td></td>
                            <td class="text centered">T O T A L</td>
                            <td></td>
                            <td class="num">{{ money(page.totals.basic + page.totals.pera) }}</td>
                            <td class="num">{{ money(page.totals.gross) }}</td>
                            <td v-for="key in dataColumns" :key="key" class="num">{{ inTotals(page.totals, key) }}</td>
                            <td class="num">{{ money(page.totals.net) }}</td>
                        </tr>
                    </template>
                    <template v-else>
                        <!-- the last page: a recap of every office, three rows apiece -->
                        <tr class="data"><td v-for="key in columns" :key="key"></td></tr>
                        <template v-for="item in sheet.recap" :key="item.office">
                            <tr class="data">
                                <td></td>
                                <td class="text strong">{{ item.office }}</td>
                                <td></td>
                                <td class="num">{{ money(item.totals.basic + item.totals.pera) }}</td>
                                <td class="num">{{ money(item.totals.gross) }}</td>
                                <td v-for="key in dataColumns" :key="key" class="num">{{ inTotals(item.totals, key) }}</td>
                                <td class="num strong">{{ money(item.totals.net) }}</td>
                            </tr>
                            <tr class="data"><td v-for="key in columns" :key="key"></td></tr>
                            <tr class="data"><td v-for="key in columns" :key="key"></td></tr>
                        </template>
                        <tr class="data total">
                            <td></td>
                            <td class="text centered">T O T A L</td>
                            <td></td>
                            <td class="num">{{ money(sheet.totals.basic + sheet.totals.pera) }}</td>
                            <td class="num">{{ money(sheet.totals.gross) }}</td>
                            <td v-for="key in dataColumns" :key="key" class="num">{{ inTotals(sheet.totals, key) }}</td>
                            <td class="num">{{ money(sheet.totals.net) }}</td>
                        </tr>
                    </template>

                    <!-- rows 41-59: the certifications -->
                    <tr><td v-for="key in columns" :key="key"></td></tr>
                    <tr><td v-for="key in columns" :key="key"></td></tr>
                    <tr>
                        <td></td>
                        <td :colspan="span('B', 'P')" class="text run">(1) I CERTIFY on my official oath that the above Payroll is correct.</td>
                        <td :colspan="span('Q', 'W')" class="text">(3) I CERTIFY that funds are available in the amount of</td>
                        <td :colspan="span('X', 'Y')" class="num">{{ money(page ? page.totals.gross : sheet.totals.gross) }}</td>
                        <td :colspan="after('Y')"></td>
                    </tr>
                    <tr v-for="n in 5" :key="`c${n}`"><td v-for="key in columns" :key="key"></td></tr>
                    <tr>
                        <td :colspan="span('A', 'C')"></td>
                        <td :colspan="span('D', 'I')" class="centered strong">{{ upper(sheet.signatories.certifier) }}</td>
                        <td :colspan="span('J', 'T')"></td>
                        <td :colspan="span('U', 'AD')" class="centered strong">{{ upper(sheet.signatories.accountant) }}</td>
                    </tr>
                    <tr>
                        <td :colspan="span('A', 'C')"></td>
                        <td :colspan="span('D', 'I')" class="centered">Chief PS (Chief HRS)</td>
                        <td :colspan="span('J', 'T')"></td>
                        <td :colspan="span('U', 'AD')" class="centered">Accountant III</td>
                    </tr>
                    <tr><td v-for="key in columns" :key="key"></td></tr>
                    <tr>
                        <td></td>
                        <td :colspan="span('B', 'P')" class="text">(2) APPROVED payable from appropriation for</td>
                        <td :colspan="after('P')" class="text">(4) I CERTIFY on my official oath that I have paid each employee whose name appears on the</td>
                    </tr>
                    <tr>
                        <td :colspan="span('A', 'P')"></td>
                        <td :colspan="after('P')" class="text">above roll the amount set opposite his/her name.</td>
                    </tr>
                    <tr v-for="n in 3" :key="`d${n}`"><td v-for="key in columns" :key="key"></td></tr>
                    <tr>
                        <td :colspan="span('A', 'C')"></td>
                        <td :colspan="span('D', 'I')" class="centered strong">{{ upper(sheet.signatories.approver) }}</td>
                        <td :colspan="span('J', 'T')"></td>
                        <td :colspan="span('U', 'AD')" class="centered strong">{{ upper(sheet.signatories.paying_officer) }}</td>
                    </tr>
                    <tr>
                        <td :colspan="span('A', 'C')"></td>
                        <td :colspan="span('D', 'I')" class="centered">Director IV</td>
                        <td :colspan="span('J', 'T')"></td>
                        <td :colspan="span('U', 'AD')" class="centered">Chief PS (Chief HRS)</td>
                    </tr>
                    <tr><td v-for="key in columns" :key="key"></td></tr>
                </tbody>
            </table>
            <p class="foot">Page {{ index + 1 }} of {{ pageCount }}<span v-if="run.status !== 'finalized'"> · DRAFT — NOT FINAL</span></p>
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
.draft-note { margin-left: 0.5rem; font-weight: 600; color: var(--color-danger-fg, #b00); }
.gp { background: var(--color-surface-muted); padding: 0.3in 0; min-height: 100vh; }

/* One printed page: 13 × 8.5 in landscape. Every size below is the workbook's, times --k. */
.sheet {
    position: relative; width: 13in; height: 8.5in; margin: 0 auto 0.3in; padding: 0.25in 0.25in 0; box-sizing: border-box; overflow: hidden;
    background: #fff; color: #000; border: 1px solid #ccc;
    font: calc(22pt * var(--k) * 1.2) / 1.1 Arial, Helvetica, sans-serif;
}
.grid { border-collapse: collapse; width: 100%; table-layout: fixed; }
.grid td, .grid th {
    height: calc(35.1pt * var(--k)); padding: 0 calc(3pt * var(--k)); border: 0; overflow: hidden; white-space: nowrap;
    font-size: inherit; font-weight: 400; vertical-align: middle;
}
.grid .agency { border: 0; text-align: center; }
.grid .title { font-weight: 700; }
.grid .head th { border: 0.6px solid #000; text-align: center; white-space: normal; line-height: 1.05; }
.grid .head.tall th { height: calc(51pt * var(--k)); }
.grid .head.taller th { height: calc(61.5pt * var(--k)); }
.grid .head th.strong { font-weight: 700; }
.grid .head th.employer { background: #ffff00; font-weight: 700; }
.grid .data td:not(:first-child) { border-left: 0.6px solid #000; border-right: 0.6px solid #000; }
.grid .data .project { overflow: visible; }
.grid .data .position { font-size: 0.8em; white-space: normal; line-height: 1; }
.grid .total td:not(:first-child) { border-top: 0.6px solid #000; border-bottom: 0.6px solid #000; }
.text { text-align: left; }
.grid .run { overflow: visible; }
.num { text-align: right; font-variant-numeric: tabular-nums; padding-right: calc(10pt * var(--k)) !important; }
.centered { text-align: center; }
.strong { font-weight: 700 !important; }
.under { border: 0 !important; border-bottom: 0.8px solid #000 !important; }
.foot { position: absolute; left: 0; right: 0; bottom: 0.08in; margin: 0; text-align: center; font: 7pt Arial, sans-serif; color: #333; }

@media print {
    @page { size: 13in 8.5in; margin: 0; }
    .gp-toolbar { display: none; }
    .gp { background: none; padding: 0; min-height: 0; }
    .sheet { border: 0; margin: 0; break-after: page; page-break-after: always; }
    .sheet:last-child { break-after: auto; page-break-after: auto; }
}
</style>
