<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import Select from '../Forms/Select.vue';
import TextInput from '../Forms/TextInput.vue';
import Textarea from '../Forms/Textarea.vue';
import InputError from '../Forms/InputError.vue';

/**
 * HR-1b: record or correct one movement (reassignment, detail,
 * designation, return to mother unit), with its order and an optional
 * scan (filed in the 201 file as an Office Order). Edits go as POST +
 * _method=PUT so a scan can ride along. `entry` null = recording.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    entry: { type: Object, default: null },
    url: { type: String, required: true /** collection URL; entry id is appended for edits */ },
    currentOfficeId: { type: Number, default: null },
    kindOptions: { type: Array, required: true },
    officeOptions: { type: Array, required: true },
    scanKilobytes: { type: Number, required: true },
});

const emit = defineEmits(['close']);

const blank = {
    kind: 'reassignment', from_unit_id: '', to_unit_id: '', effective_date: '', end_date: '',
    order_number: '', order_date: '', remarks: '', scan: null,
};

const form = useForm({ ...blank });
const fileInput = ref(null);
const editing = computed(() => props.entry !== null);
const hasEndDate = computed(() => ['detail', 'designation'].includes(form.kind));

watch(
    () => [props.open, props.entry?.id],
    ([open]) => {
        if (!open) return;
        const source = props.entry ?? { from_unit_id: props.currentOfficeId };
        form.defaults(Object.fromEntries(Object.keys(blank).map((key) => [key, key === 'scan' ? null : (source[key] ?? blank[key])])));
        form.reset();
        form.clearErrors();
        if (fileInput.value) fileInput.value.value = '';
    },
);

// A return goes back to the mother unit: the office they're in now.
watch(
    () => form.kind,
    (kind) => {
        if (kind === 'return' && !editing.value && props.currentOfficeId) form.to_unit_id = props.currentOfficeId;
    },
);

const hint = computed(
    () =>
        ({
            reassignment: "Moves the person's office to the new one when it takes effect (today, or on a future date).",
            detail: 'Temporary. The person stays under their mother unit; a later "Return to mother unit" ends it.',
            designation: "Recorded on the timeline only; the person's office doesn't change.",
            return: 'Ends any detail still running on this date.',
        })[form.kind],
);

const submit = () => {
    const options = { preserveScroll: true, forceFormData: true, onSuccess: () => emit('close') };
    const data = (values) => ({ ...values, end_date: hasEndDate.value ? values.end_date : '', ...(editing.value ? { _method: 'put' } : {}) });

    form.transform(data).post(editing.value ? `${props.url}/${props.entry.id}` : props.url, options);
};

const field = 'mt-1 block w-full';
</script>

<template>
    <Modal
        :open="open"
        :title="editing ? 'Correct movement' : 'Record movement'"
        :icon="editing ? 'pencil' : 'plus'"
        :tone="editing ? 'primary' : 'success'"
        size="lg"
        @close="emit('close')"
    >
        <form id="assignment-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <div class="sm:col-span-2">
                <label for="assignment-kind" class="block text-sm font-medium text-text">Kind <span class="text-danger" aria-hidden="true">*</span></label>
                <Select id="assignment-kind" v-model="form.kind" :class="field" :options="kindOptions" required :invalid="!!form.errors.kind" aria-describedby="assignment-kind-hint" />
                <p id="assignment-kind-hint" class="mt-1 text-xs text-text-muted">{{ hint }}</p>
                <InputError :message="form.errors.kind" />
            </div>

            <div v-if="form.kind !== 'designation'">
                <label for="assignment-from" class="block text-sm font-medium text-text">From office</label>
                <Select id="assignment-from" v-model="form.from_unit_id" :class="field" :options="officeOptions" placeholder="— Current office —" :invalid="!!form.errors.from_unit_id" />
                <InputError :message="form.errors.from_unit_id" />
            </div>
            <div :class="form.kind === 'designation' ? 'sm:col-span-2' : ''">
                <label for="assignment-to" class="block text-sm font-medium text-text">
                    {{ form.kind === 'designation' ? 'Designated to' : form.kind === 'return' ? 'Mother unit' : 'To office' }}
                    <span class="text-danger" aria-hidden="true">*</span>
                </label>
                <Select id="assignment-to" v-model="form.to_unit_id" :class="field" :options="officeOptions" placeholder="— Select —" required :invalid="!!form.errors.to_unit_id" />
                <InputError :message="form.errors.to_unit_id" />
            </div>

            <div>
                <TextInput id="assignment-effective" v-model="form.effective_date" type="date" label="Effective date" required :invalid="!!form.errors.effective_date" />
                <InputError :message="form.errors.effective_date" />
            </div>
            <div v-if="hasEndDate">
                <TextInput id="assignment-end" v-model="form.end_date" type="date" label="End date (if known)" :invalid="!!form.errors.end_date" />
                <InputError :message="form.errors.end_date" />
            </div>

            <div>
                <TextInput id="assignment-order-number" v-model="form.order_number" label="Office/Special Order No." :invalid="!!form.errors.order_number" />
                <InputError :message="form.errors.order_number" />
            </div>
            <div>
                <TextInput id="assignment-order-date" v-model="form.order_date" type="date" label="Order date" :invalid="!!form.errors.order_date" />
                <InputError :message="form.errors.order_date" />
            </div>

            <div class="sm:col-span-2">
                <label for="assignment-remarks" class="block text-sm font-medium text-text">Remarks</label>
                <Textarea id="assignment-remarks" v-model="form.remarks" class="mt-1" :invalid="!!form.errors.remarks" />
                <InputError :message="form.errors.remarks" />
            </div>

            <div class="sm:col-span-2">
                <label for="assignment-scan" class="block text-sm font-medium text-text">Scanned order (optional)</label>
                <p v-if="entry?.has_scan" class="mt-1 text-sm text-text">A scan is already filed. Choosing a file adds the new one to the 201 file and links it here.</p>
                <input
                    id="assignment-scan"
                    ref="fileInput"
                    type="file"
                    accept="application/pdf,image/jpeg,image/png"
                    aria-describedby="assignment-scan-help"
                    class="mt-1 block w-full text-sm text-text file:mr-3 file:rounded-md file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-sm file:font-medium hover:file:bg-surface-muted"
                    @change="form.scan = $event.target.files[0] ?? null"
                />
                <p id="assignment-scan-help" class="mt-1 text-xs text-text-muted">
                    PDF, JPG or PNG, up to {{ Math.round(scanKilobytes / 1024) }} MB. Filed in the 201 file as an Office Order.
                </p>
                <InputError :message="form.errors.scan" />
            </div>
        </form>

        <template #footer>
            <Button variant="secondary" @click="emit('close')">Cancel</Button>
            <Button type="submit" form="assignment-form" :variant="editing ? 'primary' : 'success'" :loading="form.processing">
                {{ editing ? 'Save changes' : 'Record movement' }}
            </Button>
        </template>
    </Modal>
</template>
