<script setup>
import { computed, ref, watch } from 'vue';
import { Head, usePage } from '@inertiajs/vue3';

/**
 * HR-6b: CS Form No. 6, Revised 2020 (Application for Leave), laid out
 * the way CSC's own leave system prints it (Control Number, ANNEX A, the
 * five-row 7.A table, "Printed by"), drawn on a Folio sheet (8.5 × 13 in): the form
 * fills the page up to a quarter-inch edge, the same as the Excel copy.
 * Every entry comes from LeaveForm (backend), which the Excel copy
 * (form.xlsx, CSC's template) uses too. Print, or "Save as PDF", from
 * the browser. No app layout: just the form.
 *
 * Spacing is one system: --edge around the page, --pad inside every
 * box, and every line of a box at the same pitch (--line) from its top,
 * as CSC's system prints it; signature blocks sit at the foot of their
 * box with room above to sign.
 */
const props = defineProps({
    form: { type: Object, required: true },
    types: { type: Array, required: true },
});

const logo = computed(() => usePage().props.branding?.logo);
const box = (checked) => (checked ? '☑' : '☐');
const detail = (key) => props.form.details[key] ?? { checked: false, text: '' };
const signers = computed(() => ({
    certifier: props.form.certifier,
    recommender: props.form.recommender,
    approver: props.form.approver,
}));
const position = (signer) => (signer ? `${signer.position}${signer.acting_for ? ` (OIC, for ${signer.acting_for})` : ''}` : '');
const workbookUrl = computed(() => `${window.location.pathname}.xlsx`);

const print = () => window.print();

// Manual nudging of each signature image (screen and print): up/down in inches and size in percent.
// Kept in this browser for every form, since a printer's offset is the same each time.
const STORAGE_KEY = 'cs6-signature-adjust';
const SLOTS = [
    { key: 'applicant', label: 'Applicant (6.D)' },
    { key: 'certifier', label: 'HR certifier (7.A)' },
    { key: 'recommender', label: 'Supervisor (7.B)' },
    { key: 'approver', label: 'Head of Agency (7.C)' },
];
const blank = () => Object.fromEntries(SLOTS.map((slot) => [slot.key, { dx: 0, dy: 0, scale: 100 }]));
const adjust = ref(blank());
try {
    const saved = JSON.parse(window.localStorage.getItem(STORAGE_KEY) ?? 'null');
    if (saved) {
        for (const slot of SLOTS) {
            if (saved[slot.key]) adjust.value[slot.key] = { dx: Number(saved[slot.key].dx) || 0, dy: Number(saved[slot.key].dy) || 0, scale: Number(saved[slot.key].scale) || 100 };
        }
    }
} catch {
    /* private window: keep the defaults */
}
watch(adjust, (value) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, JSON.stringify(value));
    } catch {
        /* not saved */
    }
}, { deep: true });
const nudge = (key, field, by) => {
    const entry = adjust.value[key];
    if (field === 'dx' || field === 'dy') entry[field] = Math.round((entry[field] + by) * 100) / 100;
    else entry.scale = Math.min(200, Math.max(40, entry.scale + by));
};
const resetAdjust = () => {
    adjust.value = blank();
};
const hasSignature = (key) => (key === 'applicant' ? !!props.form.applicant_signature : !!signers.value[key]?.signature);
const imageStyle = (key) => ({ '--dx': `${adjust.value[key].dx}in`, '--dy': `${adjust.value[key].dy}in`, '--scale': adjust.value[key].scale / 100 });
</script>

<template>
    <Head :title="`CS Form No. 6 — ${form.reference_number}`" />

    <div class="cs6-toolbar">
        <p>
            <strong>CS Form No. 6 (Revised 2020)</strong> — {{ form.reference_number }}, {{ form.status_label.toLowerCase() }}.
            Prints on one Folio sheet (8.5 × 13 in). In the print dialog keep Scale at 100% (“Default”) and Margins at “Default”; choose “Save as PDF” to keep a copy.
        </p>
        <div class="cs6-actions">
            <a :href="workbookUrl" class="cs6-secondary">Download Excel</a>
            <button type="button" @click="print">Print / Save as PDF</button>
        </div>
    </div>

    <div v-if="SLOTS.some((slot) => hasSignature(slot.key))" class="cs6-adjust">
        <details>
            <summary>Adjust signatures</summary>
            <p>Move a signature left, right, up or down, or make it larger or smaller. Saved in this browser and applied to every form you print here.</p>
            <div class="adjust-grid">
                <template v-for="slot in SLOTS" :key="slot.key">
                    <div v-if="hasSignature(slot.key)" class="adjust-row">
                        <span class="adjust-name">{{ slot.label }}</span>
                        <span class="adjust-group">
                            Left / right
                            <button type="button" aria-label="Move left" @click="nudge(slot.key, 'dx', -0.02)">◀</button>
                            <button type="button" aria-label="Move right" @click="nudge(slot.key, 'dx', 0.02)">▶</button>
                            <output>{{ adjust[slot.key].dx > 0 ? '+' : '' }}{{ adjust[slot.key].dx.toFixed(2) }} in</output>
                        </span>
                        <span class="adjust-group">
                            Up / down
                            <button type="button" aria-label="Move up" @click="nudge(slot.key, 'dy', -0.02)">▲</button>
                            <button type="button" aria-label="Move down" @click="nudge(slot.key, 'dy', 0.02)">▼</button>
                            <output>{{ adjust[slot.key].dy > 0 ? '+' : '' }}{{ adjust[slot.key].dy.toFixed(2) }} in</output>
                        </span>
                        <span class="adjust-group">
                            Size
                            <button type="button" aria-label="Smaller" @click="nudge(slot.key, 'scale', -10)">−</button>
                            <button type="button" aria-label="Larger" @click="nudge(slot.key, 'scale', 10)">+</button>
                            <output>{{ adjust[slot.key].scale }}%</output>
                        </span>
                    </div>
                </template>
            </div>
            <button type="button" class="cs6-secondary adjust-reset" @click="resetAdjust">Reset</button>
        </details>
    </div>

    <main class="cs6">
        <section class="sheet">
            <header class="masthead">
                <div class="masthead-top">
                    <p class="form-no">CSC Form No. 6<br />Revised 2020</p>
                    <p class="annex">ANNEX A</p>
                </div>
                <div class="masthead-main">
                    <div class="agency">
                        <img v-if="logo" :src="logo" alt="" class="logo" />
                        <div>
                            <p>Republic of the Philippines</p>
                            <p class="agency-name">{{ form.agency.name }}</p>
                            <p>{{ form.agency.address }}</p>
                        </div>
                    </div>
                    <div class="stamp">Stamp of Date of Receipt</div>
                </div>
                <div class="title-row">
                    <h1>APPLICATION FOR LEAVE</h1>
                    <div class="control">
                        <p class="control-number">{{ form.reference_number }}</p>
                        <p>Control Number</p>
                    </div>
                </div>
            </header>

            <div class="frame">
                <div class="row person">
                    <div class="cell">
                        <p>1.&nbsp; OFFICE/DEPARTMENT</p>
                        <p class="value office">{{ form.office }}</p>
                    </div>
                    <div class="cell">
                        <div class="name-grid"><span>2.&nbsp; NAME :</span><span>(Last)</span><span>(First)</span><span>(Middle)</span></div>
                        <div class="name-grid value"><span></span><span>{{ form.name.last }}</span><span>{{ form.name.first }}</span><span>{{ form.name.middle }}</span></div>
                    </div>
                </div>
                <div class="row filing">
                    <p>3.&nbsp; DATE OF FILING <u class="w-date">{{ form.filed_on }}</u></p>
                    <p>4.&nbsp; POSITION <u class="w-position">{{ form.position }}</u></p>
                    <p>5.&nbsp; SALARY <u class="w-salary">{{ form.salary }}</u></p>
                </div>

                <h2>6.&nbsp; DETAILS OF APPLICATION</h2>
                <div class="row split grow-6ab">
                    <div class="cell types">
                        <p class="head">6.A&nbsp; TYPE OF LEAVE TO BE AVAILED OF</p>
                        <div class="lines">
                            <p v-for="type in types" :key="type.code" class="check">
                                <span class="box">{{ box(form.type === type.code) }}</span>
                                <span><strong>{{ type.name }}</strong> <small>({{ type.basis }})</small></span>
                            </p>
                            <div class="foot-of-box">
                                <p class="sub strong">Others</p>
                                <p class="check"><u class="grow others">{{ form.others }}</u></p>
                            </div>
                        </div>
                    </div>
                    <div class="cell">
                        <p class="head">6.B&nbsp; DETAILS OF LEAVE</p>
                        <div class="lines">
                            <p class="sub">In case of Vacation/Special Privilege Leave:</p>
                            <p class="check"><span class="box">{{ box(detail('within_ph').checked) }}</span>Within the Philippines <u class="grow">{{ detail('within_ph').text }}</u></p>
                            <p class="check"><span class="box">{{ box(detail('abroad').checked) }}</span>Abroad (Specify) <u class="grow">{{ detail('abroad').text }}</u></p>
                            <p class="sub">In case of Sick Leave:</p>
                            <p class="check"><span class="box">{{ box(detail('in_hospital').checked) }}</span>In Hospital (Specify Illness) <u class="grow">{{ detail('in_hospital').text }}</u></p>
                            <p class="check"><span class="box">{{ box(detail('out_patient').checked) }}</span>Out Patient (Specify Illness) <u class="grow">{{ detail('out_patient').text }}</u></p>
                            <p class="check"><u class="grow"></u></p>
                            <p class="sub">In case of Special Leave Benefits for Women:</p>
                            <p class="check">(Specify Illness) <u class="grow">{{ detail('special_illness').text }}</u></p>
                            <p class="check"><u class="grow"></u></p>
                            <p class="sub">In case of Study Leave:</p>
                            <p class="check"><span class="box">{{ box(detail('masters_degree').checked) }}</span>Completion of Master's Degree</p>
                            <p class="check"><span class="box">{{ box(detail('bar_board_review').checked) }}</span>BAR/Board Examination Review</p>
                            <p class="sub">Other purpose:</p>
                            <p class="check"><span class="box">{{ box(detail('monetization').checked) }}</span>Monetization of Leave Credits</p>
                            <p class="check"><span class="box">{{ box(detail('terminal').checked) }}</span>Terminal Leave</p>
                        </div>
                    </div>
                </div>
                <div class="row split grow-6cd">
                    <div class="cell">
                        <p class="head">6.C&nbsp; NUMBER OF WORKING DAYS APPLIED FOR</p>
                        <div class="lines">
                            <p class="check"><u class="grow">{{ form.days }}</u></p>
                            <p class="check spaced">INCLUSIVE DATES</p>
                            <p class="check"><u class="grow">{{ form.inclusive_dates }}<template v-if="form.is_emergency"> (emergency, filed upon return)</template></u></p>
                        </div>
                    </div>
                    <div class="cell">
                        <p class="head">6.D&nbsp; COMMUTATION</p>
                        <div class="lines">
                            <p class="check"><span class="box">{{ box(!form.commutation_requested) }}</span>Not Requested</p>
                            <p class="check"><span class="box">{{ box(form.commutation_requested) }}</span>Requested</p>
                            <div class="signature">
                                <img v-if="form.applicant_signature" :src="form.applicant_signature" :style="imageStyle('applicant')" alt="" class="esign" />
                                <p class="name"></p>
                                <p class="caption">(Signature of Applicant)</p>
                            </div>
                        </div>
                    </div>
                </div>

                <h2>7.&nbsp; DETAILS OF ACTION ON APPLICATION</h2>
                <div class="row split grow-7ab">
                    <div class="cell">
                        <p class="head">7.A&nbsp; CERTIFICATION OF LEAVE CREDITS</p>
                        <div class="lines">
                            <p class="check center-line">As of <u class="w-date">{{ form.credits?.as_of }}</u></p>
                            <table class="credits">
                                <thead><tr><th></th><th>Vacation Leave</th><th>Sick Leave</th></tr></thead>
                                <tbody>
                                    <tr><th>Total Earned</th><td>{{ form.credits?.rows.VL.earned }}</td><td>{{ form.credits?.rows.SL.earned }}</td></tr>
                                    <tr><th>Total Enjoyed + Tardy</th><td>{{ form.credits?.rows.VL.enjoyed }}</td><td>{{ form.credits?.rows.SL.enjoyed }}</td></tr>
                                    <tr class="italic"><th>Balance</th><td>{{ form.credits?.rows.VL.balance }}</td><td>{{ form.credits?.rows.SL.balance }}</td></tr>
                                    <tr class="italic"><th>Less this Application</th><td>{{ form.credits?.rows.VL.less }}</td><td>{{ form.credits?.rows.SL.less }}</td></tr>
                                    <tr class="italic"><th>Balance</th><td>{{ form.credits?.rows.VL.after }}</td><td>{{ form.credits?.rows.SL.after }}</td></tr>
                                </tbody>
                            </table>
                            <div class="signature">
                                <img v-if="signers.certifier?.signature" :src="signers.certifier.signature" :style="imageStyle('certifier')" alt="" class="esign" />
                                <p class="name">{{ signers.certifier?.name }}</p>
                                <p class="position">{{ position(signers.certifier) }}</p>
                                <p class="caption">(Authorized Officer)</p>
                            </div>
                        </div>
                    </div>
                    <div class="cell">
                        <p class="head">7.B&nbsp; RECOMMENDATION</p>
                        <div class="lines">
                            <p class="check"><span class="box">{{ box(form.recommendation === 'approval') }}</span>For approval</p>
                            <p class="check"><span class="box">{{ box(form.recommendation === 'disapproval') }}</span>For disapproval due to <u class="grow">{{ form.recommendation_reason }}</u></p>
                            <p class="check"><u class="grow"></u></p>
                            <p class="check"><u class="grow"></u></p>
                            <p class="check"><u class="grow"></u></p>
                            <p v-if="!form.recommendation_required" class="check note">Not required for this type of leave.</p>
                            <div class="signature">
                                <img v-if="signers.recommender?.signature" :src="signers.recommender.signature" :style="imageStyle('recommender')" alt="" class="esign" />
                                <p class="name">{{ signers.recommender?.name }}</p>
                                <p class="position">{{ position(signers.recommender) }}</p>
                                <p class="caption">(Authorized Officer)</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row grow-7cd decision">
                    <div class="split">
                        <div class="cell">
                            <p class="head">7.C&nbsp; APPROVED FOR:</p>
                            <p class="check"><u class="w-days">{{ form.approved_for.with_pay }}</u> days with pay</p>
                            <p class="check"><u class="w-days">{{ form.approved_for.without_pay }}</u> days without pay</p>
                            <p class="check"><u class="w-days">{{ form.approved_for.others }}</u> others (Specify)</p>
                        </div>
                        <div class="cell">
                            <p class="head">7.D&nbsp; DISAPPROVED DUE TO:</p>
                            <p class="check"><u class="grow">{{ form.disapproval_reason }}</u></p>
                            <p class="check"><u class="grow"></u></p>
                            <p class="check"><u class="grow"></u></p>
                        </div>
                    </div>
                    <div class="official-row">
                        <div class="signature official">
                            <img v-if="signers.approver?.signature" :src="signers.approver.signature" :style="imageStyle('approver')" alt="" class="esign" />
                            <p class="name">{{ signers.approver?.name }}</p>
                            <p class="position">{{ position(signers.approver) }}</p>
                            <p class="caption">(Authorized Official)</p>
                        </div>
                        <p v-if="form.head_note" class="head-note"><strong>Note:</strong> {{ form.head_note }}</p>
                        <p class="decided">{{ form.decided_at }}</p>
                    </div>
                </div>
            </div>
            <footer class="foot">
                <span></span>
                <span>This is a system generated document.</span>
                <span><template v-if="form.printed_by">Printed by: {{ form.printed_by }}</template></span>
            </footer>
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
.cs6-actions { display: flex; gap: 0.5rem; flex-shrink: 0; }
.cs6-toolbar button, .cs6-secondary { padding: 0.4rem 1rem; border-radius: 0.375rem; font-weight: 600; white-space: nowrap; }
.cs6-toolbar button { background: var(--color-primary); color: #fff; }
.cs6-secondary { border: 1px solid var(--color-border); color: var(--color-text); background: var(--color-surface); }
.cs6 { background: var(--color-surface-muted); padding: 0.3in 0; overflow-x: auto; }
.cs6-adjust { padding: 0.5rem 1rem; background: var(--color-surface); border-bottom: 1px solid var(--color-border); font: 13px/1.4 system-ui, sans-serif; color: var(--color-text); }
.cs6-adjust summary { cursor: pointer; font-weight: 600; }
.cs6-adjust p { margin: 0.4rem 0; color: var(--color-text-muted); }
.adjust-grid { display: grid; gap: 0.4rem; }
.adjust-row { display: flex; flex-wrap: wrap; align-items: center; gap: 0.5rem 1.5rem; }
.adjust-name { min-width: 11rem; font-weight: 600; }
.adjust-group { display: inline-flex; align-items: center; gap: 0.35rem; }
.adjust-group button { width: 1.8rem; height: 1.8rem; border: 1px solid var(--color-border); border-radius: 0.375rem; background: var(--color-surface); color: var(--color-text); }
.adjust-group output { min-width: 4.2rem; text-align: center; font-variant-numeric: tabular-nums; }
.adjust-reset { margin-top: 0.5rem; }

/* The paper: one Folio sheet, always black on white like the printed form. */
.sheet {
    --edge: 0.25in;      /* page margin, as in the Excel copy */
    --pad-y: 5px;        /* inside every box */
    --pad-x: 8px;
    --indent: 14px;      /* lines under a box heading */
    --line: 0.18in;      /* one line of a box: the pitch of the lists */
    --line-type: calc(var(--line) * 1.15); /* 6.A's leave types, a little roomier */
    --sign: 0.38in;      /* room to sign above a signatory's name */
    --rule: 1px solid #000;

    display: flex; flex-direction: column;
    width: 8.5in; height: 13in; margin: 0 auto; padding: var(--edge); box-sizing: border-box; overflow: hidden;
    background: #fff; color: #000; font: 9pt/1.25 Arial, Helvetica, sans-serif; box-shadow: 0 1px 4px rgb(0 0 0 / 0.15);
}
p { margin: 0; }
.center { text-align: center; }

/* Masthead */
.masthead { flex: none; }
.masthead-top { display: flex; justify-content: space-between; align-items: flex-start; }
.form-no { font: italic 700 8pt/1.2 Calibri, Arial, sans-serif; }
.annex { font-weight: 700; font-size: 10pt; }
.masthead-main { position: relative; display: flex; justify-content: center; align-items: center; margin-top: 4px; min-height: 0.7in; }
.agency { display: flex; align-items: center; gap: 0.18in; text-align: center; font-size: 9pt; }
.agency .logo { height: 0.68in; width: auto; }
.agency-name { font-weight: 700; font-size: 10pt; text-transform: uppercase; }
.stamp { position: absolute; right: 0.3in; top: 50%; transform: translateY(-50%); padding: 0.12in 0.14in; border: 1px dashed #777; font-size: 7pt; }
.title-row { position: relative; margin: 6px 0; }
h1 { text-align: center; font-size: 16pt; font-weight: 700; letter-spacing: 0.02em; margin: 0; }
.control { position: absolute; right: 0; bottom: 0; width: 1.5in; text-align: center; font-size: 8pt; }
.control-number { font-weight: 700; font-size: 9pt; border-bottom: var(--rule); }

/* The form: rows share the page height; the big sections grow. The
   column divider sits where CSC's form has it (6.A wider than 6.B). */
.frame { flex: 1; display: flex; flex-direction: column; border: 1.5px solid #000; min-height: 0; }
.row { display: grid; border-bottom: var(--rule); }
.row:last-child { border-bottom: 0; }
.split { display: grid; grid-template-columns: 55% 45%; }
.split > .cell + .cell { border-left: var(--rule); }
/* Each section is as tall as its lines, and the page's spare height is
   shared out as on CSC's printout: mostly to the sections with
   signatures, so they have room to sign. */
.grow-6ab { flex: 1 1 auto; }
.grow-6cd { flex: 1 1 auto; }
.grow-7ab { flex: 2 1 auto; }
.grow-7cd { flex: 2 1 auto; }
h2 { flex: none; margin: 0; padding: 3px var(--pad-y); text-align: center; font-size: 10.5pt; font-weight: 900; letter-spacing: 0.02em; border-bottom: var(--rule); border-top: 3px double #000; }

.cell { display: flex; flex-direction: column; padding: var(--pad-y) var(--pad-x); min-width: 0; }
.head { flex: none; margin-bottom: 4px; }
/* A box's lines run from its top at one pitch; what's left is below. */
.lines { flex: 1; display: flex; flex-direction: column; padding-left: var(--indent); }
/* Never squeeze a line (or the 7.A table) below its contents: a squeezed
   line spills onto the next, as "As of" did onto the table. */
.lines > *, .decision .cell > * { flex-shrink: 0; }
.foot-of-box { margin-top: auto; }
/* 6.C: "Inclusive dates" set apart from the days line above it. */
.spaced { margin-top: 10px; }
.types .check { min-height: var(--line-type); }

.person { grid-template-columns: 34% 66%; border-bottom: 0; }
.person .cell + .cell { padding-left: 0; }
.value { font-weight: 700; font-size: 9.5pt; margin-top: 6px; }
.value.office { padding-left: var(--indent); }
.name-grid { display: grid; grid-template-columns: 20% 27% 27% 26%; text-align: center; }
.name-grid span:first-child { text-align: left; }
.filing { display: flex; justify-content: space-between; align-items: baseline; padding: 8px var(--pad-x) 6px; }

.check { display: flex; align-items: baseline; gap: 5px; min-height: var(--line); padding-top: 2px; box-sizing: border-box; }
.check small { font-size: 6pt; white-space: nowrap; }
.box { flex: none; width: 12px; font-size: 10.5pt; line-height: 1; }
.sub { font-style: italic; font-weight: 700; min-height: var(--line); padding-top: 2px; box-sizing: border-box; }
.lines > .sub:not(:first-child) { margin-top: 4px; }
.sub.strong { margin-top: 2px; }
u.others { text-align: left; font-weight: 400; }
.note { font-style: italic; font-size: 7.5pt; }
.center-line { justify-content: center; }
u { display: inline-block; min-height: 1.25em; border-bottom: var(--rule); text-decoration: none; font-weight: 700; padding: 0 4px; }
u.grow { flex: 1; min-width: 0; }
.w-date { min-width: 1.4in; }
.w-position { min-width: 2.3in; }
.w-salary { min-width: 1.1in; }
.w-days { min-width: 0.6in; text-align: center; }
/* 3–5: values centered on their underlines. */
.filing u { text-align: center; }

.credits { width: 88%; align-self: center; margin: 8px 0 0 calc(-1 * var(--indent)); border-collapse: collapse; font-size: 8.5pt; }
.credits th, .credits td { border: var(--rule); padding: 2px 6px; text-align: center; }
.credits th { font-weight: 400; }
.credits tbody th { background: #e6e6e6; }
.credits tr.italic td, .credits tr.italic th { font-style: italic; }

.signature { --room: var(--sign); position: relative; align-self: center; width: 85%; margin: auto 0 0 calc(-1 * var(--indent)); padding-top: var(--room); text-align: center; }
.signature .name { font-weight: 700; min-height: 1.25em; }
/* The e-signature image rests on the name, inside the room left above it (--room). --dx, --dy and --scale are the manual adjustments. */
.signature .esign {
    position: absolute; left: 50%; bottom: calc(100% - var(--room) - 0.02in);
    height: calc(var(--room) + 0.04in); max-width: 80%; object-fit: contain; object-position: center bottom;
    transform: translateX(calc(-50% + var(--dx, 0in))) translateY(var(--dy, 0in)) scale(var(--scale, 1)); transform-origin: center bottom;
}
.signature .position { font-size: 8pt; min-height: 1.2em; }
.signature .caption { border-top: var(--rule); padding-top: 2px; }

.decision { display: flex; flex-direction: column; }
.decision .split { flex: 1; }
.decision .split > .cell + .cell { border-left: 0; }
.decision .cell { gap: 0; }
.decision .cell .check { padding-left: var(--indent); }
.official-row { flex: none; position: relative; padding-bottom: var(--pad-y); }
.signature.official { --room: 0.28in; width: 3.2in; margin: 0 auto; }
/* The Head's note: lower right, under 7.D, above the time of decision (at most four lines). */
.head-note {
    position: absolute; right: var(--pad-x); bottom: calc(var(--pad-y) + 0.2in); width: 2.1in;
    font-size: 7.5pt; line-height: 1.2; text-align: left; overflow: hidden;
    display: -webkit-box; -webkit-line-clamp: 4; -webkit-box-orient: vertical;
}
.decided { position: absolute; right: var(--pad-x); bottom: var(--pad-y); font-size: 8pt; }
.foot { flex: none; display: grid; grid-template-columns: 1fr auto 1fr; padding-top: 3px; font-size: 7pt; }
.foot span:last-child { text-align: right; }

@media print {
    @page { size: 8.5in 13in; margin: 0; }
    .cs6-toolbar, .cs6-adjust { display: none; }
    .cs6 { background: none; padding: 0; overflow: visible; }
    .sheet { box-shadow: none; margin: 0; break-after: avoid; }
}
</style>

<style>
/* The page body adds nothing around the sheet when printing. */
@media print {
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
}
</style>
