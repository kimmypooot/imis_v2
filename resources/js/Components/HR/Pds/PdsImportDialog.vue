<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import Modal from '../../Feedback/Modal.vue';
import Alert from '../../Feedback/Alert.vue';
import Button from '../../UI/Button.vue';
import Icon from '../../UI/Icon.vue';
import InputError from '../../Forms/InputError.vue';

/**
 * Fills a PDS from the CSC's Excel form (CS Form No. 212, Revised 2026).
 * Step 1: choose the .xlsx — the server reads it and sends back a preview.
 * Step 2: review what was found, tick the sections to import, confirm.
 * Nothing is saved before step 2; a list section replaces the current rows.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    url: { type: String, required: true },
});

const emit = defineEmits(['close']);

const page = usePage();
const input = ref(null);
const form = useForm({ file: null });
const applying = ref(false);
const chosen = ref([]);

const preview = computed(() => (props.open ? (page.props.flash?.pdsImport ?? null) : null));

// Every section that was found and has no errors starts ticked.
watch(preview, (value) => {
    chosen.value = value ? value.sections.filter((section) => importable(section)).map((section) => section.key) : [];
});

const importable = (section) => section.count > 0 && section.errors.length === 0;

const upload = () => {
    if (!form.file) return;

    form.post(`${props.url}/import`, { preserveScroll: true, forceFormData: true });
};

const onFile = (event) => {
    form.file = event.target.files[0] ?? null;
    form.clearErrors();
};

const apply = () => {
    applying.value = true;

    router.post(
        `${props.url}/import/apply`,
        { token: preview.value.token, sections: chosen.value },
        { preserveScroll: true, onSuccess: () => close(), onFinish: () => (applying.value = false) },
    );
};

const close = () => {
    form.reset();
    if (input.value) input.value.value = '';
    emit('close');
};

const replaced = computed(() => (preview.value?.sections ?? []).filter((section) => chosen.value.includes(section.key) && section.kind === 'list' && !section.additive && section.current > 0));
</script>

<template>
    <Modal :open="open" :title="preview ? 'Review the import' : 'Import from Excel'" size="lg" @close="close">
        <!-- Step 1: choose the file -->
        <div v-if="!preview" class="space-y-4">
            <p class="text-sm text-text-muted">
                Upload the filled-in <strong class="text-text">CS Form No. 212 (Revised 2026)</strong> Excel file (.xlsx). You will see what was found and
                choose what to import before anything is saved. Trainings are added to the Learning &amp; Development log for review; ones already logged are skipped.
            </p>
            <div>
                <label for="pds-import-file" class="mb-1 block text-sm font-medium text-text">Excel file</label>
                <input
                    id="pds-import-file"
                    ref="input"
                    type="file"
                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    class="block w-full text-sm text-text file:mr-3 file:rounded-md file:border-0 file:bg-info-bg file:px-3 file:py-2 file:text-sm file:font-medium file:text-primary"
                    @change="onFile"
                />
                <InputError :message="form.errors.file" />
            </div>
        </div>

        <!-- Step 2: review -->
        <div v-else class="space-y-4">
            <p class="text-sm text-text-muted">
                Read from <strong class="text-text">{{ preview.file }}</strong
                >. Tick the sections to import.
            </p>

            <Alert v-for="warning in preview.warnings" :key="warning" tone="warning">{{ warning }}</Alert>

            <ul class="divide-y divide-border rounded-lg border border-border">
                <li v-for="section in preview.sections" :key="section.key" class="p-3">
                    <label class="flex items-start gap-3" :class="importable(section) ? 'cursor-pointer' : 'opacity-70'">
                        <input
                            v-model="chosen"
                            type="checkbox"
                            :value="section.key"
                            :disabled="!importable(section)"
                            class="mt-1 h-4 w-4 rounded border-border-strong text-primary focus:ring-focus-ring"
                        />
                        <span class="min-w-0 flex-1">
                            <span class="block text-sm font-medium text-text">{{ section.label }}</span>
                            <span class="block text-xs text-text-muted">
                                <template v-if="section.count === 0">{{ section.skipped ? `All ${section.skipped} already in the L&D log` : 'Nothing found' }}{{ section.current ? ` — your ${section.current} current ${section.current === 1 ? 'entry stays' : 'entries stay'}` : '' }}</template>
                                <template v-else-if="section.additive">
                                    {{ section.count }} new {{ section.count === 1 ? 'entry' : 'entries' }} to add to the L&amp;D log (pending review)<template v-if="section.skipped">; {{ section.skipped }} already logged or repeated, left out</template>
                                </template>
                                <template v-else-if="section.kind === 'list'">
                                    {{ section.count }} {{ section.count === 1 ? 'entry' : 'entries' }} found<template v-if="section.current"> (replaces your {{ section.current }} current)</template>
                                </template>
                                <template v-else>{{ section.count }} {{ section.count === 1 ? 'answer' : 'answers' }} found</template>
                            </span>
                            <span v-if="section.sample.length" class="mt-1 block truncate text-xs text-text-muted">e.g. {{ section.sample.join(' · ') }}</span>
                        </span>
                    </label>
                    <details v-if="section.skipped_items?.length" class="mt-2 ml-7 text-xs text-text-muted">
                        <summary class="cursor-pointer font-medium text-primary">See the {{ section.skipped }} left out</summary>
                        <ul class="mt-1 space-y-1">
                            <li v-for="item in section.skipped_items" :key="item.title">
                                {{ item.title }}
                                <span class="block text-text-muted/80">same as: {{ item.matches }}</span>
                            </li>
                        </ul>
                    </details>
                    <ul v-if="section.errors.length" class="mt-2 ml-7 list-disc space-y-0.5 pl-4 text-xs text-danger">
                        <li v-for="error in section.errors.slice(0, 5)" :key="error">{{ error }}</li>
                        <li v-if="section.errors.length > 5">…and {{ section.errors.length - 5 }} more. Fix them in the Excel file and upload it again.</li>
                    </ul>
                </li>
            </ul>

            <Alert v-if="replaced.length" tone="warning">
                Importing replaces the current entries in: {{ replaced.map((section) => section.label).join('; ') }}. This is logged.
            </Alert>
        </div>

        <template #footer>
            <Button variant="secondary" @click="close">Cancel</Button>
            <Button v-if="!preview" :disabled="!form.file || form.processing" @click="upload">
                <Icon name="documentText" size="sm" />
                {{ form.processing ? 'Reading…' : 'Read file' }}
            </Button>
            <Button v-else :disabled="!chosen.length || applying" @click="apply">
                <Icon name="check" size="sm" />
                {{ applying ? 'Importing…' : `Import ${chosen.length} ${chosen.length === 1 ? 'section' : 'sections'}` }}
            </Button>
        </template>
    </Modal>
</template>
