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

defineOptions({ layout: AdminLayout });

const props = defineProps({
    statusOptions: { type: Array, required: true },
    visibilityOptions: { type: Array, required: true },
    categoryOptions: { type: Array, required: true },
    organizationalUnitOptions: { type: Array, required: true },
});

const form = useForm({
    name: '',
    short_name: '',
    organizational_unit_id: '',
    category_id: '',
    description: '',
    purpose: '',
    requirements: '',
    procedure: '',
    fees: '',
    processing_time: '',
    contact_information: '',
    external_url: '',
    status: 'active',
    visibility: 'authenticated',
    featured: false,
    sort_order: 0,
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        organizational_unit_id: data.organizational_unit_id || null,
        category_id: data.category_id || null,
    })).post('/admin/services');
};
</script>

<template>
    <Head title="New service" />

    <PageHeader
        title="New service"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Services', href: '/admin/services' },
            { label: 'New service' },
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

                <FormGroup id="category_id" label="Category" :error="form.errors.category_id">
                    <Select
                        id="category_id"
                        v-model="form.category_id"
                        placeholder="None"
                        :options="categoryOptions.map((category) => ({ value: category.id, label: category.name }))"
                    />
                </FormGroup>

                <FormGroup id="organizational_unit_id" label="Organizational unit" :error="form.errors.organizational_unit_id">
                    <Select
                        id="organizational_unit_id"
                        v-model="form.organizational_unit_id"
                        placeholder="None"
                        :options="organizationalUnitOptions.map((unit) => ({ value: unit.id, label: unit.name }))"
                    />
                </FormGroup>

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
                    <TextInput
                        id="fees"
                        v-model="form.fees"
                        label="Fees"
                        placeholder="e.g. Free, or ₱50.00"
                        :invalid="!!form.errors.fees"
                    />
                    <InputError :message="form.errors.fees" />
                </div>

                <div>
                    <TextInput
                        id="processing_time"
                        v-model="form.processing_time"
                        label="Processing time"
                        placeholder="e.g. 3 working days"
                        :invalid="!!form.errors.processing_time"
                    />
                    <InputError :message="form.errors.processing_time" />
                </div>

                <div>
                    <TextInput
                        id="external_url"
                        v-model="form.external_url"
                        type="url"
                        label="External URL"
                        placeholder="https://…"
                        :invalid="!!form.errors.external_url"
                    />
                    <InputError :message="form.errors.external_url" />
                </div>
            </div>

            <Checkbox id="featured" v-model="form.featured" label="Featured (shown in Quick Access-style listings)" />

            <FormGroup id="description" label="Description" :error="form.errors.description">
                <Textarea id="description" v-model="form.description" :invalid="!!form.errors.description" />
            </FormGroup>

            <FormGroup id="purpose" label="Purpose" :error="form.errors.purpose">
                <Textarea id="purpose" v-model="form.purpose" :invalid="!!form.errors.purpose" />
            </FormGroup>

            <FormGroup id="requirements" label="Requirements" :error="form.errors.requirements">
                <Textarea id="requirements" v-model="form.requirements" :invalid="!!form.errors.requirements" />
            </FormGroup>

            <FormGroup id="procedure" label="Procedure" :error="form.errors.procedure">
                <Textarea id="procedure" v-model="form.procedure" :invalid="!!form.errors.procedure" />
            </FormGroup>

            <div>
                <TextInput
                    id="contact_information"
                    v-model="form.contact_information"
                    label="Contact information"
                    :invalid="!!form.errors.contact_information"
                />
                <InputError :message="form.errors.contact_information" />
            </div>

            <Button type="submit" :loading="form.processing">Create service</Button>
        </form>
    </Card>
</template>
