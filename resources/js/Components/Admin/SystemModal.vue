<script setup>
import { computed, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Alert from '../Feedback/Alert.vue';
import Button from '../UI/Button.vue';
import Tabs from '../UI/Tabs.vue';
import Icon from '../UI/Icon.vue';
import StatusBadge from '../UI/StatusBadge.vue';
import ActionButton from '../UI/ActionButton.vue';
import TextInput from '../Forms/TextInput.vue';
import Textarea from '../Forms/Textarea.vue';
import InputError from '../Forms/InputError.vue';
import FormGroup from '../Forms/FormGroup.vue';
import Select from '../Forms/Select.vue';
import Checkbox from '../Forms/Checkbox.vue';
import CheckboxGroup from '../Forms/CheckboxGroup.vue';
import { useAuthorization } from '../../Composables/useAuthorization';
import { confirm } from '../../Composables/useDialog';
import { formatDateTime } from '../../Utils/datetime';

/**
 * The legacy "Manage Projects" modal (extra-large) for adding or editing
 * a system, with its roles editor. Tabs: Details · Dashboard listing ·
 * Roles · Maintenance (the last two once the system exists). Details and
 * listing save together; roles and maintenance save as you go, each
 * through its own permission-checked endpoint.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    system: { type: Object, default: null },
    options: { type: Object, required: true /** statusOptions, visibilityOptions, organizationalUnitOptions, committeeOptions */ },
});

const emit = defineEmits(['close']);

const { can } = useAuthorization();
const isNew = computed(() => props.system === null);

const tab = ref('details');
const tabs = computed(() => [
    { key: 'details', label: 'Details', icon: 'grid' },
    { key: 'listing', label: 'Dashboard listing', icon: 'building' },
    ...(isNew.value ? [] : [{ key: 'roles', label: 'Roles', icon: 'key', badge: props.system.roles.length || null }]),
    ...(isNew.value || !can('applications.maintenance') ? [] : [{ key: 'maintenance', label: 'Maintenance', icon: 'exclamation' }]),
]);

const fields = (system) => ({
    name: system?.name ?? '',
    short_name: system?.short_name ?? '',
    description: system?.description ?? '',
    icon: system?.icon ?? '',
    url: system?.url ?? '',
    category: system?.category ?? '',
    organizational_unit_id: system?.organizational_unit_id ?? '',
    status: system?.status ?? 'active',
    visibility: system?.visibility ?? 'authenticated',
    sort_order: system?.sort_order ?? 0,
    external: system?.external ?? true,
    featured: system?.featured ?? false,
    requires_access: system?.requires_access ?? false,
    listed_unit_ids: [...(system?.listed_unit_ids ?? [])],
    listed_committee_ids: [...(system?.listed_committee_ids ?? [])],
});

const form = useForm(fields(props.system));
const maintenanceForm = useForm({ under_maintenance: true, maintenance_message: '' });
const roleForm = useForm({ name: '', code: '', description: '', launch_url: '', sort_order: 0 });
const editingRoleId = ref(null);

watch(
    () => [props.open, props.system?.id],
    ([open]) => {
        if (!open) return;
        form.defaults(fields(props.system));
        form.reset();
        form.clearErrors();
        maintenanceForm.maintenance_message = props.system?.maintenance_message ?? '';
        cancelRoleEdit();
        tab.value = 'details';
    },
    { immediate: true },
);

const close = () => emit('close');

const save = () => {
    const options = { preserveScroll: true, onSuccess: () => (isNew.value ? close() : form.defaults()) };
    form.transform((data) => ({ ...data, organizational_unit_id: data.organizational_unit_id || null }));

    if (isNew.value) form.post('/admin/applications', options);
    else form.put(`/admin/applications/${props.system.id}`, options);
};

// ── Roles (legacy role rows) ─────────────────────────────────────────

function cancelRoleEdit() {
    editingRoleId.value = null;
    roleForm.reset();
    roleForm.clearErrors();
}

const editRole = (role) => {
    editingRoleId.value = role.id;
    roleForm.clearErrors();
    Object.assign(roleForm, { name: role.name, code: role.code ?? '', description: role.description ?? '', launch_url: role.launch_url ?? '', sort_order: role.sort_order });
};

const saveRole = () => {
    const base = `/admin/applications/${props.system.id}/roles`;
    const options = { preserveScroll: true, onSuccess: cancelRoleEdit };

    if (editingRoleId.value) roleForm.put(`${base}/${editingRoleId.value}`, options);
    else roleForm.post(base, options);
};

const toggleRole = async (role) => {
    const deactivating = role.status === 'active';
    if (deactivating && role.users_count > 0) {
        await confirm({
            title: 'Role still in use',
            text: `${role.users_count} ${role.users_count === 1 ? 'person holds' : 'people hold'} "${role.name}". Move them to another role under System Access first.`,
            icon: 'warning',
            confirmText: 'OK',
        });

        return;
    }

    const confirmed = await confirm({
        title: deactivating ? `Deactivate "${role.name}"?` : `Activate "${role.name}"?`,
        text: deactivating ? 'It will no longer be offered when assigning access.' : 'It can be assigned again.',
        tone: deactivating ? 'danger' : 'primary',
        confirmText: deactivating ? 'Deactivate' : 'Activate',
    });

    if (confirmed) {
        router.patch(`/admin/applications/${props.system.id}/roles/${role.id}/status`, { status: deactivating ? 'inactive' : 'active' }, { preserveScroll: true });
    }
};

// ── Maintenance ──────────────────────────────────────────────────────
const saveMaintenance = (underMaintenance) => {
    maintenanceForm.under_maintenance = underMaintenance;
    maintenanceForm.patch(`/admin/applications/${props.system.id}/maintenance`, { preserveScroll: true });
};

const endMaintenance = async () => {
    const confirmed = await confirm({
        title: 'End maintenance?',
        text: `${props.system.name} will be launchable again and its notice removed.`,
        confirmText: 'End maintenance',
    });

    if (confirmed) saveMaintenance(false);
};

const canSaveDetails = computed(() => (isNew.value ? can('applications.create') : can('applications.update')));
</script>

<template>
    <Modal
        :open="open"
        :title="isNew ? 'Add New System' : `Edit System — ${system.name}`"
        :tone="isNew ? 'success' : 'primary'"
        :icon="isNew ? 'plus' : 'pencil'"
        size="xl"
        @close="close"
    >
        <template v-if="!isNew" #summary>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <span class="font-semibold text-text">{{ system.name }}</span>
                <span v-if="system.short_name" class="text-text-muted">{{ system.short_name }}</span>
                <StatusBadge :status="system.status" />
                <StatusBadge v-if="system.under_maintenance" status="maintenance" label="Under Maintenance" />
                <span class="text-xs text-text-muted">{{ system.users_count }} {{ system.users_count === 1 ? 'person has' : 'people have' }} access</span>
            </div>
        </template>

        <Tabs v-model="tab" :tabs="tabs">
            <!-- Details -->
            <template #details>
                <form id="system-form" @submit.prevent="save">
                    <fieldset :disabled="!canSaveDetails" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        <div class="sm:col-span-2">
                            <TextInput id="system-name" v-model="form.name" label="System name" required :invalid="!!form.errors.name" />
                            <InputError :message="form.errors.name" />
                        </div>
                        <div>
                            <TextInput id="system-short" v-model="form.short_name" label="Code / short name" :invalid="!!form.errors.short_name" />
                            <InputError :message="form.errors.short_name" />
                        </div>
                        <div class="sm:col-span-2 lg:col-span-3">
                            <FormGroup id="system-description" label="Description" :error="form.errors.description">
                                <Textarea id="system-description" v-model="form.description" :invalid="!!form.errors.description" />
                            </FormGroup>
                        </div>
                        <div class="sm:col-span-2">
                            <TextInput id="system-url" v-model="form.url" type="url" label="Launch URL" :invalid="!!form.errors.url" />
                            <InputError :message="form.errors.url" />
                        </div>
                        <div>
                            <TextInput id="system-icon" v-model="form.icon" label="Icon name" :invalid="!!form.errors.icon" />
                            <InputError :message="form.errors.icon" />
                        </div>
                        <FormGroup id="system-office" label="Managing office" :error="form.errors.organizational_unit_id">
                            <Select
                                id="system-office"
                                v-model="form.organizational_unit_id"
                                :options="[{ value: '', label: '— None —' }, ...options.organizationalUnitOptions.map((unit) => ({ value: unit.id, label: unit.name }))]"
                            />
                        </FormGroup>
                        <div>
                            <TextInput id="system-category" v-model="form.category" label="Category" :invalid="!!form.errors.category" />
                            <InputError :message="form.errors.category" />
                        </div>
                        <div>
                            <TextInput id="system-sort" v-model="form.sort_order" type="number" label="Display order" required :invalid="!!form.errors.sort_order" />
                            <InputError :message="form.errors.sort_order" />
                        </div>
                        <FormGroup id="system-status" label="Status" required :error="form.errors.status">
                            <Select id="system-status" v-model="form.status" :options="options.statusOptions" />
                        </FormGroup>
                        <FormGroup id="system-visibility" label="Visible to" required :error="form.errors.visibility">
                            <Select id="system-visibility" v-model="form.visibility" :options="options.visibilityOptions" />
                        </FormGroup>
                        <div class="space-y-2 sm:col-span-2 lg:col-span-3">
                            <Checkbox id="system-requires-access" v-model="form.requires_access" label="Restricted — only people with a role in this system can launch it" />
                            <Checkbox id="system-external" v-model="form.external" label="External system (opens outside IMIS)" />
                            <Checkbox id="system-featured" v-model="form.featured" label="Featured" />
                        </div>
                    </fieldset>
                </form>
            </template>

            <!-- Dashboard listing -->
            <template #listing>
                <fieldset :disabled="!canSaveDetails" class="space-y-5">
                    <p class="text-sm text-text-muted">
                        On the dashboard's Systems Directory this system appears under its managing office. List it
                        under other offices or committees too (the legacy dashboard placements).
                    </p>
                    <CheckboxGroup
                        id="system-listed-units"
                        v-model="form.listed_unit_ids"
                        legend="Also list under these offices"
                        :options="options.organizationalUnitOptions.map((unit) => ({ value: unit.id, label: unit.name }))"
                        :error="form.errors.listed_unit_ids"
                    />
                    <CheckboxGroup
                        id="system-listed-committees"
                        v-model="form.listed_committee_ids"
                        legend="List under these committees"
                        empty-text="No active committees yet."
                        :options="options.committeeOptions.map((committee) => ({ value: committee.id, label: committee.name }))"
                        :error="form.errors.listed_committee_ids"
                    />
                </fieldset>
            </template>

            <!-- Roles -->
            <template v-if="!isNew" #roles>
                <div class="space-y-4">
                    <p class="text-sm text-text-muted">
                        The roles that exist inside {{ system.name }}. Give people a role under <strong>System Access</strong>
                        or <strong>User Access</strong>. A role can have its own launch URL (e.g. an admin page).
                    </p>

                    <div class="overflow-x-auto rounded-md border border-border">
                        <table class="min-w-full text-[0.8125rem]">
                            <thead class="table-head">
                                <tr>
                                    <th scope="col" class="px-3 py-2">Role name</th>
                                    <th scope="col" class="px-3 py-2">Launch URL</th>
                                    <th scope="col" class="px-3 py-2 text-center">People</th>
                                    <th scope="col" class="px-3 py-2 text-center">Status</th>
                                    <th scope="col" class="px-3 py-2"><span class="sr-only">Actions</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-if="!system.roles.length">
                                    <td colspan="5" class="px-3 py-6 text-center text-text-muted">No roles yet — add the first one below.</td>
                                </tr>
                                <tr v-for="role in system.roles" :key="role.id" :class="{ 'bg-info-bg/40': editingRoleId === role.id }">
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-text">{{ role.name }}</div>
                                        <div v-if="role.description" class="text-xs text-text-muted">{{ role.description }}</div>
                                    </td>
                                    <td class="max-w-56 truncate px-3 py-2 text-text-muted">{{ role.launch_url || 'System URL' }}</td>
                                    <td class="px-3 py-2 text-center">{{ role.users_count }}</td>
                                    <td class="px-3 py-2 text-center"><StatusBadge :status="role.status" /></td>
                                    <td class="px-3 py-2">
                                        <div v-if="can('applications.update')" class="flex justify-end gap-1">
                                            <ActionButton icon="pencil" :label="`Edit role ${role.name}`" @click="editRole(role)" />
                                            <ActionButton
                                                icon="power"
                                                :tone="role.status === 'active' ? 'danger' : 'success'"
                                                :label="`${role.status === 'active' ? 'Deactivate' : 'Activate'} role ${role.name}`"
                                                @click="toggleRole(role)"
                                            />
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <form v-if="can('applications.update')" class="rounded-md border border-dashed border-border p-4" @submit.prevent="saveRole">
                        <p class="mb-3 flex items-center gap-2 text-sm font-semibold text-text">
                            <Icon :name="editingRoleId ? 'pencil' : 'plus'" size="sm" />
                            {{ editingRoleId ? 'Edit role' : 'Add a role' }}
                        </p>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div>
                                <TextInput id="role-name" v-model="roleForm.name" label="Role name" required :invalid="!!roleForm.errors.name" />
                                <InputError :message="roleForm.errors.name" />
                            </div>
                            <div>
                                <TextInput id="role-code" v-model="roleForm.code" label="Code (optional)" placeholder="e.g. esd_admin" :invalid="!!roleForm.errors.code" />
                                <InputError :message="roleForm.errors.code" />
                            </div>
                            <div>
                                <TextInput id="role-description" v-model="roleForm.description" label="Description" :invalid="!!roleForm.errors.description" />
                                <InputError :message="roleForm.errors.description" />
                            </div>
                            <div>
                                <TextInput id="role-url" v-model="roleForm.launch_url" type="url" label="Launch URL (optional)" :invalid="!!roleForm.errors.launch_url" />
                                <InputError :message="roleForm.errors.launch_url" />
                            </div>
                            <div>
                                <TextInput id="role-order" v-model="roleForm.sort_order" type="number" label="Order" required :invalid="!!roleForm.errors.sort_order" />
                                <InputError :message="roleForm.errors.sort_order" />
                            </div>
                        </div>
                        <div class="mt-3 flex gap-2">
                            <Button type="submit" variant="success" :loading="roleForm.processing">{{ editingRoleId ? 'Save role' : 'Add role' }}</Button>
                            <Button v-if="editingRoleId" variant="secondary" @click="cancelRoleEdit">Cancel</Button>
                        </div>
                    </form>
                </div>
            </template>

            <!-- Maintenance -->
            <template v-if="!isNew" #maintenance>
                <div class="space-y-4">
                    <Alert v-if="system.under_maintenance" tone="warning">
                        Under maintenance since {{ formatDateTime(system.maintenance_started_at) }}. Only people who manage
                        maintenance can launch it.
                    </Alert>
                    <p v-else class="text-sm text-text-muted">
                        While under maintenance, the portal shows a notice and withholds the launch link from everyone
                        except people who manage maintenance.
                    </p>
                    <FormGroup id="maintenance-message" label="Notice shown to users" helper-text="Optional — e.g. when it will be back." :error="maintenanceForm.errors.maintenance_message">
                        <Textarea id="maintenance-message" v-model="maintenanceForm.maintenance_message" :invalid="!!maintenanceForm.errors.maintenance_message" />
                    </FormGroup>
                    <div class="flex flex-wrap gap-2">
                        <Button :variant="system.under_maintenance ? 'secondary' : 'danger'" :loading="maintenanceForm.processing" @click="saveMaintenance(true)">
                            {{ system.under_maintenance ? 'Update notice' : 'Start maintenance' }}
                        </Button>
                        <Button v-if="system.under_maintenance" variant="success" @click="endMaintenance">End maintenance</Button>
                    </div>
                </div>
            </template>
        </Tabs>

        <template #footer>
            <Button variant="secondary" @click="close">Close</Button>
            <Button
                v-if="(tab === 'details' || tab === 'listing') && canSaveDetails"
                type="submit"
                form="system-form"
                :variant="isNew ? 'success' : 'primary'"
                :loading="form.processing"
                :disabled="!isNew && !form.isDirty"
            >
                {{ isNew ? 'Add System' : 'Save changes' }}
            </Button>
        </template>
    </Modal>
</template>
