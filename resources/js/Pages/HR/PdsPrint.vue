<script setup>
import { nextTick, onMounted } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { formatDateTime } from '../../Utils/datetime';
import { PHOTO, SHEETS } from '../../Components/HR/Pds/pdsFormGeometry';

/**
 * The print-ready CS Form No. 212 (Revised 2026). Each page is the official
 * form itself: CSC's workbook as Excel prints it on Folio (8.5 × 13 in,
 * 0.25 in margins, each sheet on one page), rendered at 300 dpi
 * (public/images/pds), with the entries written into its cells at the
 * positions Excel prints them (pdsFormGeometry.js). Which text goes in which
 * cell, and which boxes are ticked, comes from PdsForm (backend); the Excel
 * copy is the same entries written into CSC's own workbook.
 *
 * Pages: C1–C4, then a continuation sheet (C5–C11) for each list that
 * doesn't fit, as the form instructs. Print with no margins at 100%.
 *
 * `signature` is null for the current (unsigned) PDS, or who e-signed a
 * submission and when.
 */
const props = defineProps({
    pages: { type: Array, required: true },
    photoUrl: { type: String, default: null },
    signature: { type: Object, default: null },
    excelUrl: { type: String, default: null },
    /** How it is signed (hand · pnpki · esign) and the choices, each with its page URL (PdsPrintout). */
    mode: { type: String, default: 'hand' },
    modes: { type: Array, default: () => [] },
});

// ── Placement (points from the page's top-left) ────────────────────
const parse = (ref) => {
    const [, col, row] = ref.match(/^([A-Z]+)(\d+)$/);
    return { col, row };
};

/** A cell or merged range's box on the page, with a little padding inside its borders. */
const rect = (sheet, at) => {
    const g = SHEETS[sheet];
    const [from, to = from] = at.split(':').map(parse);
    const left = g.cols[from.col][0];
    const right = g.cols[to.col][1];
    const top = g.rows[from.row][0];
    const bottom = g.rows[to.row][1];
    return { left, right, top, bottom };
};


const entryStyle = (sheet, cell) => {
    const { left, right, top, bottom } = rect(sheet, cell.at);
    const align = cell.align ?? 'left';
    // Clear of the cell's borders; left-aligned text a little more, as Excel indents it.
    const inset = cell.kind === 'signature' || align === 'left' ? 3 : 1.5;
    return {
        left: `${left + inset}pt`,
        top: `${top + 0.6}pt`,
        width: `${right - left - 2 * inset}pt`,
        height: `${bottom - top - 1.2}pt`,
        justifyContent: align === 'right' ? 'flex-end' : align === 'center' ? 'center' : 'flex-start',
        textAlign: align,
    };
};

/** A box to leave clean (its printed hint covered), just inside its borders. */
const coverStyle = (sheet, cell) => {
    const { left, right, top, bottom } = rect(sheet, cell.at);
    return { left: `${left + 1.5}pt`, top: `${top + 1.5}pt`, width: `${right - left - 3}pt`, height: `${bottom - top - 3}pt` };
};

const boxStyle = (sheet, link) => {
    const [left, top, side] = SHEETS[sheet].boxes[link];
    return { left: `${left}pt`, top: `${top}pt`, width: `${side}pt`, height: `${side}pt` };
};

const photoStyle = {
    left: `${PHOTO[0]}pt`, top: `${PHOTO[1]}pt`, width: `${PHOTO[2] - PHOTO[0]}pt`, height: `${PHOTO[3] - PHOTO[1]}pt`,
};

/**
 * Where a two-line entry breaks, in order of preference: after a spaced
 * dash ("… AWARD) - | INDIVIDUAL CATEGORY"), before a parenthesis
 * ("… GAWA | (PINTIG …)"), after a comma. `before` breaks ahead of the match.
 */
const BREAKS = [
    { pattern: / [-–—] /g, before: false },
    { pattern: / \(/g, before: true },
    { pattern: /, /g, before: false },
];

/**
 * Every entry stays at 8.55 pt (.pds .v), never smaller: one too long for
 * its cell breaks onto two lines at the most natural point that fits (see
 * BREAKS), choosing the most even split; failing that, the lines are
 * balanced. Wrapped lines are set close together, centred in the cell; in
 * a tight row they may reach the cell's borders.
 */
const fit = (el) => {
    const t = el.querySelector('.t');
    const text = (el.dataset.text ??= t.textContent);
    const overflows = () => el.scrollWidth > el.clientWidth + 0.5;
    const show = (lines) => {
        t.textContent = lines.join('\n');
        t.style.whiteSpace = lines.length > 1 ? 'pre' : '';
    };

    show([text]);
    el.classList.remove('wrapped');
    if (!overflows()) return;

    el.classList.add('wrapped');
    for (const { pattern, before } of BREAKS) {
        let best = null;
        for (const match of text.matchAll(pattern)) {
            const cut = before ? match.index : match.index + match[0].length;
            const lines = [text.slice(0, cut).trimEnd(), text.slice(cut).trimStart()];
            show(lines);
            const uneven = Math.abs(lines[0].length - lines[1].length);
            if (!overflows() && (!best || uneven < best.uneven)) best = { lines, uneven };
        }
        if (best) {
            show(best.lines);
            return;
        }
    }
    show([text]);
};
const vFit = { mounted: fit, updated: fit };

onMounted(async () => {
    await document.fonts?.ready;
    await nextTick();
    // Entries were first measured before the fonts finished loading.
    document.querySelectorAll('.pds .v').forEach(fit);
});

const SHEET_LABELS = {
    C5: 'C5 — Learning and Development (continued)', C6: 'C6 — Work Experience (continued)', C7: 'C7 — Children (continued)',
    C8: 'C8 — Educational Background (continued)', C9: 'C9 — Civil Service Eligibility (continued)',
    C10: 'C10 — Voluntary Work (continued)', C11: 'C11 — Other Information (continued)',
};
const continuations = props.pages.filter((page) => SHEET_LABELS[page.sheet]).map((page) => SHEET_LABELS[page.sheet]);

const print = () => window.print();
</script>

<template>
    <Head title="Personal Data Sheet — print" />

    <div class="pds-toolbar">
        <div>
            <p>
                <strong>CS Form No. 212 (Revised 2026)</strong> —
                {{ signature ? `${signature.status}, signed ${formatDateTime(signature.signed_at)}` : 'current PDS, not signed' }}.
                {{ pages.length }} pages<template v-if="continuations.length">, including {{ continuations.join('; ') }}</template>.
            </p>
            <p class="hint">Print on 8.5 × 13 in (Long) paper. In the print dialog: Margins <strong>None</strong>, Scale <strong>100%</strong>. Choose “Save as PDF” to keep a copy.</p>
        </div>
        <div class="actions">
            <a v-if="excelUrl" :href="excelUrl" class="secondary">Download Excel</a>
            <button type="button" @click="print">Print / Save as PDF</button>
        </div>
        <!-- How it will be signed: changes the signature boxes on every page and the Excel copy -->
        <nav class="signing" aria-label="How this PDS is signed">
            <span class="signing-label">Signature:</span>
            <Link
                v-for="option in modes"
                :key="option.value"
                :href="option.url"
                preserve-scroll
                class="signing-option"
                :class="{ active: option.value === mode }"
                :aria-current="option.value === mode ? 'true' : undefined"
            >{{ option.label }}</Link>
            <span class="signing-note">{{ modes.find((option) => option.value === mode)?.description }}</span>
        </nav>
    </div>

    <main class="pds">
        <section v-for="(page, p) in pages" :key="p" class="sheet">
            <img class="bg" :src="SHEETS[page.sheet].image" alt="" />

            <template v-for="(cell, c) in page.cells" :key="c">
                <!-- A box left clean: its printed hint covered -->
                <span v-if="cell.kind === 'blank'" class="cover" :style="coverStyle(page.sheet, cell)" />
                <span v-else-if="cell.kind === 'signature-image' && signature?.image_url" class="v esign" :style="entryStyle(page.sheet, cell)">
                    <img :src="signature.image_url" :alt="cell.text" />
                </span>
                <span
                    v-else
                    v-fit
                    class="v"
                    :class="{ signature: cell.kind === 'signature' }"
                    :style="entryStyle(page.sheet, cell)"
                ><span class="t">{{ cell.text }}</span></span>
            </template>

            <svg v-for="link in page.checks" :key="link" class="tick" :style="boxStyle(page.sheet, link)" viewBox="0 0 10 10" aria-hidden="true">
                <path d="M1.6 5.2 L4.1 7.8 L8.6 1.9" />
            </svg>

            <img v-if="page.sheet === 'C4' && photoUrl" class="photo" :src="photoUrl" alt="" :style="photoStyle" />
        </section>

        <p class="printed">Printed from IMIS {{ formatDateTime(new Date().toISOString()) }}</p>
    </main>
</template>

<style>
/* CS Form No. 212: the official page as the background, entries on top.
   Fixed black-on-white on purpose: this is a paper form, not the app UI. */
.pds {
    font-family: 'Arial Narrow', Arial, Helvetica, sans-serif;
    color: #000;
    background: #9ca3af;
    padding: 1rem 0;
}
.pds .sheet {
    position: relative;
    width: 612pt;
    height: 936pt;
    margin: 0 auto 1rem;
    background: #fff;
    overflow: hidden;
    box-shadow: 0 2px 8px rgb(0 0 0 / 0.3);
}
.pds .bg { position: absolute; inset: 0; width: 612pt; height: 936pt; }
.pds .v {
    position: absolute;
    display: flex;
    align-items: center;
    overflow: hidden;
    white-space: nowrap;
    font-size: 8.55pt;
    /* Every entry in bold, so what was filled in stands out from the form's own printing. */
    font-weight: 700;
    line-height: 1.1;
}
/* Middle means the letters themselves: the line box is trimmed to the
   capitals' height and the baseline, so the space kept for accents and
   descenders doesn't push the text above the middle of its cell. */
.pds .v .t { display: block; max-width: 100%; text-box: trim-both cap alphabetic; }
/* A long entry: on more lines at the same size, close together, and never cut off. */
.pds .v.wrapped { white-space: normal; overflow: visible; }
.pds .v.wrapped .t { line-height: 0.95; text-wrap: balance; }
/* An e-signature covers the box's "(e-signature/digital certificate)" hint. */
.pds .v.signature { background: #fff; font-style: italic; }
.pds .cover { position: absolute; background: #fff; }
/* The e-signature image: as large as fits the box, centred, over its hint. */
.pds .v.esign { background: #fff; justify-content: center; }
.pds .v.esign img { max-width: 100%; max-height: 100%; object-fit: contain; }
.pds .tick { position: absolute; overflow: visible; }
.pds .tick path { fill: none; stroke: #000; stroke-width: 1.6; stroke-linecap: round; stroke-linejoin: round; }
/* A 1 pt stroke like the frame's, drawn inside the edge so the photo stays exactly 3.5 × 4.5 cm. */
.pds .photo { position: absolute; object-fit: cover; outline: 1pt solid #000; outline-offset: -1pt; }
.pds .printed { text-align: center; font: 7pt system-ui, sans-serif; color: #fff; }

.pds-toolbar {
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
.pds-toolbar .hint { opacity: 0.9; }
.pds-toolbar .actions { display: flex; gap: 0.5rem; align-items: center; flex-shrink: 0; }
.pds-toolbar .signing { flex-basis: 100%; display: flex; flex-wrap: wrap; align-items: center; gap: 0.4rem 0.5rem; }
.pds-toolbar .signing-label { font-weight: 600; }
.pds-toolbar .signing-option { border: 1px solid #fff; border-radius: 9999px; padding: 0.2rem 0.8rem; color: #fff; text-decoration: none; }
.pds-toolbar .signing-option:hover { background: rgb(255 255 255 / 0.15); }
.pds-toolbar .signing-option:focus-visible { outline: 2px solid #fff; outline-offset: 2px; }
.pds-toolbar .signing-option.active { background: #fff; color: var(--color-csc-blue); font-weight: 600; }
.pds-toolbar .signing-note { flex-basis: 100%; opacity: 0.9; font-size: 0.8rem; }
.pds-toolbar button, .pds-toolbar .secondary { border-radius: 0.375rem; padding: 0.35rem 1rem; font-weight: 600; white-space: nowrap; }
.pds-toolbar button { background: #fff; color: var(--color-csc-blue); }
.pds-toolbar .secondary { border: 1px solid #fff; color: #fff; }

@media print {
    @page { size: 8.5in 13in; margin: 0; }
    html, body { margin: 0 !important; padding: 0 !important; background: #fff !important; }
    .pds-toolbar, .pds .printed { display: none; }
    .pds { background: none; padding: 0; }
    .pds .sheet { box-shadow: none; margin: 0; break-after: page; }
    .pds .sheet:last-of-type { break-after: auto; }
}
</style>
