<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Button from '../../../Components/UI/Button.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import FormGroup from '../../../Components/Forms/FormGroup.vue';
import Select from '../../../Components/Forms/Select.vue';
import Textarea from '../../../Components/Forms/Textarea.vue';
import Checkbox from '../../../Components/Forms/Checkbox.vue';
import CheckboxGroup from '../../../Components/Forms/CheckboxGroup.vue';
import ConfirmDialog from '../../../Components/Feedback/ConfirmDialog.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
import { formatDateTime } from '../../../Utils/datetime';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    application: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    visibilityOptions: { type: Array, required: true },
    organizationalUnitOptions: { type: Array, required: true },
    committeeOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const form = useForm({
    name: props.application.name,
    short_name: props.application.short_name ?? '',
    organizational_unit_id: props.application.organizational_unit_id ?? '',
    description: props.application.description ?? '',
    icon: props.application.icon ?? '',
    category: props.application.category ?? '',
    url: props.application.url ?? '',
    external: props.application.external,
    status: props.application.status,
    visibility: props.application.visibility,
    featured: props.application.featured,
    requires_access: props.application.requires_access,
    listed_unit_ids: [...props.application.listed_unit_ids],
    listed_committee_ids: [...props.application.listed_committee_ids],
    sort_order: props.application.sort_order,
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        organizational_unit_id: data.organizational_unit_id || null,
    })).put(`/admin/applications/${props.application.id}`);
};

const isActive = computed(() => props.application.status === 'active');
const confirmOpen = ref(false);
const statusForm = useForm({ status: '' });

const maintenanceForm = useForm({
    under_maintenance: true,
    maintenance_message: props.application.maintenance_message ?? '',
});
const confirmEndMaintenance = ref(false);

const saveMaintenance = () => {
    maintenanceForm.under_maintenance = true;
    maintenanceForm.patch(`/admin/applications/${props.application.id}/maintenance`, { preserveScroll: true });
};

const endMaintenance = () => {
    maintenanceForm.under_maintenance = false;
    maintenanceForm.patch(`/admin/applications/${props.application.id}/maintenance`, {
        preserveScroll: true,
        onSuccess: () => {
            confirmEndMaintenance.value = false;
            maintenanceForm.maintenance_message = '';
        },
    });
};

const toggleStatus = () => {
    statusForm.status = isActive.value ? 'inactive' : 'active';
    statusForm.patch(`/admin/applications/${props.application.id}/status`, {
        onSuccess: () => (confirmOpen.value = false),
    });
};
</script>

<template>
    <Head :title="`Edit system — ${application.name}`" />

    <PageHeader
        :title="`Edit system — ${application.name}`"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Systems / Applications', href: '/admin/applications' },
            { label: application.name },
        ]"
    >
        <template #actions>
            <Link :href="`/admin/applications/${application.id}/access`">
                <Button variant="secondary">Roles &amp; access</Button>
            </Link>
            <Button
                v-if="can('applications.archive')"
                :variant="isActive ? 'danger' : 'primary'"
                @click="confirmOpen = true"
            >
                {{ isActive ? 'Deactivate' : 'Activate' }}
            </Button>
        </template>
    </PageHeader>

    <Card class="max-w-3xl">
        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <TextInput id="name" v-model="form.name" label="Name" required :invalid="!!form.errors.name" />
                    <InputError :message="form.errors.name" />
                </div>

                <div>
                    <TextInput
                        id="short_name"
                        v-model="form.short_name"
                        label="Short name"
                        :invalid="!!form.errors.short_name"
                    />
                    <InputError :message="form.errors.short_name" />
                </div>

                <FormGroup id="organizational_unit_id" label="Organizational unit" :error="form.errors.organizational_unit_id">
                    <Select
                        id="organizational_unit_id"
                        v-model="form.organizational_unit_id"
                        placeholder="None"
                        :options="organizationalUnitOptions.map((unit) => ({ value: unit.id, label: unit.name }))"
                    />
                </FormGroup>

                <div>
                    <TextInput
                        id="category"
                        v-model="form.category"
                        label="Category"
                        :invalid="!!form.errors.category"
                    />
                    <InputError :message="form.errors.category" />
                </div>

                <FormGroup id="status" label="Status" required :error="form.errors.status">
                    <Select id="status" v-model="form.status" :options="statusOptions" />
                </FormGroup>

                <FormGroup id="visibility" label="Visibility" required :error="form.errors.visibility">
                    <Select id="visibility" v-model="form.visibility" :options="visibilityOptions" />
                </FormGroup>

                <div>
                    <TextInput
                        id="sort_order"
                        v-model="form.sort_order"
                        type="number"
                        label="Sort order"
                        required
                        :invalid="!!form.errors.sort_order"
                    />
                    <InputError :message="form.errors.sort_order" />
                </div>

                <div>
                    <TextInput id="icon" v-model="form.icon" label="Icon" :invalid="!!form.errors.icon" />
                    <InputError :message="form.errors.icon" />
                </div>

                <div>
                    <TextInput
                        id="url"
                        v-model="form.url"
                        type="url"
                        label="Launch URL"
                        :invalid="!!form.errors.url"
                    />
                    <InputError :message="form.errors.url" />
                </div>
            </div>

            <Checkbox id="external" v-model="form.external" label="External system (launches outside IMIS)" />
            <Checkbox id="featured" v-model="form.featured" label="Featured (shown in Quick Access-style listings)" />
            <Checkbox
                id="requires_access"
                v-model="form.requires_access"
                label="Restricted (only people granted a role in this system get the launch link)"
            />

            <div class="space-y-5 rounded-md border border-border p-4">
                <p class="text-sm text-text-muted">
                    On the dashboard directory, this system appears under its organizational unit above. List it
                    under other offices or committees too, as the legacy dashboard allowed.
                </p>
                <CheckboxGroup
                    id="listed_unit_ids"
                    v-model="form.listed_unit_ids"
                    legend="Also list under these offices"
                    :options="organizationalUnitOptions.map((unit) => ({ value: unit.id, label: unit.name }))"
                    :error="form.errors.listed_unit_ids"
                />
                <CheckboxGroup
                    id="listed_committee_ids"
                    v-model="form.listed_committee_ids"
                    legend="List under these committees"
                    :options="committeeOptions.map((committee) => ({ value: committee.id, label: committee.name }))"
                    empty-text="No active committees yet."
                    :error="form.errors.listed_committee_ids"
                />
            </div>


            <FormGroup id="description" label="Description" :error="form.errors.description">
                <Textarea id="description" v-model="form.description" :invalid="!!form.errors.description" />
            </FormGroup>

            <Button type="submit" :loading="form.processing">Save system</Button>
        </form>
    </Card>

    <Card
        v-if="can('applications.maintenance')"
        title="Maintenance"
        description="While a system is under maintenance, the portal shows a notice and withholds its launch link from everyone except people who manage maintenance."
        class="mt-6 max-w-3xl"
    >
        <form class="space-y-4" @submit.prevent="saveMaintenance">
            <Alert v-if="application.under_maintenance" tone="warning">
                Under maintenance since {{ formatDateTime(application.maintenance_started_at) }}.
            </Alert>

            <FormGroup
                id="maintenance_message"
                label="Notice shown to users"
                helper-text="Optional. For example, the expected time it will be back."
                :error="maintenanceForm.errors.maintenance_message"
            >
                <Textarea
                    id="maintenance_message"
                    v-model="maintenanceForm.maintenance_message"
                    :invalid="!!maintenanceForm.errors.maintenance_message"
                />
            </FormGroup>

            <div class="flex flex-wrap gap-2">
                <Button type="submit" :variant="application.under_maintenance ? 'secondary' : 'danger'" :loading="maintenanceForm.processing">
                    {{ application.under_maintenance ? 'Update notice' : 'Start maintenance' }}
                </Button>
                <Button v-if="application.under_maintenance" @click="confirmEndMaintenance = true">End maintenance</Button>
            </div>
        </form>
    </Card>

    <ConfirmDialog
        :open="confirmEndMaintenance"
        title="End maintenance?"
        confirm-label="End maintenance"
        :processing="maintenanceForm.processing"
        @confirm="endMaintenance"
        @cancel="confirmEndMaintenance = false"
    >
        {{ application.name }} will be launchable again and its notice will be removed.
    </ConfirmDialog>

    <ConfirmDialog
        :open="confirmOpen"
        :title="isActive ? 'Deactivate this system?' : 'Activate this system?'"
        :danger="isActive"
        :confirm-label="isActive ? 'Deactivate' : 'Activate'"
        :processing="statusForm.processing"
        @confirm="toggleStatus"
        @cancel="confirmOpen = false"
    >
        {{
            isActive
                ? `${application.name} will no longer appear in the portal directory.`
                : `${application.name} will appear in the portal directory again.`
        }}
    </ConfirmDialog>
</template>
