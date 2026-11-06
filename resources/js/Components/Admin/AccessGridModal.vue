<script setup>
import Badge from '../UI/Badge.vue';
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
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
    // Inactive accounts left out of the rows (they show only on the Users page).
    hiddenInactive: { type: Number, default: 0 },
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

// When rows carry a `group` (e.g. the person's unit), the grid is ordered by
// `groupRank`, then highest `grade` first, with a full-width (colspan)
// heading row at the start of each group.
const grouped = computed(() => visibleRows.value.some((row) => row.group));

const tableRows = computed(() => {
    if (!grouped.value) return visibleRows.value.map((row) => ({ row, heading: null }));

    const sorted = [...visibleRows.value].sort(
        (a, b) =>
            (a.groupRank ?? '9').localeCompare(b.groupRank ?? '9') ||
            (a.group ?? '').localeCompare(b.group ?? '') ||
            (b.grade ?? 0) - (a.grade ?? 0),
    );

    return sorted.map((row, index) => ({ row, heading: sorted[index - 1]?.group === row.group ? null : row.group }));
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
                        class="form-control block w-full py-1.5 pr-3 pl-8"
                    />
                </div>
                <label for="grid-show" class="sr-only">Show</label>
                <select id="grid-show" v-model="show" class="form-control px-2 py-1.5">
                    <option value="all">All</option>
                    <option value="granted">With access</option>
                    <option value="none">No access</option>
                    <option value="changed">Changed</option>
                </select>
                <div v-if="bulkOptions.length" class="flex items-center gap-1">
                    <label for="grid-bulk" class="sr-only">Set every visible row to</label>
                    <select id="grid-bulk" v-model="bulkRole" class="form-control px-2 py-1.5">
                        <option value="">No access</option>
                        <option v-for="role in bulkOptions" :key="role.id" :value="role.id">{{ role.name }}</option>
                    </select>
                    <Button variant="secondary" @click="applyBulk">Apply to visible</Button>
                </div>
            </div>
            <p v-if="hiddenInactive" class="mt-2 text-xs text-text-muted">
                {{ hiddenInactive }} inactive account{{ hiddenInactive === 1 ? ' is' : 's are' }} not listed.
                <Link href="/users?status=inactive" class="font-medium text-primary hover:underline">View inactive accounts</Link>
            </p>
        </template>

        <div v-if="loading || rows === null" class="flex items-center justify-center gap-2 py-16 text-sm text-text-muted">
            <Spinner />
            Loading…
        </div>

        <div v-else class="overflow-x-auto rounded-md border border-border">
            <table class="min-w-full text-compact">
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
                    <template v-for="{ row, heading } in tableRows" :key="row.key">
                    <tr v-if="heading">
                        <th colspan="3" scope="colgroup" class="bg-surface-muted px-3 py-2 text-left text-xs font-semibold tracking-wide text-text uppercase">{{ heading }}</th>
                    </tr>
                    <tr :class="changed(row) ? 'bg-warning-bg/50' : ''">
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
                                class="form-control w-full px-2 py-1"
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
                    </template>
                </tbody>
            </table>
        </div>

        <template #footer>
            <Badge v-if="pending.length" pill tone="warning" class="mr-auto self-center">
                {{ pending.length }} unsaved {{ pending.length === 1 ? 'change' : 'changes' }}
            </Badge>
            <Button v-if="pending.length" variant="ghost" @click="undo">Undo</Button>
            <Button variant="secondary" @click="close">Close</Button>
            <Button variant="success" :loading="saving" :disabled="!pending.length" @click="save">Save changes</Button>
        </template>
    </Modal>
</template>
