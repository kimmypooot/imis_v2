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

defineProps({
    statusOptions: { type: Array, required: true },
});

const form = useForm({
    name: '',
    short_name: '',
    description: '',
    status: 'active',
    effective_from: '',
    effective_until: '',
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        effective_from: data.effective_from || null,
        effective_until: data.effective_until || null,
    })).post('/admin/committees');
};
</script>

<template>
    <Head title="New committee" />

    <PageHeader
        title="New committee"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Committees', href: '/admin/committees' },
            { label: 'New committee' },
        ]"
    />

    <Card class="max-w-2xl">
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

                <FormGroup id="status" label="Status" required :error="form.errors.status">
                    <Select id="status" v-model="form.status" :options="statusOptions" />
                </FormGroup>

                <div />

                <div>
                    <TextInput
                        id="effective_from"
                        v-model="form.effective_from"
                        type="date"
                        label="Effective from"
                        :invalid="!!form.errors.effective_from"
                    />
                    <InputError :message="form.errors.effective_from" />
                </div>

                <div>
                    <TextInput
                        id="effective_until"
                        v-model="form.effective_until"
                        type="date"
                        label="Effective until"
                        :invalid="!!form.errors.effective_until"
                    />
                    <InputError :message="form.errors.effective_until" />
                </div>
            </div>

            <FormGroup id="description" label="Description" :error="form.errors.description">
                <Textarea id="description" v-model="form.description" :invalid="!!form.errors.description" />
            </FormGroup>

            <Button type="submit" :loading="form.processing">Create committee</Button>
        </form>
    </Card>
</template>
