<script setup>
import { computed, ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import InputError from '../Forms/InputError.vue';

/**
 * Set up employee records for many existing accounts at once. Everyone
 * starts ticked; HR unticks accounts that aren't staff (duplicates, test
 * or service accounts). Records start with employment status "Not set".
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    candidates: { type: Array, required: true /** [{ id, name, username, position, unit }] */ },
});

const emit = defineEmits(['close']);

const search = ref('');
const form = useForm({ user_ids: [] });

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        search.value = '';
        form.clearErrors();
        form.user_ids = props.candidates.map((candidate) => candidate.id);
    },
);

const visible = computed(() => {
    const term = search.value.trim().toLowerCase();
    return term
        ? props.candidates.filter((c) => [c.name, c.username, c.position, c.unit].some((text) => (text ?? '').toLowerCase().includes(term)))
        : props.candidates;
});

const selectAll = (checked) => {
    const ids = visible.value.map((candidate) => candidate.id);
    form.user_ids = checked ? [...new Set([...form.user_ids, ...ids])] : form.user_ids.filter((id) => !ids.includes(id));
};

const allVisibleSelected = computed(() => visible.value.length > 0 && visible.value.every((candidate) => form.user_ids.includes(candidate.id)));

const submit = () => form.post('/admin/hr/employees/bulk', { preserveScroll: true, onSuccess: () => emit('close') });

const firstError = computed(() => form.errors.user_ids ?? Object.entries(form.errors).find(([key]) => key.startsWith('user_ids.'))?.[1]);
</script>

<template>
    <Modal :open="open" title="Set up employee records" icon="users" tone="success" size="lg" @close="emit('close')">
        <p class="text-sm text-text">
            These active accounts have no employee record yet. Untick anyone who isn't a staff member (duplicate, test or service accounts).
            Records start with employment status <strong>Not set</strong> — fill it in from each record afterwards.
        </p>

        <div class="mt-4 flex flex-wrap items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm font-medium text-text">
                <input
                    type="checkbox"
                    :checked="allVisibleSelected"
                    class="h-4 w-4 rounded-sm border-border-strong text-primary focus:ring-2 focus:ring-focus-ring"
                    @change="selectAll($event.target.checked)"
                />
                Select all{{ search ? ' shown' : '' }}
            </label>
            <label class="sr-only" for="bulk-setup-search">Search accounts</label>
            <input
                id="bulk-setup-search"
                v-model="search"
                type="search"
                placeholder="Search name, office, position…"
                class="form-control w-full px-3 py-1.5 sm:w-64"
            />
        </div>

        <ul class="mt-3 max-h-80 divide-y divide-border overflow-y-auto rounded-md border border-border">
            <li v-for="candidate in visible" :key="candidate.id">
                <label class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-surface-muted">
                    <input
                        v-model="form.user_ids"
                        type="checkbox"
                        :value="candidate.id"
                        class="h-4 w-4 rounded-sm border-border-strong text-primary focus:ring-2 focus:ring-focus-ring"
                    />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-text">{{ candidate.name }} <span class="font-normal text-text-muted">({{ candidate.username }})</span></span>
                        <span class="block truncate text-xs text-text-muted">{{ [candidate.position, candidate.unit].filter(Boolean).join(' · ') || '—' }}</span>
                    </span>
                </label>
            </li>
            <li v-if="visible.length === 0" class="px-3 py-4 text-center text-sm text-text-muted">No accounts match.</li>
        </ul>

        <InputError :message="firstError" />

        <template #footer>
            <span class="mr-auto text-sm text-text-muted" role="status">{{ form.user_ids.length }} of {{ candidates.length }} selected</span>
            <Button variant="secondary" @click="emit('close')">Cancel</Button>
            <Button variant="success" :loading="form.processing" :disabled="form.user_ids.length === 0" @click="submit">
                Set up {{ form.user_ids.length }} {{ form.user_ids.length === 1 ? 'record' : 'records' }}
            </Button>
        </template>
    </Modal>
</template>
