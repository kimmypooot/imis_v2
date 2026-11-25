<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';

/**
 * Notices of Salary Adjustment and of Step Increment on the office's 2026
 * templates: A4 on the CSC RO VIII letterhead, Aptos 12 pt, 1-inch margins.
 * Positions were measured from Word's rendering of the templates
 * (NOSA_Template_2026.docx, NOSI_Template_2026.docx). A NOSA prints an
 * Employee Copy and an OHRMD/HRD Copy; a NOSI one page.
 *
 * The signature space is left blank on purpose: the notice is signed by
 * hand or with a PNPKI digital signature after it is printed or saved as
 * PDF. The page title is HRD's file name, so "Save as PDF" suggests it.
 * No app layout: just the notices. All wording comes from the server.
 */
defineProps({
    notices: { type: Array, required: true },
    title: { type: String, required: true },
});

const NOSA_COPIES = ['Employee Copy', 'OHRMD/HRD Copy'];

// Off when printing on paper that already has the letterhead.
const letterhead = ref(true);
const print = () => window.print();
</script>

<template>
    <Head :title="title" />

    <div class="sn-toolbar">
        <p>
            <strong>{{ notices.length === 1 ? notices[0].title : `${notices.length} notices` }}</strong>.
            Print on A4, margins <em>None</em>, scale 100%. To sign with PNPKI, choose “Save as PDF”.
        </p>
        <label class="sn-check"><input v-model="letterhead" type="checkbox" /> Letterhead</label>
        <button type="button" @click="print">Print</button>
    </div>

    <main class="sn">
        <template v-for="notice in notices" :key="notice.id">
            <!-- Notice of Salary Adjustment (NBC Annex B-1), two copies -->
            <template v-if="notice.type === 'nosa'">
                <section v-for="copy in NOSA_COPIES" :key="`${notice.id}-${copy}`" class="page" :aria-label="`${notice.title}, ${notice.addressee}, ${copy}`">
                    <img v-if="letterhead" class="letterhead" src="/images/brand/csc-ro8-letterhead-a4.jpg" alt="" />

                    <h1 class="abs title" style="top: 63.6mm">{{ notice.title }}</h1>
                    <p class="abs right-margin" style="top: 76mm">{{ notice.notice_date }}</p>
                    <p class="abs left" style="top: 85.9mm"><strong>{{ notice.addressee }}</strong><br />{{ notice.office }}</p>
                    <p class="abs left" style="top: 109.5mm">{{ notice.salutation }}</p>

                    <div class="abs body nosa-body" style="top: 118.3mm">
                        <p class="indent justify">
                            Pursuant to {{ notice.basis }}, your salary is hereby adjusted effective
                            <strong class="u">{{ notice.effective }}</strong>, as follows:
                        </p>
                        <ol class="nosa-items">
                            <li>
                                <span>Adjusted monthly basic salary effective <strong class="u">{{ notice.effective }}</strong>, under the new Salary Schedule; SG <strong class="u">{{ notice.salary_grade }}</strong>, Step <strong class="u">{{ notice.to_step }}</strong></span>
                                <strong class="amount">₱ {{ notice.to_salary }}</strong>
                            </li>
                            <li>
                                <span>Actual monthly basic salary as of {{ notice.as_of }};<br />SG <strong>{{ notice.salary_grade }}</strong>, Step <strong>{{ notice.from_step }}</strong></span>
                                <strong class="amount">₱ {{ notice.from_salary }}</strong>
                            </li>
                            <li>
                                <span>Monthly salary adjustment effective <strong>{{ notice.effective }}</strong><br />(1-2)</span>
                                <strong class="amount">₱ {{ notice.difference }}</strong>
                            </li>
                        </ol>
                        <p class="indent justify">It is understood that this salary adjustment is subject to review and post-audit, and to appropriate re-adjustment and refund if found not in order.</p>

                        <!-- In the flow, so a longer text pushes it down instead of overlapping. The blank space is for the signature. -->
                        <p style="margin: 8mm 0 0 80.8mm">Very truly yours,</p>
                        <p class="center-block" style="margin: 10.6mm 0 0 85.3mm; width: 74mm"><strong>{{ notice.signatory_name }}</strong><br />{{ notice.signatory_position }}</p>
                    </div>

                    <div class="abs nosa-foot" style="top: 238.1mm; left: 27.9mm">
                        <p>Position Title: {{ notice.position }}</p>
                        <p>Salary Grade: <span class="u">{{ notice.salary_grade }}</span></p>
                        <p>Item No./Unique Item No., FY <span class="u">{{ notice.year }}</span> Personnel Services Itemization</p>
                        <p>and/or Plantilla of Personnel: {{ notice.item_number }}</p>
                        <p class="cf">Copy Furnished: GSIS</p>
                        <p class="copy">{{ copy }}</p>
                    </div>
                </section>
            </template>

            <!-- Notice of Step Increment Due to Length of Service -->
            <section v-else class="page" :aria-label="`${notice.title}, ${notice.addressee}`">
                <img v-if="letterhead" class="letterhead" src="/images/brand/csc-ro8-letterhead-a4.jpg" alt="" />

                <h1 class="abs title" style="top: 60.2mm">{{ notice.title }}</h1>
                <p class="abs" style="top: 73.7mm; right: 39mm">{{ notice.notice_date }}</p>
                <p class="abs left" style="top: 87.1mm"><strong>{{ notice.addressee }}</strong><br />{{ notice.office }}</p>
                <p class="abs left" style="top: 106.3mm">{{ notice.salutation }}</p>

                <div class="abs body nosi-body" style="top: 114.9mm">
                    <p class="indent justify">
                        Pursuant to {{ notice.basis }}, your salary as {{ notice.position_upper }} is hereby adjusted effective {{ notice.effective }} as follows:
                    </p>
                    <ol class="nosi-items">
                        <li>
                            <span>Actual monthly basic Salary as of {{ notice.as_of }}<br />(SG {{ notice.salary_grade }}, Step {{ notice.from_step }})</span>
                            <span class="peso">P</span><span class="amount">{{ notice.from_salary }}</span>
                        </li>
                        <li>
                            <span>Add: One (1) Step Increment<br />Due to Length of Service</span>
                            <span class="peso">P</span><span class="amount">{{ notice.difference }}</span>
                        </li>
                        <li class="one-line">
                            <span>Adjusted monthly basic salary effective {{ notice.effective }}</span>
                            <span class="peso">P</span><span class="amount">{{ notice.to_salary }}</span>
                        </li>
                    </ol>
                    <p class="indent justify">This salary adjustment is subject to review and post-audit, and to appropriate re-adjustment and refund if found not in order.</p>

                    <!-- In the flow, so a longer text pushes it down instead of overlapping. The blank space is for the signature. -->
                    <p style="margin: 8.5mm 0 0 101.3mm">Very truly yours,</p>
                    <p style="margin: 18.7mm 0 0 101.3mm"><strong>{{ notice.signatory_name }}</strong><br />{{ notice.signatory_position }}</p>
                </div>

                <div class="abs left" style="top: 248.7mm">
                    <p>Item No. {{ notice.item_number }}</p>
                    <p>FY {{ notice.year }} Plantilla of Personnel</p>
                    <p class="cf">CF: GSIS Tacloban Branch</p>
                </div>
            </section>
        </template>
    </main>
</template>

<style scoped>
.sn-toolbar {
    position: sticky; top: 0; z-index: 10;
    display: flex; align-items: center; gap: 1rem;
    padding: 0.6rem 1rem; background: var(--color-surface); border-bottom: 1px solid var(--color-border);
    font: 13px/1.4 system-ui, sans-serif; color: var(--color-text);
}
.sn-toolbar p { flex: 1; margin: 0; }
.sn-check { display: inline-flex; align-items: center; gap: 0.35rem; white-space: nowrap; }
.sn-toolbar button { padding: 0.4rem 1rem; border-radius: 0.375rem; background: var(--color-primary); color: #fff; font-weight: 600; }
.sn { background: var(--color-surface-muted); padding: 0.3in 0; min-height: 100vh; }

/* The sheet: A4, with Word's text metrics (Aptos 12 pt). */
.page {
    position: relative; width: 210mm; height: 297mm; margin: 0 auto 0.3in; overflow: hidden;
    background: #fff; color: #000; box-shadow: 0 1px 4px rgb(0 0 0 / 0.25);
    font: 12pt/1.22 Aptos, 'Aptos Display', 'Segoe UI', Calibri, Arial, sans-serif;
}
.letterhead { position: absolute; inset: 0; width: 100%; height: 100%; }
.abs { position: absolute; margin: 0; }
.left { left: 25.4mm; }
.right-margin { right: 25.4mm; }
.title { left: 25.4mm; right: 25.4mm; text-align: center; font-size: 12pt; font-weight: 700; }
.center-block { text-align: center; }
p { margin: 0; }
.body { left: 25.4mm; right: 25.4mm; }
.indent { text-indent: 12.9mm; }
.justify { text-align: justify; }
.u { text-decoration: underline; }

/* NOSA: 1.4 line spacing, amounts flush with the right margin. */
.nosa-body { line-height: 1.4; }
.nosa-items { list-style: none; counter-reset: item; margin: 4.6mm 0 4.4mm; padding: 0; }
.nosa-items li { counter-increment: item; position: relative; display: flex; justify-content: space-between; gap: 6mm; padding-left: 14.6mm; margin-bottom: 4.2mm; }
.nosa-items li::before { content: counter(item) '.'; position: absolute; left: 8.5mm; }
.nosa-items li span { max-width: 108mm; }
.nosa-items .amount { white-space: nowrap; margin-right: 2mm; font-variant-numeric: tabular-nums; }
.nosa-foot { font-size: 10pt; line-height: 1.32; }
.nosa-foot .cf { margin-top: 4.6mm; }
.nosa-foot .copy { margin-top: 3.6mm; font-size: 7pt; font-weight: 700; font-style: italic; }

/* NOSI: single spacing, the peso sign and amount in their own columns. */
.nosi-body { line-height: 1.22; }
.nosi-items { list-style: none; counter-reset: item; margin: 2.6mm 0 4mm; padding: 0; }
.nosi-items li { counter-increment: item; position: relative; display: grid; grid-template-columns: 1fr 4mm 18mm; align-items: end; padding-left: 19mm; margin: 0 10.6mm 4.6mm 0; }
.nosi-items li::before { content: counter(item) '.'; position: absolute; left: 12.9mm; top: 0; }
.nosi-items .amount { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.nosi-items .one-line { align-items: start; }
.cf { margin-top: 4.6mm; }

@media print {
    @page { size: A4; margin: 0; }
    .sn-toolbar { display: none; }
    .sn { background: none; padding: 0; min-height: 0; }
    .page { margin: 0; box-shadow: none; break-after: page; }
    .page:last-child { break-after: auto; }
}
</style>
