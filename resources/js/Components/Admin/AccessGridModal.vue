<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import Icon from '../UI/Icon.vue';
import Spinner from '../UI/Spinner.vue';
import UserAvatar from '../UI/UserAvatar.vue';
import { confirm } from '../../Composables/useDialog';

/**
 * The legacy access-assignment modal, shared by System Access (rows are
 * users, one system) and User Access (rows are systems, one user). Each
 * row has a role picker; changes are collected and saved together, with
 * a pending count like the legacy "unsaved changes" badge.
 *
 * Rows: [{ key, title, subtitle?, photoUrl?, avatar?, roleId, roles: [{id, name}], locked?: 'self'|'protected'|'inactive' }]
 * Emits `save` with [{ key, roleId|null }] for changed rows only.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    title: { type: String, required: true },
    subject: { type: String, default: null },
    rows: { type: Array, default: null },
    loading: { type: Boolean, default: false },
    saving: { type: Boolean, default: false },
    rowLabel: { type: String, default: 'Name' },
    errors: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['close', 'save']);

const draft = ref({});
const search = ref('');
const show = ref('all');
const bulkRole = ref('');

watch(
    () => props.rows,
    (rows) => {
        draft.value = Object.fromEntries((rows ?? []).map((row) => [row.key, row.roleId ?? '']));
    },
    { immediate: true },
);

const lockText = {
    self: "You can't change your own access",
    protected: 'Super Administrator — protected',
    inactive: 'Inactive account — access can only be removed',
};

const changed = (row) => String(draft.value[row.key] ?? '') !== String(row.roleId ?? '');
const pending = computed(() => (props.rows ?? []).filter(changed));

const visibleRows = computed(() => {
    const term = search.value.trim().toLowerCase();

    return (props.rows ?? []).filter((row) => {
        const matches = !term || [row.title, row.subtitle].some((text) => (text ?? '').toLowerCase().includes(term));
        const value = draft.value[row.key];
        const filter = { all: true, granted: !!value, none: !value, changed: changed(row) }[show.value];

        return matches && filter;
    });
});

const optionsFor = (row) => {
    // Inactive accounts may only lose access (or keep what they have).
    const roles = row.locked === 'inactive' ? row.roles.filter((role) => role.id === row.roleId) : row.roles;

    return [{ id: '', name: 'No access' }, ...roles];
};

const isLocked = (row) => row.locked === 'self' || row.locked === 'protected';

const applyBulk = () => {
    visibleRows.value
        .filter((row) => !isLocked(row) && (bulkRole.value === '' || (row.locked !== 'inactive' && row.roles.some((role) => String(role.id) === String(bulkRole.value)))))
        .forEach((row) => (draft.value[row.key] = bulkRole.value === '' ? '' : Number(bulkRole.value)));
};

// Bulk targets: roles shared by every row (one system → all rows share them).
const bulkOptions = computed(() => {
    const first = props.rows?.[0]?.roles ?? [];

    return first.filter((role) => (props.rows ?? []).every((row) => row.roles.some((candidate) => candidate.id === role.id)));
});

const undo = () => {
    draft.value = Object.fromEntries((props.rows ?? []).map((row) => [row.key, row.roleId ?? '']));
};

const save = () => {
    emit('save', pending.value.map((row) => ({ key: row.key, roleId: draft.value[row.key] === '' ? null : Number(draft.value[row.key]) })));
};

const close = async () => {
    if (pending.value.length && !(await confirm({ title: 'Discard unsaved changes?', text: `${pending.value.length} change(s) haven't been saved.`, tone: 'danger', confirmText: 'Discard' }))) {
        return;
    }
    emit('close');
};

const errorFor = (index) => props.errors[`entries.${index}.application_role_id`] ?? props.errors[`access.${index}.application_role_id`];
const rowErrors = computed(() => {
    // Map server errors (by position in the submitted list) back to rows.
    const map = {};
    pending.value.forEach((row, index) => {
        const message = errorFor(index);
        if (message) map[row.key] = message;
    });

    return map;
});
</script>

<template>
    <Modal :open="open" :title="title" tone="primary" icon="key" size="xl" @close="close">
        <template #summary>
            <div class="flex flex-wrap items-center gap-3">
                <div class="relative min-w-56 flex-1">
                    <label for="grid-search" class="sr-only">Search</label>
                    <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-text-muted" />
                    <input
                        id="grid-search"
                        v-model="search"
                        type="search"
                        :placeholder="`Search ${rowLabel.toLowerCase()}…`"
                        class="block w-full rounded-md border border-border-strong bg-surface py-1.5 pr-3 pl-8 text-sm focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                    />
                </div>
                <label for="grid-show" class="sr-only">Show</label>
                <select id="grid-show" v-model="show" class="rounded-md border border-border-strong bg-surface px-2 py-1.5 text-sm">
                    <option value="all">All</option>
                    <option value="granted">With access</option>
                    <option value="none">No access</option>
                    <option value="changed">Changed</option>
                </select>
                <div v-if="bulkOptions.length" class="flex items-center gap-1">
                    <label for="grid-bulk" class="sr-only">Set every visible row to</label>
                    <select id="grid-bulk" v-model="bulkRole" class="rounded-md border border-border-strong bg-surface px-2 py-1.5 text-sm">
                        <option value="">No access</option>
                        <option v-for="role in bulkOptions" :key="role.id" :value="role.id">{{ role.name }}</option>
                    </select>
                    <Button variant="secondary" @click="applyBulk">Apply to visible</Button>
                </div>
            </div>
        </template>

        <div v-if="loading || rows === null" class="flex items-center justify-center gap-2 py-16 text-sm text-text-muted">
            <Spinner />
            Loading…
        </div>

        <div v-else class="overflow-x-auto rounded-md border border-border">
            <table class="min-w-full text-[0.8125rem]">
                <caption class="sr-only">{{ title }}</caption>
                <thead class="sticky top-0 table-head">
                    <tr>
                        <th scope="col" class="px-3 py-2">{{ rowLabel }}</th>
                        <th scope="col" class="w-56 px-3 py-2 text-center">Assigned role</th>
                        <th scope="col" class="w-44 px-3 py-2 text-center">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-if="!visibleRows.length">
                        <td colspan="3" class="px-3 py-8 text-center text-text-muted">Nothing matches.</td>
                    </tr>
                    <tr v-for="row in visibleRows" :key="row.key" :class="changed(row) ? 'bg-warning-bg/50' : ''">
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-3">
                                <UserAvatar v-if="row.avatar" :name="row.title" :photo-url="row.photoUrl" size="sm" />
                                <span v-else class="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                                    <Icon name="grid" size="sm" />
                                </span>
                                <div class="min-w-0">
                                    <div class="truncate font-medium text-text">{{ row.title }}</div>
                                    <div v-if="row.subtitle" class="truncate text-xs text-text-muted">{{ row.subtitle }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-center">
                            <label :for="`grid-role-${row.key}`" class="sr-only">Role for {{ row.title }}</label>
                            <select
                                :id="`grid-role-${row.key}`"
                                v-model="draft[row.key]"
                                :disabled="isLocked(row)"
                                class="w-full rounded-md border border-border-strong bg-surface px-2 py-1 text-sm disabled:cursor-not-allowed disabled:bg-surface-muted disabled:text-text-muted"
                                :aria-invalid="!!rowErrors[row.key]"
                            >
                                <option v-for="role in optionsFor(row)" :key="role.id" :value="role.id">{{ role.name }}</option>
                            </select>
                            <p v-if="rowErrors[row.key]" class="mt-1 text-left text-xs text-danger">{{ rowErrors[row.key] }}</p>
                        </td>
                        <td class="px-3 py-2 text-center text-xs">
                            <span v-if="row.locked && row.locked !== 'inactive'" class="inline-flex items-center gap-1 text-text-muted">
                                <Icon name="lock" size="sm" /> {{ lockText[row.locked] }}
                            </span>
                            <span v-else-if="changed(row)" class="font-semibold text-warning">Changed — not saved</span>
                            <span v-else-if="draft[row.key]" class="font-semibold text-success">Granted</span>
                            <span v-else class="text-text-muted">No access</span>
                            <span v-if="row.locked === 'inactive'" class="block text-text-muted">{{ lockText.inactive }}</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <template #footer>
            <span v-if="pending.length" class="mr-auto self-center rounded-full bg-warning-bg px-3 py-1 text-xs font-semibold text-warning">
                {{ pending.length }} unsaved {{ pending.length === 1 ? 'change' : 'changes' }}
            </span>
            <Button v-if="pending.length" variant="ghost" @click="undo">Undo</Button>
            <Button variant="secondary" @click="close">Close</Button>
            <Button variant="success" :loading="saving" :disabled="!pending.length" @click="save">Save changes</Button>
        </template>
    </Modal>
</template>
