<script setup>
import { Head } from '@inertiajs/vue3';

/**
 * HR-5: the printable Service Record, in the standard CSC layout, on
 * long bond paper (8.5 × 13 in, landscape). Print or "Save as PDF" from
 * the browser. No app layout: just the form.
 *
 * `certified` — HR's copy carries the certification and the certifying
 * officer (System Settings → Human Resources); the employee's own
 * printout is marked as a copy for reference.
 */
const props = defineProps({
    person: { type: Object, required: true },
    lines: { type: Array, required: true },
    certification: { type: Object, required: true },
    certified: { type: Boolean, required: true },
});

const v = (value) => (value === null || value === undefined ? '' : String(value));
const upper = (value) => v(value).toUpperCase();

/** mm/dd/yyyy, as service records are usually written. */
const mdy = (value) => {
    if (!value) return '';
    const [y, m, d] = value.slice(0, 10).split('-');
    return `${m}/${d}/${y}`;
};

const money = (value) => (value === null || value === undefined ? '' : Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

// The form leaves room for writing in; pad short records to a full sheet.
const MIN_ROWS = 14;
const rows = [...props.lines, ...Array(Math.max(0, MIN_ROWS - props.lines.length)).fill(null)];

const today = new Date().toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' });
const print = () => window.print();
</script>

<template>
    <Head title="Service Record — print" />

    <div class="sr-toolbar">
        <p>
            <strong>Service Record</strong> — {{ certified ? 'certified copy' : 'copy for reference (not certified)' }}.
            Use your browser's print dialog; choose “Save as PDF” to keep a copy. Set the paper to 8.5 × 13 in, landscape.
        </p>
        <button type="button" @click="print">Print</button>
    </div>

    <main class="sr">
        <section class="sheet">
            <header class="agency">
                <p>Republic of the Philippines</p>
                <p class="agency-name">{{ upper(certification.organization) }}</p>
            </header>

            <h1>SERVICE RECORD</h1>
            <p v-if="!certified" class="watermark">COPY FOR REFERENCE — NOT CERTIFIED</p>

            <table class="person">
                <tr>
                    <td class="label">NAME:</td>
                    <td class="value">{{ upper(person.surname) }}</td>
                    <td class="value">{{ upper(person.first_name) }}</td>
                    <td class="value">{{ upper(person.middle_name) }}</td>
                    <td class="hint" rowspan="2">(If married woman, give also full maiden name)</td>
                </tr>
                <tr class="caption">
                    <td></td><td>(Surname)</td><td>(Given name)</td><td>(Middle name)</td>
                </tr>
                <tr>
                    <td class="label">BIRTH:</td>
                    <td class="value">{{ mdy(person.date_of_birth) }}</td>
                    <td class="value" colspan="2">{{ upper(person.place_of_birth) }}</td>
                    <td class="hint" rowspan="2">(Data herein should be checked from birth or baptismal certificate or some other reliable documents)</td>
                </tr>
                <tr class="caption">
                    <td></td><td>(Date)</td><td colspan="2">(Place)</td>
                </tr>
            </table>

            <p class="statement">
                This is to certify that the employee named herein above actually rendered services in this Office as shown by the service record
                below, each line of which is supported by appointment and other papers actually issued by this Office and approved by the
                authorities concerned.
            </p>

            <table class="lines">
                <thead>
                    <tr>
                        <th colspan="2">SERVICE<br /><span>(Inclusive Dates)</span></th>
                        <th colspan="3">RECORD OF APPOINTMENT</th>
                        <th colspan="2">OFFICE ENTITY / DIVISION</th>
                        <th rowspan="2">L/V ABS<br />W/O PAY</th>
                        <th colspan="2">SEPARATION</th>
                    </tr>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Designation</th>
                        <th>Status</th>
                        <th>Salary<br />(Annual)</th>
                        <th>Station / Place of Assignment</th>
                        <th>Branch</th>
                        <th>Date</th>
                        <th>Cause</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(line, index) in rows" :key="index">
                        <template v-if="line">
                            <td class="center">{{ mdy(line.date_from) }}</td>
                            <td class="center">{{ line.date_to ? mdy(line.date_to) : 'Present' }}</td>
                            <td>{{ line.designation }}</td>
                            <td class="center">{{ line.status }}</td>
                            <td class="right">{{ money(line.annual_salary) }}</td>
                            <td>{{ line.station }}</td>
                            <td class="center">{{ line.branch }}</td>
                            <td class="center">{{ line.leave_without_pay ?? '' }}</td>
                            <td class="center">{{ mdy(line.separation_date) }}</td>
                            <td>{{ line.separation_cause ?? '' }}</td>
                        </template>
                        <template v-else>
                            <td v-for="cell in 10" :key="cell">&nbsp;</td>
                        </template>
                    </tr>
                </tbody>
            </table>
            <p class="end">*** Nothing follows ***</p>

            <footer class="sign">
                <div>
                    <p class="line">{{ today }}</p>
                    <p class="caption">Date</p>
                </div>
                <div>
                    <p class="certified">CERTIFIED CORRECT:</p>
                    <p class="line name">{{ certified ? upper(certification.name) : '' }}&nbsp;</p>
                    <p class="caption">{{ certified ? v(certification.position) : '' }}&nbsp;</p>
                </div>
            </footer>
        </section>
    </main>
</template>

<style scoped>
.sr-toolbar {
    position: sticky; top: 0; z-index: 10;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: 0.6rem 1rem; background: var(--color-surface); border-bottom: 1px solid var(--color-border);
    font: 13px/1.4 system-ui, sans-serif; color: var(--color-text);
}
.sr-toolbar button {
    padding: 0.4rem 1rem; border-radius: 0.375rem; background: var(--color-primary); color: #fff; font-weight: 600;
}
.sr { background: var(--color-surface-muted); padding: 0.3in 0; }
.sheet {
    width: 13in; min-height: 8.5in; margin: 0 auto; padding: 0.45in 0.5in; box-sizing: border-box;
    background: #fff; color: #000; font: 10pt/1.3 Arial, Helvetica, sans-serif; box-shadow: 0 1px 4px rgb(0 0 0 / 0.15);
    position: relative;
}
.agency { text-align: center; }
.agency p { margin: 0; }
.agency-name { font-weight: 700; font-size: 11pt; }
h1 { text-align: center; font-size: 15pt; letter-spacing: 0.15em; margin: 0.15in 0 0.1in; }
.watermark { text-align: center; font-weight: 700; color: var(--color-danger); margin: 0 0 0.08in; letter-spacing: 0.05em; }
table { border-collapse: collapse; width: 100%; }
.person td { padding: 1px 6px; vertical-align: bottom; }
.person .label { width: 0.8in; font-weight: 700; }
.person .value { border-bottom: 1px solid #000; font-weight: 700; text-align: center; }
.person .hint { font-size: 8pt; font-style: italic; width: 2.6in; vertical-align: middle; }
.person .caption td { font-size: 8pt; text-align: center; padding-top: 0; }
.statement { margin: 0.12in 0; text-align: justify; text-indent: 0.4in; }
.lines th, .lines td { border: 1px solid #000; padding: 2px 4px; font-size: 9pt; }
.lines th { font-weight: 700; text-align: center; vertical-align: middle; }
.lines th span { font-weight: 400; font-size: 8pt; }
.lines td { height: 0.24in; vertical-align: top; }
.center { text-align: center; }
.right { text-align: right; white-space: nowrap; }
.end { text-align: center; font-style: italic; font-size: 9pt; margin: 0.05in 0; }
.sign { display: flex; justify-content: space-between; margin-top: 0.35in; }
.sign > div { width: 3.6in; text-align: center; }
.sign .certified { text-align: left; font-weight: 700; margin: 0 0 0.35in; }
.sign .line { border-bottom: 1px solid #000; margin: 0; padding-bottom: 2px; }
.sign .name { font-weight: 700; }
.sign .caption { font-size: 9pt; margin: 2px 0 0; }

@media print {
    @page { size: 13in 8.5in; margin: 0; }
    .sr-toolbar { display: none; }
    .sr { background: none; padding: 0; }
    .sheet { box-shadow: none; margin: 0; }
}
</style>
