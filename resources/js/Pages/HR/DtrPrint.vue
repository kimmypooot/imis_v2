<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';

/**
 * The printable Daily Time Record, Civil Service Form No. 48, laid out cell
 * for cell like HR's "DTR Template.xls": A4 portrait, the same 17-column
 * grid, row heights, fonts and borders, printed at 97%. One sheet is one
 * office and one period; days 1 to 31 are always listed, as on the
 * template. Print or "Save as PDF" from the browser. No app layout.
 *
 * Worked hours print as hours.minutes ("9.49" is 9 h 49 min), late and
 * undertime hours as hh:mm, and a zero as "0", as the template does.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    sheet: { type: Object, required: true },
    generated_at: { type: String, required: true },
    /** 'single': one form on an A4 portrait page. 'double': two identical, narrower forms side by side on one A4 portrait page. */
    layout: { type: String, default: 'single' },
});

const isDouble = computed(() => props.layout === 'double');
const copies = computed(() => (isDouble.value ? [1, 2] : [1]));

/*
 * The column widths in cm. A single form is the template's 20.211 cm grid. A double
 * one is the "two DTR in one paper" report's narrower grid (its column widths, in the
 * same 17 columns), scaled so two forms and the gap between them fit A4's width.
 */
const SINGLE_COLUMNS = [1.108, 1.695, 0.432, 1.259, 1.259, 0.171, 0.28, 0.52, 0.716, 0.236, 0.215, 1.695, 0.716, 3.565, 3.935, 0.432, 1.977];
const DOUBLE_COLUMNS = [4.1, 8.35, 2.73, 1.25, 3.11, 1.24, 2.84, 3.43, 2.05, 0.97, 4.09, 3.28, 0.66, 6.85, 3.96, 1.23, 5.22];
const DOUBLE_FORM_WIDTH = 10.15;
/*
 * How many of the 17 columns each cell spans. The double form is not the single one
 * squeezed: its report lays the same rows over a different set of spans (a wide
 * Break-out, a two-column Worked Hrs., a three-column Remarks, ...).
 */
const SPANS = {
    single: {
        title: 5, logo: 5, agency: 7, blank: 5, formNo: 7, nameLabel: 5, nameValue: 12,
        refLabel: 5, refText: 9, countLabel: 2, periodLabel: 5, periodText: 9,
        morning: 3, afternoon: 7, worked: 1, remarks: 5, breakOut: 2, breakIn: 3, timeOut: 4,
        totalLabel: 9, totalValue: 4, totalRightLabel: 2, totalRightValue: 2,
        qrLabel: 3, signLabel: 5, signature: 9, head: 14, qr: 3, footLabel: 3, footValue: 11, footer: 14,
    },
    double: {
        title: 3, logo: 4, agency: 10, blank: 4, formNo: 10, nameLabel: 3, nameValue: 14,
        refLabel: 3, refText: 10, countLabel: 3, periodLabel: 3, periodText: 10,
        morning: 5, afternoon: 6, worked: 2, remarks: 3, breakOut: 4, breakIn: 3, timeOut: 3,
        totalLabel: 5, totalValue: 3, totalRightLabel: 7, totalRightValue: 2,
        qrLabel: 4, signLabel: 7, signature: 6, head: 13, qr: 4, footLabel: 6, footValue: 7, footer: 13,
    },
};
const g = computed(() => SPANS[isDouble.value ? 'double' : 'single']);
const printedBy = computed(() => usePage().props.auth?.user?.username ?? '');

const columnWidths = computed(() => {
    if (!isDouble.value) return SINGLE_COLUMNS;
    const total = DOUBLE_COLUMNS.reduce((sum, width) => sum + width, 0);

    return DOUBLE_COLUMNS.map((width) => Number(((width * DOUBLE_FORM_WIDTH) / total).toFixed(4)));
});

const logo = computed(() => usePage().props.branding?.logo);
const upper = (value) => String(value ?? '').toUpperCase();

/** 7:51AM, as the form prints times. */
const clock = (value) => {
    if (!value) return '';
    const [h, m] = value.split(':').map(Number);
    return `${h % 12 || 12}:${String(m).padStart(2, '0')}${h < 12 ? 'AM' : 'PM'}`;
};

const pad = (n) => String(n).padStart(2, '0');
/** 9.49 for 9 h 49 min; blank for none on a day row. */
const worked = (minutes) => (minutes ? `${Math.floor(minutes / 60)}.${pad(minutes % 60)}` : '');
/** 00:12 for 12 minutes; a plain 0 when there is none. */
const hoursMinutes = (minutes) => (minutes ? `${pad(Math.floor(minutes / 60))}:${pad(minutes % 60)}` : '0');

// Days 1 to 31, filled from the sheet's rows (a sheet covers days in one month).
const days = computed(() => {
    const byDay = Object.fromEntries(props.sheet.rows.map((row) => [Number(row.date.slice(8)), row]));

    return Array.from({ length: 31 }, (_, index) => ({ day: index + 1, row: byDay[index + 1] ?? null }));
});

const totals = props.sheet.totals;
const left = [
    ['Total hours work', worked(totals.minutes) || '0'],
    ['Total hours late', hoursMinutes(totals.late_minutes)],
    ['Total hours undertime', hoursMinutes(totals.undertime_minutes)],
    ['Total no. of absences', totals.absences],
];
const right = [
    ['Total no. of days travelled', totals.travel_days],
    ['Total no. of days on-leave', totals.leave_days],
    ['Total no. of undertime', totals.undertime_count],
    ['Total no. of late', totals.late_count],
];

// "11/19/2026 6:14:00 PM", as the template's Generated Date.
const generated = (() => {
    const date = new Date(props.generated_at.replace(' ', 'T'));
    const time = date.toLocaleTimeString('en-US', { hour: 'numeric', minute: '2-digit', second: '2-digit', hour12: true });

    return `${date.getMonth() + 1}/${date.getDate()}/${date.getFullYear()} ${time}`;
})();

const print = () => window.print();

// A signed sheet carries a QR code to the validation page, drawn here in the browser.
const qr = ref('');
onMounted(async () => {
    if (props.sheet.signature?.verify_url) {
        qr.value = await QRCode.toString(props.sheet.signature.verify_url, { type: 'svg', margin: 0, errorCorrectionLevel: 'M' });
    }
});
</script>

<template>
    <Head title="Daily Time Record — print" />

    <div class="dtr-toolbar">
        <p>
            <strong>Daily Time Record</strong> — {{ sheet.period }}. Use your browser's print dialog; choose “Save as PDF” to keep a copy. Set the paper to A4, portrait, with Scale at 100% (“Default”).
            <template v-if="isDouble">Two copies are printed on the page: cut it down the middle.</template>
        </p>
        <button type="button" @click="print">Print</button>
    </div>

    <main class="dtr">
        <section class="page" :class="{ double: isDouble }">
            <!-- The faded agency logo behind the form(s): the "two DTR in one paper" report has it, and a single DTR now matches. -->
            <img v-if="logo" class="watermark" :src="logo" alt="" />
            <div class="copies">
                <div v-for="copy in copies" :key="copy" class="copy">
                    <table class="tpl" :style="{ width: `${columnWidths.reduce((sum, width) => sum + width, 0)}cm` }">
                        <colgroup>
                            <col v-for="(width, index) in columnWidths" :key="index" :style="{ width: `${width}cm` }" />
                        </colgroup>
                        <tbody>
                            <!-- Title, logo and agency -->
                            <tr style="height: 0.529cm">
                                <td :colspan="g.title" rowspan="4" class="c title">DAILY TIME RECORD</td>
                                <td :colspan="g.logo" rowspan="3" class="bt logo">
                                    <img v-if="logo" :src="logo" alt="" />
                                </td>
                                <td :colspan="g.agency" rowspan="3" class="btr s agency-text">
                                    <p class="f8">Republic of the Philippines</p>
                                    <p class="f9 bold">CIVIL SERVICE COMMISSION</p>
                                    <p class="f6">CSC Building, IBP Road, Constitution Hills, Diliman, 1126 Quezon City</p>
                                </td>
                            </tr>
                            <tr style="height: 0.492cm"></tr>
                            <tr style="height: 0.46cm"></tr>
                            <tr style="height: 0.492cm">
                                <td :colspan="g.blank" class="nb"></td>
                                <td :colspan="g.formNo" class="br e f8 bold">Civil Service Form No. 48</td>
                            </tr>
        
                            <!-- Employee, time reference, period and the day counts -->
                            <tr style="height: 0.704cm">
                                <td :colspan="g.nameLabel" class="e f8-5">Employee's Name</td>
                                <td :colspan="g.nameValue" class="s f11 bold name">{{ upper(employee.name) }}</td>
                            </tr>
                            <tr style="height: 0.598cm">
                                <td :colspan="g.refLabel" rowspan="2" class="e f9">Time Reference</td>
                                <td :colspan="g.refText" rowspan="2" class="s f10 wrap schedule">{{ sheet.schedule ?? 'No work schedule assigned' }}</td>
                                <td :colspan="g.countLabel" class="e f9">Regular Days</td>
                                <td class="c f10">{{ totals.regular_days }}</td>
                            </tr>
                            <tr style="height: 0.492cm">
                                <td :colspan="g.countLabel" class="e f9">Weekends</td>
                                <td class="c f10">{{ totals.weekend_days }}</td>
                            </tr>
                            <tr style="height: 0.566cm">
                                <td :colspan="g.periodLabel" class="e f9">For the period of</td>
                                <td :colspan="g.periodText" class="s f10 bold">{{ sheet.period }}</td>
                                <td :colspan="g.countLabel" class="e f9">Holiday</td>
                                <td class="c f10">{{ totals.holiday_days }}</td>
                            </tr>
        
                            <!-- Column headings -->
                            <tr style="height: 0.423cm">
                                <td rowspan="2" class="c f10 bold">Day</td>
                                <td :colspan="g.morning" class="c f10 bold">MORNING</td>
                                <td :colspan="g.afternoon" class="c f10 bold">AFTERNOON</td>
                                <td :colspan="g.worked" rowspan="2" class="c f8 bold wrap">Worked Hrs.</td>
                                <td :colspan="g.remarks" rowspan="2" class="c f10 bold">Remarks</td>
                            </tr>
                            <tr style="height: 0.423cm">
                                <td class="c f8-5">Time-in</td>
                                <td :colspan="g.breakOut" class="c f8-5">Break-out</td>
                                <td :colspan="g.breakIn" class="c f8-5">Break-in</td>
                                <td :colspan="g.timeOut" class="c f8-5">Time-out</td>
                            </tr>
        
                            <!-- Days 1 to 31 -->
                            <tr v-for="item in days" :key="item.day" style="height: 0.423cm">
                                <td class="c f9">{{ item.day }}</td>
                                <td class="c f9">{{ clock(item.row?.in) }}</td>
                                <td :colspan="g.breakOut" class="c f9">{{ clock(item.row?.break_out) }}</td>
                                <td :colspan="g.breakIn" class="c f9">{{ clock(item.row?.break_in) }}</td>
                                <td :colspan="g.timeOut" class="c f9">{{ clock(item.row?.out) }}</td>
                                <td :colspan="g.worked" class="c f9">{{ worked(item.row?.minutes) }}</td>
                                <td :colspan="g.remarks" class="c f7-5 remark" :title="item.row?.remark ?? undefined">{{ item.row?.remark ?? '' }}</td>
                            </tr>
        
                            <!-- Totals -->
                            <tr v-for="(item, index) in left" :key="item[0]" style="height: 0.423cm">
                                <td :colspan="g.totalLabel" class="e f9 bold">{{ item[0] }}</td>
                                <td :colspan="g.totalValue" class="s f10" :class="{ bold: index === 0 }">{{ item[1] }}</td>
                                <td :colspan="g.totalRightLabel" class="e f9 bold">{{ right[index][0] }}</td>
                                <td :colspan="g.totalRightValue" class="s f10">{{ right[index][1] }}</td>
                            </tr>
        
                            <!-- Certification -->
                            <tr style="height: 0.99cm">
                                <td colspan="17" class="s f7-5 wrap certify">
                                    I CERTIFY on my honor that the above is true and correct report of the hours of work performed, record or which was made daily at the time of arrival and at departure from office
                                </td>
                            </tr>
        
                            <!-- Signatures, QR code and the footer -->
                            <tr style="height: 0.884cm">
                                <td :colspan="g.qrLabel" rowspan="2" class="c f7-5 wrap">Use this QRCode to validate the authenticity of this document</td>
                                <td :colspan="g.signLabel" class="s f8-5">Employee's Signature</td>
                                <td :colspan="g.signature" class="c f9 bold esig">
                                    <img v-if="sheet.signature?.image_url" :src="sheet.signature.image_url" alt="Employee's e-signature" />
                                    <span v-if="sheet.signature?.status === 'changed'" class="changed">CHANGED SINCE THE EMPLOYEE SIGNED IT. Print the current sheet and sign again.</span>
                                </td>
                            </tr>
                            <tr style="height: 0.143cm">
                                <td :colspan="g.head" rowspan="2" class="s f8-5">Office Head Name/Signature</td>
                            </tr>
                            <tr style="height: 0.318cm">
                                <td :colspan="g.qr" :rowspan="isDouble ? 6 : 5" class="qr">
                                    <div v-if="sheet.signature" class="qr-code" v-html="qr"></div>
                                </td>
                            </tr>
                            <tr style="height: 0.99cm">
                                <td :colspan="g.head" class="c f11 bold head">{{ upper(sheet.head ?? '') }}</td>
                            </tr>
                            <tr style="height: 0.318cm">
                                <td :colspan="g.head" class="c f8 italic">Verified as to the prescribed office hours</td>
                            </tr>
                            <tr style="height: 0.386cm">
                                <td :colspan="g.footLabel" class="s f8-5">Generated Date:</td>
                                <td :colspan="g.footValue" class="s f8-5">{{ generated }}</td>
                            </tr>
                            <tr v-if="isDouble" style="height: 0.386cm">
                                <td :colspan="g.footLabel" class="s f8-5">Printed by:</td>
                                <td :colspan="g.footValue" class="s f7-5">{{ printedBy }}</td>
                            </tr>
                            <tr style="height: 0.423cm">
                                <td :colspan="g.footer" class="s f8-5">This is a computer generated document.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</template>

<style scoped>
.dtr-toolbar {
    position: sticky; top: 0; z-index: 10;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: 0.6rem 1rem; background: var(--color-surface); border-bottom: 1px solid var(--color-border);
    font: 13px/1.4 system-ui, sans-serif; color: var(--color-text);
}
.dtr-toolbar button {
    padding: 0.4rem 1rem; border-radius: 0.375rem; background: var(--color-primary); color: #fff; font-weight: 600;
}
.dtr { background: var(--color-surface-muted); padding: 0.3in 0; overflow-x: auto; }

/* The paper: A4 portrait with the template's 0.508 cm margins, always black on white. */
.page {
    position: relative; width: 21.001cm; min-height: 29.7cm; margin: 0 auto; padding: 0.508cm; box-sizing: border-box;
    background: #fff; color: #000; box-shadow: 0 1px 4px rgb(0 0 0 / 0.15);
}

/*
 * Two forms on one A4 portrait page, as in the "two DTR in one paper" report: each is
 * 10.15 cm wide (the same rows, in narrower columns) and unscaled, side by side
 * with a small gap, so the page is cut down the middle. The agency logo sits faded
 * behind both; the forms have no background of their own, so it shows through.
 */
.page.double { position: relative; padding: 0.508cm 0.25cm; }
.copies { position: relative; z-index: 1; }
.double .copies { display: flex; gap: 0.2cm; }
.double .copy { flex: none; }
.double .tpl { --form-scale: 1; margin-left: 0; }
.double .title { white-space: normal; font-size: 12pt; line-height: 1.2; }
.double .agency-text { white-space: normal; }
.double .schedule { font-size: 8pt; line-height: 1.05; }
.watermark {
    position: absolute; z-index: 0; top: 5.4cm; left: 50%; width: 16.5cm; height: auto;
    transform: translateX(-50%); opacity: 0.12; pointer-events: none;
}

/* The sheet, 20.211 cm of columns printed at 97%, from the template's first column on. */
.tpl {
    width: 20.211cm; margin-left: 0.041cm; border-collapse: collapse; table-layout: fixed;
    transform: scale(var(--form-scale, 0.97)); transform-origin: top left;
    font-family: Arial, Helvetica, sans-serif; color: #000;
}
.tpl td {
    border: 0.74pt solid #000; padding: 0 0.08cm; overflow: hidden; vertical-align: middle;
    line-height: 1.15; white-space: nowrap;
}
.tpl td.wrap { white-space: normal; }
/* Where the template draws only some of a cell's edges. */
.tpl td.nb { border: 0; }
.tpl td.bt { border: 0; border-top: 0.74pt solid #000; }
.tpl td.br { border: 0; border-right: 0.74pt solid #000; }
.tpl td.btr { border: 0; border-top: 0.74pt solid #000; border-right: 0.74pt solid #000; }
.tpl td.certify { border-top: 2.49pt solid #000; }

.c { text-align: center; }
.e { text-align: right; }
.s { text-align: left; }
.top { vertical-align: top !important; }
.bold { font-weight: 700; }
.italic { font-style: italic; }
.f6 { font-size: 6pt; }
.f7-5 { font-size: 7.5pt; }
.f8 { font-size: 8pt; }
.f8-5 { font-size: 8.5pt; }
.f9 { font-size: 9pt; }
.f10 { font-size: 10pt; }
.f11 { font-size: 11pt; }
.title { font: 700 13pt 'Microsoft Sans Serif', Arial, Helvetica, sans-serif; }
.name { vertical-align: middle !important; }
.head { vertical-align: bottom !important; }
/* The logo and the agency lines sit in the vertical middle of the header box (left-aligned, as on the template). */
/* Moved down about 10% of the box (0.15 cm); the form number below keeps its place. */
.logo { padding: 0 !important; text-align: center; vertical-align: middle; overflow: visible !important; }
.logo img { position: relative; top: 0.15cm; display: block; width: 1.711cm; height: 1.482cm; margin: 0 auto; object-fit: contain; }
.agency-text { vertical-align: middle !important; padding-top: 0.3cm !important; }
.agency-text p { margin: 0; line-height: 1.25; }
.remark { text-overflow: ellipsis; }
.qr { padding: 0 !important; text-align: center; vertical-align: middle; }
.qr-code { width: 2.3cm; height: 2.3cm; margin: 0 auto; }
.qr-code :deep(svg) { width: 100%; height: 100%; display: block; }
.esig { position: relative; padding: 0 !important; }
.esig img { display: block; max-height: 0.8cm; max-width: 100%; margin: 0 auto; }
.changed { display: block; color: #b00020; font-size: 6.5pt; font-weight: 700; white-space: normal; line-height: 1.1; }

@media print {
    @page { size: 21.001cm 29.7cm; margin: 0; }
    .dtr-toolbar { display: none; }
    .dtr { background: none; padding: 0; overflow: visible; }
    .page { box-shadow: none; margin: 0; }
}
</style>

<style>
/* The page body adds nothing around the sheet when printing. */
@media print {
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
}
</style>
