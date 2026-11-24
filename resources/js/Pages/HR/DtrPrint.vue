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
        </p>
        <button type="button" @click="print">Print</button>
    </div>

    <main class="dtr">
        <section class="page">
            <table class="tpl">
                <colgroup>
                    <col style="width: 1.108cm" />
                    <col style="width: 1.695cm" />
                    <col style="width: 0.432cm" />
                    <col style="width: 1.259cm" />
                    <col style="width: 1.259cm" />
                    <col style="width: 0.171cm" />
                    <col style="width: 0.28cm" />
                    <col style="width: 0.52cm" />
                    <col style="width: 0.716cm" />
                    <col style="width: 0.236cm" />
                    <col style="width: 0.215cm" />
                    <col style="width: 1.695cm" />
                    <col style="width: 0.716cm" />
                    <col style="width: 3.565cm" />
                    <col style="width: 3.935cm" />
                    <col style="width: 0.432cm" />
                    <col style="width: 1.977cm" />
                </colgroup>
                <tbody>
                    <!-- Title, logo and agency -->
                    <tr style="height: 0.529cm">
                        <td colspan="5" rowspan="4" class="c title">DAILY TIME RECORD</td>
                        <td colspan="5" rowspan="3" class="bt logo">
                            <img v-if="logo" :src="logo" alt="" />
                        </td>
                        <td colspan="7" rowspan="3" class="btr s agency-text">
                            <p class="f8">Republic of the Philippines</p>
                            <p class="f9 bold">CIVIL SERVICE COMMISSION</p>
                            <p class="f6">CSC Building, IBP Road, Constitution Hills, Diliman, 1126 Quezon City</p>
                        </td>
                    </tr>
                    <tr style="height: 0.492cm"></tr>
                    <tr style="height: 0.46cm"></tr>
                    <tr style="height: 0.492cm">
                        <td colspan="5" class="nb"></td>
                        <td colspan="7" class="br e f8 bold">Civil Service Form No. 48</td>
                    </tr>

                    <!-- Employee, time reference, period and the day counts -->
                    <tr style="height: 0.704cm">
                        <td colspan="5" class="e f8-5">Employee's Name</td>
                        <td colspan="12" class="s f11 bold name">{{ upper(employee.name) }}</td>
                    </tr>
                    <tr style="height: 0.598cm">
                        <td colspan="5" rowspan="2" class="e f9">Time Reference</td>
                        <td colspan="9" rowspan="2" class="s f10 wrap">{{ sheet.schedule ?? 'No work schedule assigned' }}</td>
                        <td colspan="2" class="e f9">Regular Days</td>
                        <td class="c f10">{{ totals.regular_days }}</td>
                    </tr>
                    <tr style="height: 0.492cm">
                        <td colspan="2" class="e f9">Weekends</td>
                        <td class="c f10">{{ totals.weekend_days }}</td>
                    </tr>
                    <tr style="height: 0.566cm">
                        <td colspan="5" class="e f9">For the period of</td>
                        <td colspan="9" class="s f10 bold">{{ sheet.period }}</td>
                        <td colspan="2" class="e f9">Holiday</td>
                        <td class="c f10">{{ totals.holiday_days }}</td>
                    </tr>

                    <!-- Column headings -->
                    <tr style="height: 0.423cm">
                        <td rowspan="2" class="c f10 bold">Day</td>
                        <td colspan="3" class="c f10 bold">MORNING</td>
                        <td colspan="7" class="c f10 bold">AFTERNOON</td>
                        <td rowspan="2" class="c f8 bold wrap">Worked Hrs.</td>
                        <td colspan="5" rowspan="2" class="c f10 bold">Remarks</td>
                    </tr>
                    <tr style="height: 0.423cm">
                        <td class="c f8-5">Time-in</td>
                        <td colspan="2" class="c f8-5">Break-out</td>
                        <td colspan="3" class="c f8-5">Break-in</td>
                        <td colspan="4" class="c f8-5">Time-out</td>
                    </tr>

                    <!-- Days 1 to 31 -->
                    <tr v-for="item in days" :key="item.day" style="height: 0.423cm">
                        <td class="c f9">{{ item.day }}</td>
                        <td class="c f9">{{ clock(item.row?.in) }}</td>
                        <td colspan="2" class="c f9">{{ clock(item.row?.break_out) }}</td>
                        <td colspan="3" class="c f9">{{ clock(item.row?.break_in) }}</td>
                        <td colspan="4" class="c f9">{{ clock(item.row?.out) }}</td>
                        <td class="c f9">{{ worked(item.row?.minutes) }}</td>
                        <td colspan="5" class="c f7-5 remark">{{ item.row?.remark ?? '' }}</td>
                    </tr>

                    <!-- Totals -->
                    <tr v-for="(item, index) in left" :key="item[0]" style="height: 0.423cm">
                        <td colspan="9" class="e f9 bold">{{ item[0] }}</td>
                        <td colspan="4" class="s f10" :class="{ bold: index === 0 }">{{ item[1] }}</td>
                        <td colspan="2" class="e f9 bold">{{ right[index][0] }}</td>
                        <td colspan="2" class="s f10">{{ right[index][1] }}</td>
                    </tr>

                    <!-- Certification -->
                    <tr style="height: 0.99cm">
                        <td colspan="17" class="s f7-5 wrap certify">
                            I CERTIFY on my honor that the above is true and correct report of the hours of work performed, record or which was made daily at the time of arrival and at departure from office
                        </td>
                    </tr>

                    <!-- Signatures, QR code and the footer -->
                    <tr style="height: 0.884cm">
                        <td colspan="3" rowspan="2" class="c f7-5 wrap">Use this QRCode to validate the authenticity of this document</td>
                        <td colspan="5" class="s f8-5">Employee's Signature</td>
                        <td colspan="9" class="c f9 bold esig">
                            <img v-if="sheet.signature?.image_url" :src="sheet.signature.image_url" alt="Employee's e-signature" />
                            <span v-if="sheet.signature?.status === 'changed'" class="changed">CHANGED SINCE THE EMPLOYEE SIGNED IT. Print the current sheet and sign again.</span>
                        </td>
                    </tr>
                    <tr style="height: 0.143cm">
                        <td colspan="14" rowspan="2" class="s f8-5">Office Head Name/Signature</td>
                    </tr>
                    <tr style="height: 0.318cm">
                        <td colspan="3" rowspan="5" class="qr">
                            <div v-if="sheet.signature" class="qr-code" v-html="qr"></div>
                        </td>
                    </tr>
                    <tr style="height: 0.99cm">
                        <td colspan="14" class="c f11 bold head">{{ upper(sheet.head ?? '') }}</td>
                    </tr>
                    <tr style="height: 0.318cm">
                        <td colspan="14" class="c f8 italic">Verified as to the prescribed office hours</td>
                    </tr>
                    <tr style="height: 0.386cm">
                        <td colspan="3" class="s f8-5">Generated Date:</td>
                        <td colspan="11" class="s f8-5">{{ generated }}</td>
                    </tr>
                    <tr style="height: 0.423cm">
                        <td colspan="14" class="s f8-5">This is a computer generated document.</td>
                    </tr>
                </tbody>
            </table>
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
    width: 21.001cm; min-height: 29.7cm; margin: 0 auto; padding: 0.508cm; box-sizing: border-box;
    background: #fff; color: #000; box-shadow: 0 1px 4px rgb(0 0 0 / 0.15);
}

/* The sheet, 20.211 cm of columns printed at 97%, from the template's first column on. */
.tpl {
    width: 20.211cm; margin-left: 0.041cm; border-collapse: collapse; table-layout: fixed;
    transform: scale(0.97); transform-origin: top left;
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
