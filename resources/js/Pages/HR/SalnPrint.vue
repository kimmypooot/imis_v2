<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { AS1, AS2, FORM_PAGE_1 as P1, FORM_PAGE_2 as P2 } from '../../Components/HR/Saln/salnFormGeometry';

/**
 * The print-ready 2025 SALN Form (CSC Resolution No. 2500632, updated 3
 * February 2026). Each page is the official form itself, as Word renders
 * the CSC Word files (docs/reference/saln) at 300 dpi, with the entries
 * written onto its blanks at measured positions (salnFormGeometry.js). So
 * margins, spacing and lines are the form's own, on every computer.
 *
 * Pages: the form's two pages, AS-1 for the declarant's overflow, AS-2 for
 * the spouse's and children's, then a continuation sheet for children and
 * relatives beyond the form's rows. Printed from the browser on 8.5 × 13 in
 * paper with no margins at 100%.
 */
const props = defineProps({
    saln: { type: Object, required: true },
    version: { type: Object, required: true },
    layout: { type: Object, required: true },
    pageCount: { type: Number, required: true },
    backUrl: { type: String, default: null },
});

const s = computed(() => props.version.statement);
const main = computed(() => props.layout.main);
const v = (value) => (value === null || value === undefined ? '' : String(value));
const type = computed(() => props.saln.type);
const asOf = computed(() => props.saln.reckoning_date);
const spouseInGovernment = computed(() => Boolean(s.value.spouse?.in_government));
// The reference printed at the foot of each page (no version: HRD receives the latest).
const reference = computed(() => `IMIS ${props.version.reference}`);
// Joint Filing / Separate Filing / Not Applicable, as the declarant chose.
const filingMode = computed(() => s.value.filing_mode ?? (spouseInGovernment.value ? 'separate' : 'not_applicable'));
const FILING_BOX = { joint: 'joint', separate: 'separate', not_applicable: 'notApplicable' };

const longDate = (value) => {
    if (!value) return '';
    const [y, m, d] = value.slice(0, 10).split('-').map(Number);
    return new Date(y, m - 1, d).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' });
};
const asOfText = computed(() => (type.value === 'annual' ? `December 31, ${asOf.value.slice(0, 4)}` : longDate(asOf.value)));

const money = (value) => {
    if (value === null || value === undefined || value === '') return '';
    if (String(value).toUpperCase() === 'N/A') return 'N/A';
    const negative = String(value).startsWith('-');
    const [whole, cents = '00'] = String(value).replace('-', '').split('.');
    return `${negative ? '-' : ''}${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${cents.padEnd(2, '0')}`;
};

const continuation = computed(() => props.layout.continuation ?? { children: [], relatives: [] });
const continues = computed(() => continuation.value.children.length > 0 || continuation.value.relatives.length > 0);

// ── Placement (points from the page's top-left) ────────────────────
const justify = (align) => (align === 'left' ? 'flex-start' : align === 'right' ? 'flex-end' : 'center');

/** Text sitting on a ruled blank from x0 to x1 at y: its bottom just clear of the rule. */
const onLine = ([x0, x1], y, align = 'center', size = 9, gap = 1.2) => ({
    left: `${x0 + 1}pt`, width: `${x1 - x0 - 2}pt`, top: `${y - 11.5 - (gap - 1.2)}pt`, height: '11pt',
    alignItems: 'flex-end', paddingBottom: '1.2pt',
    justifyContent: justify(align), textAlign: align, '--size': `${size}pt`,
});
/**
 * A page number on the footer's blank, on the same baseline as "Page 1 of"
 * and in italic like it. Its box is taller than the type, so the digit is
 * never clipped.
 */
const pageNo = ([x0, x1], y) => ({ ...onLine([x0, x1], y, 'center', 12), top: `${y - 16.6}pt`, height: '16pt', paddingBottom: '0', lineHeight: '1.1', fontStyle: 'italic' });
/** Text inside table cell (row r, column c), centred vertically; wrapped lines keep the alignment. */
const cell = (cols, rows, c, r, align = 'left', size = 9) => ({
    left: `${cols[c] + 2.5}pt`, width: `${cols[c + 1] - cols[c] - 5}pt`,
    top: `${rows[r] + 0.8}pt`, height: `${rows[r + 1] - rows[r] - 1.6}pt`,
    justifyContent: justify(align), textAlign: align, '--size': `${size}pt`,
});

/**
 * Splits text into what fits on the first ruled line and the rest, for a
 * blank with a second line (the declarant's office address).
 */
let measurer = null;
const splitLines = (text, widthPt, sizePt) => {
    const value = v(text).trim().toUpperCase(); // measured as printed: in capitals
    if (!value || typeof document === 'undefined') return [value, ''];
    measurer ??= document.createElement('canvas').getContext('2d');
    measurer.font = `${sizePt}pt "Bookman Old Style", "TeX Gyre Bonum", serif`;
    const widthPx = (widthPt * 96) / 72;
    if (measurer.measureText(value).width <= widthPx) return [value, ''];
    const words = value.split(/\s+/);
    let first = '';
    while (words.length && measurer.measureText(`${first} ${words[0]}`.trim()).width <= widthPx) {
        first = `${first} ${words.shift()}`.trim();
    }
    // A single word longer than the line: let the fitter shrink it.
    return first ? [first, words.join(' ')] : [value, ''];
};
const addressLines = computed(() => splitLines(s.value.declarant.office_address, P1.right[1] - P1.right[0] - 2, 8));

// ── N/A: every blank the declarant fills prints N/A when it has no entry ──
const NA = 'N/A';
/** The value, or N/A when empty. */
const orNa = (value) => (v(value).trim() === '' ? NA : v(value));
/** A table row's cells, or N/A in each of its columns when the row is unused. */
const cellsOr = (row, cellsOf, columns) => (row ? cellsOf(row).map(([text, align]) => [v(text).trim() === '' ? NA : text, v(text).trim() === '' ? 'center' : align]) : Array.from({ length: columns }, () => [NA, 'center']));
/** Rows for a table the declarant ticked "none" for: all unused. */
const unused = (rows) => rows.map(() => null);
/** A tick in a box. */
const tick = ([x0, y0, x1, y1]) => ({ left: `${x0}pt`, top: `${y0}pt`, width: `${x1 - x0}pt`, height: `${y1 - y0}pt`, fontSize: `${(y1 - y0) * 1.3}pt` });

/** The smallest size a row is evened out to, and a one-line entry shrinks to. */
const READABLE = 6.5;

/** Shrinks an entry's type until it fits its blank, so nothing is cut off. */
const fit = (el) => {
    el.dataset.fit = '1';
    el.style.whiteSpace = '';
    el.style.lineHeight = '';
    const base = parseFloat(getComputedStyle(el).getPropertyValue('--size')) || 9;
    let size = base;
    el.style.fontSize = `${size}pt`;
    const overflows = () => el.scrollWidth > el.clientWidth + 0.5 || el.scrollHeight > el.clientHeight + 0.5;
    const shrinkTo = (floor) => {
        while (size > floor && overflows()) {
            size -= 0.25;
            el.style.fontSize = `${size}pt`;
        }
    };
    // On one line down to a readable size; past that, two lines, smaller
    // still if it must, rather than cut anything off.
    shrinkTo(READABLE);
    if (overflows()) {
        el.style.whiteSpace = 'normal';
        el.style.lineHeight = '1';
        shrinkTo(4.5);
    }
};
const vFit = { mounted: fit, updated: fit };

/**
 * One type size per table row: every cell takes the smallest size any cell
 * in its row needed, so a row reads evenly instead of one narrow column
 * (e.g. a relative's position) printing smaller than its neighbours.
 */
const evenRows = () => {
    const rows = {};
    document.querySelectorAll('.saln [data-row]').forEach((el) => (rows[el.dataset.row] ??= []).push(el));
    Object.values(rows).forEach((cells) => {
        const size = Math.max(READABLE, Math.min(...cells.map((el) => parseFloat(el.style.fontSize) || 9)));
        // Cells that needed less (a very long entry) keep their own size.
        cells.forEach((el) => (el.style.fontSize = `${Math.min(size, parseFloat(el.style.fontSize) || 9)}pt`));
    });
};
const fitAll = () => {
    document.querySelectorAll('.saln [data-fit]').forEach(fit);
    evenRows();
};

// Real properties: centred, except the acquisition cost (a currency, right).
const realCells = (row) => [
    [row?.description, 'center'], [row?.kind, 'center'], [row?.location, 'center'], [money(row?.assessed_value), 'center'],
    [money(row?.market_value), 'center'], [row?.acquisition_year, 'center'], [row?.acquisition_mode, 'center'], [money(row?.acquisition_cost), 'right'],
];
const personalCells = (row) => [[row?.description, 'left'], [row?.acquisition_year, 'center'], [money(row?.acquisition_cost), 'right']];
const liabilityCells = (row) => [[row?.nature, 'left'], [row?.creditor, 'left'], [money(row?.outstanding_balance), 'right']];
const businessCells = (row) => [[row?.name, 'center'], [row?.address, 'center'], [row?.nature, 'center'], [row?.acquired_on, 'center']];
const relativeCells = (row) => [[row?.name, 'center'], [row?.relationship, 'center'], [row?.position, 'center'], [row?.agency_and_address, 'center']];

const asSheets = computed(() => [
    ...props.layout.as1.map((sheet) => ({ kind: 'as1', g: AS1, sheet })),
    ...props.layout.as2.map((sheet) => ({ kind: 'as2', g: AS2, sheet })),
]);

// Bookman Old Style (Microsoft Office) is the form's typeface; elsewhere a
// close substitute writes the entries. The form itself is always exact.
const fontMissing = ref(false);
onMounted(async () => {
    evenRows();
    await document.fonts?.ready;
    fontMissing.value = document.fonts ? !document.fonts.check('10pt "Bookman Old Style"') : false;
    // Entries were first measured before the fonts finished loading.
    fitAll();
});

const print = () => window.print();
</script>

<template>
    <Head :title="`SALN ${version.reference} v${version.version} — print`" />

    <div class="saln-toolbar">
        <div>
            <p><strong>2025 SALN Form</strong> — {{ version.reference }} v{{ version.version }}, {{ pageCount }} page{{ pageCount === 1 ? '' : 's' }}</p>
            <p class="hint">
                Print on 8.5 × 13 in (Long) paper. In the print dialog: Margins <strong>None</strong>, Scale <strong>100%</strong>.
            </p>
            <p v-if="fontMissing" class="warn">Bookman Old Style isn't installed here, so the entries use a similar typeface. The form itself prints exactly.</p>
        </div>
        <div class="actions">
            <a v-if="backUrl" :href="backUrl">Back</a>
            <button type="button" @click="print">Print</button>
        </div>
    </div>

    <main class="saln">
        <!-- ═══════════════════════ PAGE 1 ═══════════════════════ -->
        <section class="sheet">
            <img class="bg" :src="P1.image" alt="" />

            <span class="tick" :style="tick(P1.compliance.boxes[type])">✓</span>
            <span v-if="type === 'entry'" v-fit class="v" :style="onLine(P1.compliance.entry, P1.compliance.line)">{{ longDate(asOf) }}</span>
            <span v-if="type === 'annual'" v-fit class="v" :style="onLine(P1.compliance.annual, P1.compliance.line)">{{ asOf.slice(0, 4) }}</span>
            <span v-if="type === 'exit'" v-fit class="v" :style="onLine(P1.compliance.exit, P1.compliance.line)">{{ longDate(asOf) }}</span>

            <!-- Declarant -->
            <span v-fit class="v" :style="onLine([P1.nameCols[0], P1.nameCols[1]], P1.declarant.name, 'center', 8)">{{ orNa(s.declarant.family_name) }}</span>
            <span v-fit class="v" :style="onLine([P1.nameCols[1], P1.nameCols[2]], P1.declarant.name, 'center', 8)">{{ orNa(s.declarant.first_name) }}</span>
            <span v-fit class="v" :style="onLine([P1.nameCols[2], P1.nameCols[3]], P1.declarant.name, 'center', 8)">{{ orNa(s.declarant.middle_initial) }}</span>
            <span v-fit class="v" :style="onLine(P1.right, P1.declarant.position, 'center', 8)">{{ orNa(s.declarant.position) }}</span>
            <span v-fit class="v" :style="onLine(P1.right, P1.declarant.agency, 'center', 8)">{{ orNa(s.declarant.agency) }}</span>
            <span v-fit class="v" :style="onLine(P1.right, P1.declarant.address[0], 'center', 8)">{{ orNa(addressLines[0]) }}</span>
            <span v-if="addressLines[1]" v-fit class="v" :style="onLine(P1.right, P1.declarant.address[1], 'center', 8)">{{ addressLines[1] }}</span>

            <!-- Spouse: position, agency and address only for a spouse in government (endnote i) -->
            <span v-fit class="v" :style="onLine([P1.nameCols[0], P1.nameCols[1]], P1.spouse.name, 'center', 8)">{{ orNa(s.spouse?.family_name) }}</span>
            <span v-fit class="v" :style="onLine([P1.nameCols[1], P1.nameCols[2]], P1.spouse.name, 'center', 8)">{{ orNa(s.spouse?.first_name) }}</span>
            <span v-fit class="v" :style="onLine([P1.nameCols[2], P1.nameCols[3]], P1.spouse.name, 'center', 8)">{{ orNa(s.spouse?.middle_initial) }}</span>
            <span
                v-for="[y, value] in [[P1.spouse.position, s.spouse?.position], [P1.spouse.agency, s.spouse?.agency], [P1.spouse.address[0], s.spouse?.office_address]]"
                :key="`spouse-${y}`"
                v-fit
                class="v"
                :style="onLine(P1.right, y, 'center', 8)"
            >{{ spouseInGovernment ? orNa(value) : NA }}</span>

            <span class="tick" :style="tick(P1.filing[FILING_BOX[filingMode]])">✓</span>

            <!-- Multiple marriages -->
            <span v-if="!s.other_spouses?.length" class="tick" :style="tick(P1.marriages.notApplicable)">✓</span>
            <span v-fit class="v" :style="onLine(P1.marriages.x, P1.marriages.lines[0], 'center')">{{ orNa(s.other_spouses?.[0]) }}</span>
            <span v-fit class="v" :style="onLine(P1.marriages.x, P1.marriages.lines[1], 'center')">{{ orNa(s.other_spouses?.slice(1).join('; ')) }}</span>

            <!-- Children -->
            <template v-for="(child, i) in main.children" :key="`child-${i}`">
                <span v-fit class="v" :style="onLine(P1.children.name, P1.children.lines[i], 'center', 10)">{{ child ? orNa(child.name) : NA }}</span>
                <span class="v" :style="onLine(P1.children.age, P1.children.lines[i], 'center', 10)">{{ child ? orNa(child.age) : NA }}</span>
            </template>

            <!-- a. Real properties -->
            <template v-for="(row, r) in main.real_properties" :key="`real-${r}`">
                <span v-for="([text, align], c) in cellsOr(row, realCells, 8)" :key="c" v-fit :data-row="`p1-real-${r}`" class="v wrap" :style="cell(P1.real.cols, P1.real.rows, c, r, align)">{{ text }}</span>
            </template>
            <span v-fit class="v" :style="onLine(P1.real.subtotal.x, P1.real.subtotal.line, 'right', 10)">{{ money(s.totals?.real_properties) }}</span>

            <!-- b. Personal properties -->
            <template v-for="(row, r) in main.personal_properties" :key="`personal-${r}`">
                <span v-for="([text, align], c) in cellsOr(row, personalCells, 3)" :key="c" v-fit :data-row="`p1-personal-${r}`" class="v" :style="cell(P1.personal.cols, P1.personal.rows, c, r, align)">{{ text }}</span>
            </template>
            <span v-fit class="v" :style="onLine(P1.personal.subtotal.x, P1.personal.subtotal.line, 'right', 10)">{{ money(s.totals?.personal_properties) }}</span>
            <span v-fit class="v b" :style="onLine(P1.personal.total.x, P1.personal.total.line, 'right', 10)">{{ money(s.totals?.total_assets) }}</span>

            <span class="v" :style="pageNo(P1.pageOf.x, P1.pageOf.line)">{{ pageCount }}</span>
            <span class="ref">{{ reference }}</span>
        </section>

        <!-- ═══════════════════════ PAGE 2 ═══════════════════════ -->
        <section class="sheet">
            <img class="bg" :src="P2.image" alt="" />

            <!-- 2. Liabilities -->
            <template v-for="(row, r) in main.liabilities" :key="`liability-${r}`">
                <span v-for="([text, align], c) in cellsOr(row, liabilityCells, 3)" :key="c" v-fit :data-row="`p2-liability-${r}`" class="v" :style="cell(P2.liabilities.cols, P2.liabilities.rows, c, r, align)">{{ text }}</span>
            </template>
            <span v-fit class="v" :style="onLine(P2.liabilities.total.x, P2.liabilities.total.line, 'right', 10)">{{ money(s.totals?.total_liabilities) }}</span>
            <span v-fit class="v b" :style="onLine(P2.liabilities.netWorth.x, P2.liabilities.netWorth.line, 'right', 10)">{{ money(s.totals?.net_worth) }}</span>

            <!-- Business interests and financial connections -->
            <span v-if="s.no_business_interests" class="tick" :style="tick(P2.business.none)">✓</span>
            <template v-for="(row, r) in (s.no_business_interests ? unused(main.business_interests) : main.business_interests)" :key="`business-${r}`">
                <span v-for="([text, align], c) in cellsOr(row, businessCells, 4)" :key="c" v-fit :data-row="`p2-business-${r}`" class="v" :style="cell(P2.business.cols, P2.business.rows, c, r, align)">{{ text }}</span>
            </template>

            <!-- Relatives in the government service -->
            <span v-if="s.no_relatives" class="tick" :style="tick(P2.relatives.none)">✓</span>
            <template v-for="(row, r) in (s.no_relatives ? unused(main.relatives) : main.relatives)" :key="`relative-${r}`">
                <span v-for="([text, align], c) in cellsOr(row, relativeCells, 4)" :key="c" v-fit :data-row="`p2-relative-${r}`" class="v" :style="cell(P2.relatives.cols, P2.relatives.rows, c, r, align, 8)">{{ text }}</span>
            </template>

            <!-- Government-issued ID: the declarant's, then the joint declarant's (never used) -->
            <span v-fit class="v" :style="onLine(P2.governmentId.x, P2.governmentId.type, 'left', 8)">{{ orNa(s.government_id?.type) }}</span>
            <span v-fit class="v" :style="onLine(P2.governmentId.x, P2.governmentId.number, 'left', 8)">{{ orNa(s.government_id?.number) }}</span>
            <span v-fit class="v" :style="onLine(P2.governmentId.x, P2.governmentId.issued, 'left', 8)">{{ orNa(longDate(s.government_id?.issued_on)) }}</span>
            <!-- The second block is the spouse's to sign and fill in by hand; N/A only when there is no spouse. -->
            <template v-if="!s.spouse">
                <span class="v" :style="onLine(P2.secondDeclarant.signature.x, P2.secondDeclarant.signature.line, 'center', 9)">N/A</span>
                <span v-for="y in [P2.governmentId.type, P2.governmentId.number, P2.governmentId.issued]" :key="`second-id-${y}`" class="v" :style="onLine(P2.secondDeclarant.idX, y, 'left', 8)">N/A</span>
            </template>

            <template v-if="version.officer_name">
                <span v-fit class="v b upper" :style="onLine(P2.officer.x, P2.officer.line, 'center', 9)">{{ version.officer_name }}</span>
                <span v-fit class="v" :style="{ ...onLine(P2.officer.x, P2.officer.title[1], 'center', 7), top: `${P2.officer.title[0]}pt`, height: `${P2.officer.title[1] - P2.officer.title[0]}pt` }">{{ version.officer_title }}</span>
            </template>

            <span class="v" :style="pageNo(P2.pageOf.x, P2.pageOf.line)">{{ pageCount }}</span>
            <span class="ref">{{ reference }}</span>
        </section>

        <!-- ═══════════════════ AS-1 / AS-2 SHEETS ═══════════════════ -->
        <section v-for="(page, n) in asSheets" :key="`as-${n}`" class="sheet">
            <img class="bg" :src="page.g.image" alt="" />

            <span v-fit class="v" :style="onLine(page.g.asOf.x, page.g.asOf.line, 'center', 10)">{{ asOfText }}</span>
            <span v-fit class="v" :style="onLine([page.g.nameCols[0], page.g.nameCols[1]], page.g.name, 'center', 8)">{{ orNa(s.declarant.family_name) }}</span>
            <span v-fit class="v" :style="onLine([page.g.nameCols[1], page.g.nameCols[2]], page.g.name, 'center', 8)">{{ orNa(s.declarant.first_name) }}</span>
            <span v-fit class="v" :style="onLine([page.g.nameCols[2], page.g.nameCols[3]], page.g.name, 'center', 8)">{{ orNa(s.declarant.middle_initial) }}</span>
            <span v-fit class="v" :style="onLine(page.g.right, page.g.name, 'center', 8)">{{ orNa(s.declarant.position) }}</span>
            <span v-fit class="v" :style="onLine(page.g.right, page.g.agency, 'center', 8)">{{ orNa(s.declarant.agency) }}</span>

            <template v-for="(row, r) in page.sheet.real_properties" :key="`as-real-${r}`">
                <span v-for="([text, align], c) in cellsOr(row, realCells, 8)" :key="c" v-fit :data-row="`as${n}-real-${r}`" class="v wrap" :style="cell(page.g.real.cols, page.g.real.rows, c, r, align)">{{ text }}</span>
            </template>
            <template v-for="(row, r) in page.sheet.personal_properties" :key="`as-personal-${r}`">
                <span v-for="([text, align], c) in cellsOr(row, personalCells, 3)" :key="c" v-fit :data-row="`as${n}-personal-${r}`" class="v" :style="cell(page.g.personal.cols, page.g.personal.rows, c, r, align)">{{ text }}</span>
            </template>
            <template v-for="(row, r) in page.sheet.liabilities" :key="`as-liability-${r}`">
                <span v-for="([text, align], c) in cellsOr(row, liabilityCells, 3)" :key="c" v-fit :data-row="`as${n}-liability-${r}`" class="v" :style="cell(page.g.liabilities.cols, page.g.liabilities.rows, c, r, align)">{{ text }}</span>
            </template>
            <template v-for="(row, r) in page.sheet.business_interests" :key="`as-business-${r}`">
                <span v-for="([text, align], c) in cellsOr(row, businessCells, 4)" :key="c" v-fit :data-row="`as${n}-business-${r}`" class="v" :style="cell(page.g.business.cols, page.g.business.rows, c, r, align)">{{ text }}</span>
            </template>

            <template v-if="page.kind === 'as1'">
                <span v-fit class="v" :style="onLine(page.g.real.subtotal.x, page.g.real.subtotal.line, 'right', 10)">{{ money(page.sheet.totals.real_properties) }}</span>
                <span v-fit class="v" :style="onLine(page.g.personal.subtotal.x, page.g.personal.subtotal.line, 'right', 10)">{{ money(page.sheet.totals.personal_properties) }}</span>
                <span v-fit class="v b" :style="onLine(page.g.personal.total.x, page.g.personal.total.line, 'right', 10)">{{ money(page.sheet.totals.total_assets) }}</span>
                <span v-fit class="v" :style="onLine(page.g.liabilities.total.x, page.g.liabilities.total.line, 'right', 10)">{{ money(page.sheet.totals.total_liabilities) }}</span>
            </template>

            <span class="v" :style="pageNo(page.g.page.x, page.g.page.line)">{{ n + 3 }}</span>
            <span class="v" :style="pageNo(page.g.of.x, page.g.of.line)">{{ pageCount }}</span>
            <span class="ref">{{ reference }}</span>
        </section>

        <!-- ═══════════════ CONTINUATION (children, relatives) ═══════════════ -->
        <section v-if="continues" class="sheet continuation">
            <h1>SWORN STATEMENT OF ASSETS, LIABILITIES AND NET WORTH</h1>
            <p class="c">As of <span class="ul">{{ asOfText }}</span></p>
            <p class="c i">(Continuation sheet for the declarant)</p>
            <p class="name">
                <strong>NAME:</strong> {{ [s.declarant.family_name, s.declarant.first_name, s.declarant.middle_initial].filter(Boolean).join(', ') }}
                &nbsp;&nbsp; <strong>POSITION:</strong> {{ v(s.declarant.position) }}
            </p>

            <template v-if="continuation.children.length">
                <h2>UNMARRIED CHILDREN BELOW EIGHTEEN (18) YEARS OF AGE LIVING IN DECLARANT’S HOUSEHOLD</h2>
                <table>
                    <tr><th>NAME OF CHILD</th><th class="age">AGE</th></tr>
                    <tr v-for="(child, i) in continuation.children" :key="i"><td>{{ child.name }}</td><td class="c">{{ child.age }}</td></tr>
                </table>
            </template>

            <template v-if="continuation.relatives.length">
                <h2>RELATIVES IN THE GOVERNMENT SERVICE</h2>
                <table>
                    <tr><th>NAME OF RELATIVE</th><th>RELATIONSHIP</th><th>POSITION</th><th>NAME OF AGENCY/OFFICE AND ADDRESS</th></tr>
                    <tr v-for="(row, i) in continuation.relatives" :key="i">
                        <td>{{ row.name }}</td><td>{{ row.relationship }}</td><td>{{ row.position }}</td><td>{{ row.agency_and_address }}</td>
                    </tr>
                </table>
            </template>

            <div class="initial"><span>Signature/Initial of Declarant</span></div>
            <p class="page-no">Page {{ pageCount }} of {{ pageCount }}</p>
            <span class="ref">{{ reference }}</span>
        </section>
    </main>
</template>

<style>
/* The 2025 SALN Form: the official page as the background, entries on top.
   Fixed black-on-white on purpose: this is a paper form, not the app UI. */
.saln {
    font-family: 'Bookman Old Style', 'TeX Gyre Bonum', serif;
    color: #000;
    background: #9ca3af;
    padding: 1rem 0;
}
.saln .sheet {
    position: relative;
    width: 612pt;
    height: 936pt;
    margin: 0 auto 1rem;
    background: #fff;
    overflow: hidden;
    box-shadow: 0 2px 8px rgb(0 0 0 / 0.3);
}
.saln .bg { position: absolute; inset: 0; width: 612pt; height: 936pt; }

.saln .v {
    position: absolute;
    display: flex;
    align-items: center;
    overflow: hidden;
    white-space: nowrap;
    font-size: var(--size, 9pt);
    line-height: 1.1;
    /* Entries print in capitals, as the form is filled in. */
    text-transform: uppercase;
}
.saln .v.wrap { white-space: normal; line-height: 1.05; }
.saln .v.b { font-weight: 700; }
.saln .v.upper { text-transform: uppercase; }
.saln .tick {
    position: absolute;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    font-weight: 700;
    font-family: 'Segoe UI Symbol', 'DejaVu Sans', sans-serif;
}
/* The IMIS reference, in the bottom margin clear of the form. */
.saln .ref { position: absolute; right: 22pt; bottom: 4pt; font-size: 6pt; color: #444; font-family: system-ui, sans-serif; }

/* The continuation sheet, styled after AS-1. */
.saln .continuation { padding: 40pt 22pt 30pt 28pt; box-sizing: border-box; font-size: 10pt; }
.saln .continuation h1 { text-align: center; font-size: 12pt; font-weight: 700; margin: 0 0 6pt; }
.saln .continuation h2 { text-align: center; font-size: 10pt; font-weight: 700; text-decoration: underline; margin: 18pt 0 6pt; }
.saln .continuation .c { text-align: center; }
.saln .continuation .i { font-style: italic; margin-top: 4pt; }
.saln .continuation .ul { border-bottom: 0.5pt solid #000; padding: 0 12pt; }
.saln .continuation .name { margin-top: 16pt; font-size: 8pt; }
.saln .continuation table { width: 100%; border-collapse: collapse; font-size: 9pt; }
.saln .continuation th { background: #c0c0c0; font-size: 8pt; border: 0.5pt solid #000; padding: 3pt; }
.saln .continuation td { border: 0.5pt solid #000; padding: 3pt 5pt; height: 12pt; text-transform: uppercase; }
.saln .continuation .age { width: 100pt; }
.saln .continuation .initial { position: absolute; right: 22pt; bottom: 40pt; width: 120pt; text-align: center; }
.saln .continuation .initial span { display: block; border-top: 0.5pt solid #000; font-size: 6pt; font-style: italic; }
.saln .continuation .page-no { position: absolute; left: 0; right: 0; bottom: 42pt; text-align: center; font-style: italic; font-size: 12pt; }

.saln-toolbar {
    position: sticky;
    top: 0;
    z-index: 10;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
    padding: 0.5rem 1rem;
    background: var(--color-csc-blue);
    color: #fff;
    font: 0.85rem system-ui, sans-serif;
}
.saln-toolbar .hint { opacity: 0.9; }
.saln-toolbar .warn { margin-top: 0.25rem; font-weight: 600; }
.saln-toolbar .actions { display: flex; gap: 0.5rem; align-items: center; }
.saln-toolbar a { color: #fff; text-decoration: underline; }
.saln-toolbar button {
    background: #fff;
    color: var(--color-csc-blue);
    border-radius: 0.375rem;
    padding: 0.35rem 1rem;
    font-weight: 600;
}

@media print {
    @page { size: 8.5in 13in; margin: 0; }
    html, body { margin: 0; padding: 0; }
    .saln-toolbar { display: none; }
    .saln { background: none; padding: 0; }
    .saln .sheet { box-shadow: none; margin: 0; break-after: page; }
    .saln .sheet:last-of-type { break-after: auto; }
}
</style>
