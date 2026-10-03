<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';

/**
 * HR-6b: the printable CS Form No. 6 (Application for Leave), filled from
 * the application and the actions recorded on its approval chain. Each
 * signatory line names who acted in IMIS and when. Print or "Save as PDF"
 * from the browser. No app layout: just the form.
 */
const props = defineProps({
    application: { type: Object, required: true },
    steps: { type: Array, required: true },
    person: { type: Object, required: true },
    types: { type: Array, required: true },
});

const v = (value) => (value === null || value === undefined ? '' : String(value));
const upper = (value) => v(value).toUpperCase();
const mdy = (value) => {
    if (!value) return '';
    const [y, m, d] = value.slice(0, 10).split('-');
    return `${m}/${d}/${y}`;
};
const box = (checked) => (checked ? '☒' : '☐');
const num = (value) => Number(value ?? 0).toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const peso = (value) => (value === null || value === undefined ? '' : Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

const app = computed(() => props.application);
const step = (role) => props.steps.find((candidate) => candidate.role === role) ?? null;
const supervisor = computed(() => step('supervisor'));
const hr = computed(() => step('hr'));
const head = computed(() => step('head'));
const acted = (s) => s && s.action !== 'pending';
const signedBy = (s) => (acted(s) ? `${upper(s.acted_by)}${s.acted_for ? ` (OIC for ${s.acted_for})` : ''}` : '');
const signedOn = (s) => (acted(s) ? `Acted in IMIS on ${mdy(s.acted_at)}` : '');

const detail = (value) => app.value.detail === value;

// 7.A: VL and SL as HR certified them, less this application where it draws on them.
const credit = (code) => {
    const earned = hr.value?.certified_credits?.[code];
    if (earned === undefined || !acted(hr.value)) return null;
    const draws = { VL: ['VL', 'FL'], SL: ['SL'] }[code].includes(app.value.type_code);
    const less = draws ? Number(app.value.days) : 0;
    return { earned, less, balance: Number(earned) - less };
};
const vl = computed(() => credit('VL'));
const sl = computed(() => credit('SL'));

const print = () => window.print();
</script>

<template>
    <Head :title="`CS Form No. 6 — ${application.reference_number}`" />

    <div class="cs6-toolbar">
        <p><strong>CS Form No. 6 (Revised 2020)</strong> — {{ application.reference_number }}, {{ application.status_label.toLowerCase() }}. Use your browser's print dialog; choose “Save as PDF” to keep a copy.</p>
        <button type="button" @click="print">Print</button>
    </div>

    <main class="cs6">
        <section class="sheet">
            <p class="form-no"><em>Civil Service Form No. 6</em><br /><em>Revised 2020</em></p>
            <h1>APPLICATION FOR LEAVE</h1>
            <p class="ref">Reference No. {{ application.reference_number }}</p>

            <table>
                <tr>
                    <td class="w-1/3"><span class="label">1. OFFICE/DEPARTMENT</span><div class="value">{{ upper(person.office) }}</div></td>
                    <td colspan="2">
                        <span class="label">2. NAME:</span>
                        <div class="names">
                            <span><small>(Last)</small>{{ upper(person.surname) }}</span>
                            <span><small>(First)</small>{{ upper(person.first_name) }}</span>
                            <span><small>(Middle)</small>{{ upper(person.middle_name) }}</span>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td><span class="label">3. DATE OF FILING</span> {{ mdy(application.submitted_at) }}</td>
                    <td><span class="label">4. POSITION</span> {{ upper(person.position) }}</td>
                    <td><span class="label">5. SALARY</span> {{ peso(person.monthly_salary) }}</td>
                </tr>
                <tr><th colspan="3" class="section">6. DETAILS OF APPLICATION</th></tr>
                <tr>
                    <td class="top">
                        <span class="label">6.A TYPE OF LEAVE TO BE AVAILED OF</span>
                        <p v-for="type in types" :key="type.id" class="check">{{ box(type.id === application.leave_type_id) }} {{ type.name }}</p>
                    </td>
                    <td colspan="2" class="top">
                        <span class="label">6.B DETAILS OF LEAVE</span>
                        <p class="sub">In case of Vacation/Special Privilege Leave:</p>
                        <p class="check">{{ box(detail('within_ph')) }} Within the Philippines</p>
                        <p class="check">{{ box(detail('abroad')) }} Abroad (Specify) <u>{{ detail('abroad') ? application.detail_text : '' }}</u></p>
                        <p class="sub">In case of Sick Leave:</p>
                        <p class="check">{{ box(detail('in_hospital')) }} In Hospital (Specify Illness) <u>{{ detail('in_hospital') ? application.detail_text : '' }}</u></p>
                        <p class="check">{{ box(detail('out_patient')) }} Out Patient (Specify Illness) <u>{{ detail('out_patient') ? application.detail_text : '' }}</u></p>
                        <p class="sub">In case of Special Leave Benefits for Women:</p>
                        <p class="check">(Specify Illness) <u>{{ detail('special_illness') ? application.detail_text : '' }}</u></p>
                        <p class="sub">In case of Study Leave:</p>
                        <p class="check">{{ box(detail('masters_degree')) }} Completion of Master's Degree</p>
                        <p class="check">{{ box(detail('bar_board_review')) }} BAR/Board Examination Review</p>
                        <p class="sub">Other purpose:</p>
                        <p class="check">{{ box(false) }} Monetization of Leave Credits</p>
                        <p class="check">{{ box(false) }} Terminal Leave</p>
                    </td>
                </tr>
                <tr>
                    <td class="top">
                        <span class="label">6.C NUMBER OF WORKING DAYS APPLIED FOR</span>
                        <div class="value">{{ num(application.days) }}<template v-if="application.is_half_day"> ({{ application.half_day_period }})</template></div>
                        <span class="label">INCLUSIVE DATES</span>
                        <div class="value">{{ mdy(application.date_from) }}<template v-if="application.date_to !== application.date_from"> – {{ mdy(application.date_to) }}</template></div>
                    </td>
                    <td colspan="2" class="top">
                        <span class="label">6.D COMMUTATION</span>
                        <p class="check">{{ box(!application.commutation_requested) }} Not Requested</p>
                        <p class="check">{{ box(application.commutation_requested) }} Requested</p>
                        <div class="signature">
                            <div class="line">{{ upper([person.first_name, person.surname].filter(Boolean).join(' ')) }}</div>
                            <small>(Signature of Applicant) · filed in IMIS on {{ mdy(application.submitted_at) }}</small>
                        </div>
                    </td>
                </tr>
                <tr><th colspan="3" class="section">7. DETAILS OF ACTION ON APPLICATION</th></tr>
                <tr>
                    <td class="top">
                        <span class="label">7.A CERTIFICATION OF LEAVE CREDITS</span>
                        <p>As of <u>{{ acted(hr) ? mdy(hr.acted_at) : '' }}</u></p>
                        <table class="credits">
                            <tr><th></th><th>Vacation Leave</th><th>Sick Leave</th></tr>
                            <tr><td>Total Earned</td><td>{{ vl ? num(vl.earned) : '' }}</td><td>{{ sl ? num(sl.earned) : '' }}</td></tr>
                            <tr><td>Less this application</td><td>{{ vl ? num(vl.less) : '' }}</td><td>{{ sl ? num(sl.less) : '' }}</td></tr>
                            <tr><td>Balance</td><td>{{ vl ? num(vl.balance) : '' }}</td><td>{{ sl ? num(sl.balance) : '' }}</td></tr>
                        </table>
                        <div class="signature">
                            <div class="line">{{ signedBy(hr) }}</div>
                            <small>(Authorized Officer) {{ signedOn(hr) }}</small>
                        </div>
                    </td>
                    <td colspan="2" class="top">
                        <span class="label">7.B RECOMMENDATION</span>
                        <p class="check">{{ box(supervisor?.action === 'recommended') }} For approval</p>
                        <p class="check">{{ box(supervisor?.action === 'not_recommended') }} For disapproval due to <u>{{ supervisor?.action === 'not_recommended' ? supervisor.remarks : '' }}</u></p>
                        <p v-if="!supervisor" class="note">Not required for this type of leave.</p>
                        <div class="signature">
                            <div class="line">{{ signedBy(supervisor) }}</div>
                            <small>(Authorized Officer) {{ signedOn(supervisor) }}</small>
                        </div>
                    </td>
                </tr>
                <tr>
                    <td class="top">
                        <span class="label">7.C APPROVED FOR:</span>
                        <p><u>{{ application.status === 'approved' ? num(application.days_with_pay) : '' }}</u> days with pay</p>
                        <p><u>{{ application.status === 'approved' ? num(application.days_without_pay) : '' }}</u> days without pay</p>
                        <p>___ others (Specify)</p>
                    </td>
                    <td colspan="2" class="top">
                        <span class="label">7.D DISAPPROVED DUE TO:</span>
                        <p><u>{{ head?.action === 'disapproved' || hr?.action === 'insufficient_credits' ? (head?.action === 'disapproved' ? head.remarks : hr.remarks) : '' }}</u></p>
                    </td>
                </tr>
                <tr>
                    <td colspan="3" class="center">
                        <div class="signature wide">
                            <div class="line">{{ signedBy(head) }}</div>
                            <small>(Authorized Official) {{ signedOn(head) }}</small>
                        </div>
                    </td>
                </tr>
            </table>
        </section>
    </main>
</template>

<style scoped>
.cs6-toolbar {
    position: sticky; top: 0; z-index: 10;
    display: flex; align-items: center; justify-content: space-between; gap: 1rem;
    padding: 0.6rem 1rem; background: var(--color-surface); border-bottom: 1px solid var(--color-border);
    font: 13px/1.4 system-ui, sans-serif; color: var(--color-text);
}
.cs6-toolbar button { padding: 0.4rem 1rem; border-radius: 0.375rem; background: var(--color-primary); color: #fff; font-weight: 600; }
.cs6 { background: var(--color-surface-muted); padding: 0.3in 0; }
.sheet {
    width: 8.5in; min-height: 13in; margin: 0 auto; padding: 0.4in; box-sizing: border-box;
    background: #fff; color: #000; font: 8.5pt/1.3 Arial, Helvetica, sans-serif; box-shadow: 0 1px 4px rgb(0 0 0 / 0.15);
}
.form-no { font-size: 8pt; margin: 0; }
h1 { text-align: center; font-size: 14pt; margin: 0.1in 0 0; letter-spacing: 0.05em; }
.ref { text-align: center; font-size: 8pt; margin: 0 0 0.1in; }
table { width: 100%; border-collapse: collapse; }
td, th { border: 1px solid #000; padding: 4px 6px; vertical-align: top; }
.top { vertical-align: top; }
.center { text-align: center; }
.w-1\/3 { width: 33%; }
.section { background: #e5e5e5; text-align: center; font-weight: 700; }
.label { font-weight: 700; display: block; }
.value { font-weight: 700; margin: 2px 0 6px; }
.names { display: flex; gap: 0.2in; font-weight: 700; }
.names span { flex: 1; }
.names small { display: block; font-weight: 400; font-size: 7pt; }
.check { margin: 1px 0; }
.sub { font-style: italic; margin: 4px 0 1px; }
.note { font-style: italic; font-size: 7.5pt; }
u { text-decoration: underline; min-width: 1in; display: inline-block; }
.credits { margin: 4px 0; }
.credits td, .credits th { text-align: center; font-size: 8pt; padding: 2px 4px; }
.signature { margin-top: 0.3in; text-align: center; }
.signature.wide { width: 3.5in; margin: 0.3in auto 0; }
.signature .line { border-bottom: 1px solid #000; min-height: 1.2em; font-weight: 700; }
.signature small { font-size: 7pt; }

@media print {
    @page { size: 8.5in 13in; margin: 0; }
    .cs6-toolbar { display: none; }
    .cs6 { background: none; padding: 0; }
    .sheet { box-shadow: none; margin: 0; }
}
</style>
