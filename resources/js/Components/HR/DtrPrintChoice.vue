<script setup>
import { computed, ref, watch } from 'vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';

/**
 * "Print CS Form 48" or "Export to Excel": which of the two accepted layouts to use. A single
 * DTR is one form on an A4 portrait page (print it twice for two copies); a
 * double DTR is two identical forms side by side on one A4 portrait page, to
 * cut in half. The last choice is remembered on this device.
 *
 * `url` is the print (or export) address without the layout. A print opens in a
 * new tab; an Excel export downloads the workbook, laid out the same way.
 */
const props = defineProps({
    /** The print address of the sheet, or null while the dialog is closed. */
    url: { type: String, default: null },
    period: { type: String, default: '' },
    /** 'print' opens the printable form; 'export' downloads the Excel workbook. */
    action: { type: String, default: 'print', validator: (value) => ['print', 'export'].includes(value) },
    /** What is being exported or printed, for the sentence under the title (an office, an employee). */
    subject: { type: String, default: '' },
    /** Your own DTR: the Excel file is a copy, so the notice must be ticked every time before it downloads. */
    acknowledge: { type: Boolean, default: false },
});
const emit = defineEmits(['close']);

const STORAGE_KEY = 'imis.dtr.print-layout';
const layout = ref('single');
const understood = ref(false);

watch(
    () => props.url,
    (url) => {
        if (url) {
            layout.value = localStorage.getItem(STORAGE_KEY) === 'double' ? 'double' : 'single';
            // The notice is asked again on every download, so it is never remembered.
            understood.value = false;
        }
    },
);

const exporting = computed(() => props.action === 'export');
const options = computed(() => [
    {
        value: 'single',
        title: 'Single DTR',
        description: `One form on an A4 portrait page. ${exporting.value ? 'The workbook prints the same way: print it twice if you need two copies.' : 'Print it twice if you need two copies.'}`,
    },
    {
        value: 'double',
        title: 'Double DTR',
        description: `Two identical, narrower forms side by side on one A4 portrait page. ${exporting.value ? 'The workbook is laid out and prints the same way: print once and cut down the middle.' : 'Print once and cut down the middle.'}`,
    },
]);

const needsAck = computed(() => exporting.value && props.acknowledge);

const print = () => {
    if (needsAck.value && !understood.value) return;
    localStorage.setItem(STORAGE_KEY, layout.value);
    const separator = props.url.includes('?') ? '&' : '?';
    const target = `${props.url}${separator}layout=${layout.value}`;
    if (exporting.value) {
        window.location.assign(target);
    } else {
        window.open(target, '_blank');
    }
    emit('close');
};
</script>

<template>
    <Modal :open="url !== null" :title="exporting ? 'Export CS Form 48 to Excel' : 'Print CS Form 48'" :icon="exporting ? 'download' : 'printer'" @close="emit('close')">
        <fieldset class="space-y-3">
            <legend class="mb-3 text-sm text-text">
                Both layouts are accepted. Choose how to {{ exporting ? 'lay out the workbook for' : 'print' }} the Daily Time Record<template v-if="subject"> of {{ subject }}</template><template v-if="period"> for {{ period }}</template>.
            </legend>

            <label
                v-for="option in options"
                :key="option.value"
                class="flex cursor-pointer items-start gap-3 rounded-lg border p-3 transition-colors focus-within:ring-2 focus-within:ring-focus-ring"
                :class="layout === option.value ? 'border-primary bg-info-bg' : 'border-border hover:border-border-strong'"
            >
                <input v-model="layout" type="radio" name="dtr-layout" :value="option.value" class="mt-1 h-4 w-4 shrink-0 text-link focus:ring-0" />

                <!-- A tiny picture of the paper -->
                <span class="flex h-14 w-16 shrink-0 items-center justify-center rounded-sm border border-border bg-surface" aria-hidden="true">
                    <span v-if="option.value === 'single'" class="h-11 w-8 rounded-[2px] border border-text-muted bg-surface-muted" />
                    <span v-else class="flex h-11 w-8 items-stretch justify-center gap-px rounded-[2px] border border-text-muted bg-surface-muted p-0.5">
                        <span class="w-full rounded-[1px] bg-surface ring-1 ring-text-muted" />
                        <span class="w-full rounded-[1px] bg-surface ring-1 ring-text-muted" />
                    </span>
                </span>

                <span class="min-w-0">
                    <span class="block text-sm font-semibold text-text">{{ option.title }}</span>
                    <span class="block text-sm text-text-muted">{{ option.description }}</span>
                </span>
            </label>
        </fieldset>

        <div v-if="needsAck" class="mt-4 space-y-3 rounded-lg border border-warning-border bg-warning-bg p-3 text-sm text-warning-fg">
            <p class="font-semibold">Before you download</p>
            <p>
                This Excel file is a copy of your DTR in IMIS. If a time or reason is wrong, close this, press <strong>Edit my entries</strong>, then export again. Changes made in the spreadsheet are not recorded in IMIS, and HR's computation and verification use what is in IMIS.
            </p>
            <label class="flex cursor-pointer items-start gap-2 text-text">
                <input v-model="understood" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                <span>I understand that I have to make my changes in IMIS, not in the Excel file.</span>
            </label>
        </div>

        <template #footer>
            <Button variant="secondary" icon="close" @click="emit('close')">Cancel</Button>
            <Button :icon="exporting ? 'download' : 'printer'" :disabled="needsAck && !understood" @click="print">{{ exporting ? 'Download Excel' : 'Continue to print' }}</Button>
        </template>
    </Modal>
</template>
