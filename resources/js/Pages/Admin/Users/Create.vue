<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Button from '../../../Components/UI/Button.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import HelperText from '../../../Components/Forms/HelperText.vue';
import Checkbox from '../../../Components/Forms/Checkbox.vue';
import Alert from '../../../Components/Feedback/Alert.vue';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    roleOptions: { type: Array, required: true },
});

const form = useForm({
    name: '',
    username: '',
    email: '',
    role_ids: [],
});

const toggleRole = (id, checked) => {
    form.role_ids = checked ? [...form.role_ids, id] : form.role_ids.filter((roleId) => roleId !== id);
};

const submit = () => {
    form.post('/users');
};
</script>

<template>
    <Head title="New user" />

    <PageHeader
        title="New user"
        :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Users', href: '/users' }, { label: 'New user' }]"
    />

    <Card class="max-w-lg">
        <Alert tone="info" class="mb-5">
            No password is set here. A secure password-set link is emailed to the new account immediately — the
            same link used for "forgot password" — so no one ever chooses or sees their initial password.
        </Alert>

        <form class="space-y-5" @submit.prevent="submit">
            <div>
                <TextInput id="name" v-model="form.name" label="Full name" required :invalid="!!form.errors.name" />
                <InputError :message="form.errors.name" />
            </div>

            <div>
                <TextInput
                    id="username"
                    v-model="form.username"
                    label="Username"
                    autocomplete="off"
                    :invalid="!!form.errors.username"
                />
                <HelperText v-if="!form.errors.username" id="username-helper">
                    Used to sign in. Leave blank to generate one from the name (initials + surname, e.g. jdcruz).
                </HelperText>
                <InputError :message="form.errors.username" />
            </div>

            <div>
                <TextInput
                    id="email"
                    v-model="form.email"
                    type="email"
                    label="Email address"
                    required
                    :invalid="!!form.errors.email"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div>
                <p class="mb-2 block text-sm font-medium text-text">Roles</p>
                <div class="space-y-2">
                    <Checkbox
                        v-for="role in roleOptions"
                        :key="role.id"
                        :id="`role-${role.id}`"
                        :label="role.display_name"
                        :model-value="form.role_ids.includes(role.id)"
                        @update:model-value="(checked) => toggleRole(role.id, checked)"
                    />
                    <p v-if="roleOptions.length === 0" class="text-sm text-text-muted">No active roles available.</p>
                </div>
                <InputError :message="form.errors.role_ids" />
            </div>

            <Button type="submit" :loading="form.processing">Create user</Button>
        </form>
    </Card>
</template>
