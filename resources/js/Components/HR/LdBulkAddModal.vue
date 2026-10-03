<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import InputError from '../Forms/InputError.vue';
import LdTrainingFields from './LdTrainingFields.vue';

/**
 * HR-3b bulk add (ldms's "Add Training for Multiple Employees"): one
 * training for everyone who attended. Records are approved at once;
 * anyone who already has it is skipped and named in the result.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    employees: { type: Array, required: true /** [{ id, name, position, unit }] */ },
    typeOptions: { type: Array, required: true },
    modalityOptions: { type: Array, required: true },
});

const emit = defineEmits(['close']);

const blank = {
    title: '', date_from: '', date_to: '', hours: '', ld_type: '', ld_type_other: '', modality: '',
    sponsor: '', venue: '', resource_speaker: '', employee_ids: [],
};

const form = useForm({ ...blank });
const search = ref('');

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        search.value = '';
        form.defaults({ ...blank, employee_ids: [] });
        form.reset();
        form.clearErrors();
    },
);

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();
    return term ? props.employees.filter((e) => [e.name, e.position, e.unit].some((text) => (text ?? '').toLowerCase().includes(term))) : props.employees;
});

const allVisibleSelected = computed(() => visible.value.length > 0 && visible.value.every((employee) => form.employee_ids.includes(employee.id)));

const selectAll = (checked) => {
    const ids = visible.value.map((employee) => employee.id);
    form.employee_ids = checked ? [...new Set([...form.employee_ids, ...ids])] : form.employee_ids.filter((id) => !ids.includes(id));
};

const submit = () => form.post('/admin/hr/learning-development/bulk', { preserveScroll: true, onSuccess: () => emit('close') });

const peopleError = computed(() => form.errors.employee_ids ?? Object.entries(form.errors).find(([key]) => key.startsWith('employee_ids.'))?.[1]);
</script>

<template>
    <Modal :open="open" title="Add a training for several employees" icon="users" tone="success" size="xl" @close="emit('close')">
        <form id="ld-bulk-form" class="grid gap-6 lg:grid-cols-2" @submit.prevent="submit">
            <fieldset class="grid content-start gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-semibold text-text sm:col-span-2">Training</legend>
                <LdTrainingFields :form="form" :type-options="typeOptions" :modality-options="modalityOptions" id-prefix="ld-bulk" />
            </fieldset>

            <fieldset>
                <legend class="mb-2 text-sm font-semibold text-text">Who attended</legend>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm font-medium text-text">
                        <input
                            type="checkbox"
                            :checked="allVisibleSelected"
                            class="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-focus-ring"
                            @change="selectAll($event.target.checked)"
                        />
                        Select all{{ search ? ' shown' : '' }}
                    </label>
                    <label class="sr-only" for="ld-bulk-search">Search employees</label>
                    <input
                        id="ld-bulk-search"
                        v-model="search"
                        type="search"
                        placeholder="Search name, office, position…"
                        class="w-full rounded-md border border-border bg-surface px-3 py-1.5 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring sm:w-64"
                    />
                </div>

                <ul class="mt-3 max-h-96 divide-y divide-border overflow-y-auto rounded-md border border-border">
                    <li v-for="employee in visible" :key="employee.id">
                        <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-surface-muted">
                            <input
                                v-model="form.employee_ids"
                                type="checkbox"
                                :value="employee.id"
                                class="h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-focus-ring"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-medium text-text">{{ employee.name }}</span>
                                <span class="block truncate text-xs text-text-muted">{{ [employee.position, employee.unit].filter(Boolean).join(' · ') || '—' }}</span>
                            </span>
                        </label>
                    </li>
                    <li v-if="visible.length === 0" class="px-3 py-4 text-center text-sm text-text-muted">No employees match.</li>
                </ul>
                <InputError :message="peopleError" />
                <p class="mt-2 text-xs text-text-muted">Anyone who already has this training (same title and start date) is skipped.</p>
            </fieldset>
        </form>

        <template #footer>
            <span class="mr-auto text-sm text-text-muted" role="status">{{ form.employee_ids.length }} of {{ employees.length }} selected</span>
            <Button variant="secondary" @click="emit('close')">Cancel</Button>
            <Button type="submit" form="ld-bulk-form" variant="success" :loading="form.processing" :disabled="form.employee_ids.length === 0">
                Add to {{ form.employee_ids.length }} {{ form.employee_ids.length === 1 ? 'employee' : 'employees' }}
            </Button>
        </template>
    </Modal>
</template>
