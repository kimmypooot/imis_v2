<script setup>
import InputLabel from '../../../../Components/Forms/InputLabel.vue';
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import FileInput from '../../../../Components/Forms/FileInput.vue';
import InputError from '../../../../Components/Forms/InputError.vue';

defineOptions({ layout: AdminLayout });

/**
 * HR-4: completing the plantilla from a spreadsheet. Download the template
 * (every active item with what's on record), fill in the blanks in Excel,
 * save as CSV and upload it; every row is checked and shown here before
 * anything is saved. A blank cell never clears a value.
 */
const props = defineProps({
    upload: { type: Object, default: null },
    rows: { type: Array, default: null },
    summary: { type: Object, default: null },
});

const form = useForm({ file: null });
const send = () => form.post('/admin/hr/plantilla-sheet', { forceFormData: true, onSuccess: () => form.reset() });

const show = ref('changes');
const shown = computed(() => {
    if (!props.rows) return [];
    if (show.value === 'changes') return props.rows.filter((row) => row.changes.length || row.errors.length);
    if (show.value === 'errors') return props.rows.filter((row) => row.errors.length);

    return props.rows;
});

const applying = ref(false);
const apply = () => router.post('/admin/hr/plantilla-sheet/apply', {}, { onStart: () => (applying.value = true), onFinish: () => (applying.value = false) });
const discard = () => router.delete('/admin/hr/plantilla-sheet');
</script>

<template>
    <Head title="Complete the plantilla" />

    <PageHeader
        title="Complete the plantilla from a spreadsheet"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Plantilla', href: '/admin/hr/plantilla' }, { label: 'Spreadsheet' }]"
    />

    <div class="space-y-6">
        <Card title="1. Download, fill in, upload" icon="download">
            <ol class="list-decimal space-y-1 pl-5 text-sm text-text">
                <li>
                    <a href="/admin/hr/plantilla-sheet/template" class="font-medium text-primary hover:underline">Download the template</a>: every active item, with what's on
                    record now.
                </li>
                <li>
                    Fill in the blanks in Excel: item number, salary grade, place of assignment, and the holder's step, status (Permanent, Temporary, Coterminous),
                    nature of appointment (e.g. Original, Promotion) and start date (YYYY-MM-DD).
                </li>
                <li>Don't change the Item ID column. A blank cell leaves the value as it is. Grades already set on the Positions list come from there.</li>
                <li>Save it as <strong>CSV (UTF-8)</strong> and upload it below. Nothing is saved until you review the changes and apply them.</li>
            </ol>

            <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="send">
                <div class="min-w-64 flex-1">
                    <InputLabel for="sheet-file">Filled-in spreadsheet (CSV)</InputLabel>
                    <FileInput id="sheet-file" class="mt-1" accept=".csv,text/csv" required :invalid="!!form.errors.file" @update:model-value="(file) => (form.file = file)" />
                    <InputError :message="form.errors.file" />
                </div>
                <Button type="submit" :loading="form.processing" :disabled="!form.file"><Icon name="checkCircle" size="sm" />Check the file</Button>
            </form>
        </Card>

        <Card v-if="upload && summary" :title="`2. Review: ${upload.name}`" icon="clipboard">
            <template #actions>
                <div class="flex flex-wrap gap-1" role="group" aria-label="Show">
                    <button
                        v-for="option in [{ key: 'changes', label: 'Changes and errors' }, { key: 'errors', label: 'Errors only' }, { key: 'all', label: 'All rows' }]"
                        :key="option.key"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="show === option.key ? 'bg-primary text-white' : 'text-text hover:bg-surface-muted'"
                        :aria-pressed="show === option.key"
                        @click="show = option.key"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </template>

            <p class="text-sm text-text">
                {{ summary.rows }} rows: <strong>{{ summary.ready }}</strong> ready to apply,
                <strong :class="summary.errors ? 'text-danger' : ''">{{ summary.errors }}</strong> with errors (skipped), {{ summary.unchanged }} unchanged.
            </p>

            <div class="table-card mt-4">
                <table class="data-table">
                    <caption class="sr-only">Rows of the uploaded spreadsheet</caption>
                    <thead>
                        <tr>
                            <th scope="col" class="font-medium">Line</th>
                            <th scope="col" class="font-medium">Item</th>
                            <th scope="col" class="font-medium">Holder</th>
                            <th scope="col" class="font-medium">Changes or errors</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in shown" :key="row.line">
                            <td class="align-top tabular-nums text-text-muted">{{ row.line }}</td>
                            <td class="align-top">
                                <a v-if="row.item_id" :href="`/admin/hr/plantilla/${row.item_id}`" class="font-medium text-primary hover:underline">{{ row.item }}</a>
                                <span v-else>{{ row.item }}</span>
                                <div v-if="row.position_title" class="text-xs text-text-muted">{{ row.position_title }}</div>
                            </td>
                            <td class="align-top">{{ row.holder || 'Vacant' }}</td>
                            <td class="align-top">
                                <ul v-if="row.errors.length" class="space-y-0.5 text-danger">
                                    <li v-for="error in row.errors" :key="error">{{ error }}</li>
                                </ul>
                                <ul v-else-if="row.changes.length" class="space-y-0.5">
                                    <li v-for="change in row.changes" :key="change.field">
                                        <span class="text-text-muted">{{ change.field }}:</span> {{ change.from }} → <strong>{{ change.to }}</strong>
                                    </li>
                                </ul>
                                <span v-else class="text-text-muted">No change</span>
                            </td>
                        </tr>
                        <tr v-if="!shown.length">
                            <td colspan="4" class="py-4 text-center text-text-muted">Nothing to show here.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="mt-4 flex flex-wrap justify-end gap-2">
                <Button variant="secondary" @click="discard">Discard</Button>
                <Button variant="success" :disabled="!summary.ready" :loading="applying" @click="apply">
                    Apply {{ summary.ready }} {{ summary.ready === 1 ? 'row' : 'rows' }}
                </Button>
            </div>
        </Card>
    </div>
</template>
