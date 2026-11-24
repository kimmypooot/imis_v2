<script setup>
import { ref, watch } from 'vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';

/**
 * "Print CS Form 48": which of the two accepted layouts to print. A single
 * DTR is one form on an A4 portrait page (print it twice for two copies); a
 * double DTR is two identical forms side by side on one A4 portrait page, to
 * cut in half. The last choice is remembered on this device.
 *
 * `url` is the print address without the layout; the print opens in a new tab.
 */
const props = defineProps({
    /** The print address of the sheet, or null while the dialog is closed. */
    url: { type: String, default: null },
    period: { type: String, default: '' },
});
const emit = defineEmits(['close']);

const STORAGE_KEY = 'imis.dtr.print-layout';
const layout = ref('single');

watch(
    () => props.url,
    (url) => {
        if (url) layout.value = localStorage.getItem(STORAGE_KEY) === 'double' ? 'double' : 'single';
    },
);

const options = [
    {
        value: 'single',
        title: 'Single DTR',
        description: 'One form on an A4 portrait page. Print it twice if you need two copies.',
    },
    {
        value: 'double',
        title: 'Double DTR',
        description: 'Two identical, narrower forms side by side on one A4 portrait page. Print once and cut down the middle.',
    },
];

const print = () => {
    localStorage.setItem(STORAGE_KEY, layout.value);
    const separator = props.url.includes('?') ? '&' : '?';
    window.open(`${props.url}${separator}layout=${layout.value}`, '_blank');
    emit('close');
};
</script>

<template>
    <Modal :open="url !== null" title="Print CS Form 48" icon="printer" @close="emit('close')">
        <fieldset class="space-y-3">
            <legend class="mb-3 text-sm text-text">
                Both layouts are accepted. Choose how to print the Daily Time Record<template v-if="period"> for {{ period }}</template>.
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

        <template #footer>
            <Button variant="secondary" icon="close" @click="emit('close')">Cancel</Button>
            <Button icon="printer" @click="print">Continue to print</Button>
        </template>
    </Modal>
</template>
