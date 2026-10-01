<script setup>
import { Head, useForm } from '@inertiajs/vue3';
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

defineOptions({ layout: AdminLayout });

const props = defineProps({
    statusOptions: { type: Array, required: true },
    visibilityOptions: { type: Array, required: true },
    organizationalUnitOptions: { type: Array, required: true },
    committeeOptions: { type: Array, required: true },
});

const form = useForm({
    name: '',
    short_name: '',
    organizational_unit_id: '',
    description: '',
    icon: '',
    category: '',
    url: '',
    external: true,
    status: 'active',
    visibility: 'authenticated',
    featured: false,
    requires_access: false,
    listed_unit_ids: [],
    listed_committee_ids: [],
    sort_order: 0,
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        organizational_unit_id: data.organizational_unit_id || null,
    })).post('/admin/applications');
};
</script>

<template>
    <Head title="New system" />

    <PageHeader
        title="New system"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Systems / Applications', href: '/admin/applications' },
            { label: 'New system' },
        ]"
    />

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
                        placeholder="e.g. records, examinations"
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
                    <TextInput id="icon" v-model="form.icon" label="Icon" placeholder="e.g. home" :invalid="!!form.errors.icon" />
                    <InputError :message="form.errors.icon" />
                </div>

                <div>
                    <TextInput
                        id="url"
                        v-model="form.url"
                        type="url"
                        label="Launch URL"
                        placeholder="https://…"
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

            <Button type="submit" :loading="form.processing">Create system</Button>
        </form>
    </Card>
</template>
