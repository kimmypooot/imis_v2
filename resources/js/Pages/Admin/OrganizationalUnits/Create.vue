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

defineOptions({ layout: AdminLayout });

const props = defineProps({
    parentOptions: { type: Array, required: true },
    statusOptions: { type: Array, required: true },
    unitTypeOptions: { type: Array, required: true },
});

const form = useForm({
    code: '',
    name: '',
    short_name: '',
    parent_id: '',
    unit_type: props.unitTypeOptions[0]?.value ?? '',
    status: 'active',
    sort_order: 0,
    description: '',
    email: '',
    telephone: '',
    address: '',
    website_url: '',
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        parent_id: data.parent_id || null,
    })).post('/admin/organizational-units');
};
</script>

<template>
    <Head title="New organizational unit" />

    <PageHeader
        title="New organizational unit"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Organizational Units', href: '/admin/organizational-units' },
            { label: 'New unit' },
        ]"
    />

    <Card class="max-w-2xl">
        <form class="space-y-5" @submit.prevent="submit">
            <div class="grid gap-5 sm:grid-cols-2">
                <div>
                    <TextInput id="code" v-model="form.code" label="Code" required :invalid="!!form.errors.code" />
                    <InputError :message="form.errors.code" />
                </div>

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

                <FormGroup id="parent_id" label="Parent unit" :error="form.errors.parent_id">
                    <Select
                        id="parent_id"
                        v-model="form.parent_id"
                        placeholder="None (top-level unit)"
                        :options="parentOptions.map((unit) => ({ value: unit.id, label: unit.name }))"
                    />
                </FormGroup>

                <FormGroup id="unit_type" label="Type" required :error="form.errors.unit_type">
                    <Select id="unit_type" v-model="form.unit_type" :options="unitTypeOptions" />
                </FormGroup>

                <FormGroup id="status" label="Status" required :error="form.errors.status">
                    <Select id="status" v-model="form.status" :options="statusOptions" />
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
                    <TextInput
                        id="email"
                        v-model="form.email"
                        type="email"
                        label="Email"
                        :invalid="!!form.errors.email"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div>
                    <TextInput
                        id="telephone"
                        v-model="form.telephone"
                        type="tel"
                        label="Telephone"
                        :invalid="!!form.errors.telephone"
                    />
                    <InputError :message="form.errors.telephone" />
                </div>

                <div>
                    <TextInput
                        id="website_url"
                        v-model="form.website_url"
                        type="url"
                        label="Website URL"
                        :invalid="!!form.errors.website_url"
                    />
                    <InputError :message="form.errors.website_url" />
                </div>
            </div>

            <div>
                <TextInput id="address" v-model="form.address" label="Address" :invalid="!!form.errors.address" />
                <InputError :message="form.errors.address" />
            </div>

            <FormGroup id="description" label="Description" :error="form.errors.description">
                <Textarea id="description" v-model="form.description" :invalid="!!form.errors.description" />
            </FormGroup>

            <Button type="submit" :loading="form.processing">Create unit</Button>
        </form>
    </Card>
</template>
