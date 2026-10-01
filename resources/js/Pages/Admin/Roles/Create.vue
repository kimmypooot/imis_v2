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

const form = useForm({
    name: '',
    display_name: '',
    description: '',
    status: 'active',
});

const submit = () => {
    form.post('/roles');
};
</script>

<template>
    <Head title="New role" />

    <PageHeader
        title="New role"
        :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Roles', href: '/roles' }, { label: 'New role' }]"
    />

    <Card class="max-w-lg">
        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <TextInput
                    id="name"
                    v-model="form.name"
                    label="Name (machine-readable, e.g. rsu-manager)"
                    required
                    :invalid="!!form.errors.name"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div>
                <TextInput
                    id="display_name"
                    v-model="form.display_name"
                    label="Display name"
                    required
                    :invalid="!!form.errors.display_name"
                />
                <InputError :message="form.errors.display_name" />
            </div>

            <FormGroup id="description" label="Description" :error="form.errors.description">
                <Textarea id="description" v-model="form.description" :invalid="!!form.errors.description" />
            </FormGroup>

            <FormGroup id="status" label="Status" required :error="form.errors.status">
                <Select
                    id="status"
                    v-model="form.status"
                    :options="[
                        { value: 'active', label: 'Active' },
                        { value: 'inactive', label: 'Inactive' },
                    ]"
                    :invalid="!!form.errors.status"
                />
            </FormGroup>

            <Button type="submit" :loading="form.processing">Create role</Button>
        </form>
    </Card>
</template>
