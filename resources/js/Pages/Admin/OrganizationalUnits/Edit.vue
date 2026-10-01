<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Button from '../../../Components/UI/Button.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import FormGroup from '../../../Components/Forms/FormGroup.vue';
import Select from '../../../Components/Forms/Select.vue';
import Textarea from '../../../Components/Forms/Textarea.vue';
import ConfirmDialog from '../../../Components/Feedback/ConfirmDialog.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    organizationalUnit: { type: Object, required: true },
    ancestors: { type: Array, required: true },
    children: { type: Array, required: true },
    parentOptions: { type: Array, required: true },
    statusOptions: { type: Array, required: true },
    unitTypeOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const form = useForm({
    code: props.organizationalUnit.code,
    name: props.organizationalUnit.name,
    short_name: props.organizationalUnit.short_name ?? '',
    parent_id: props.organizationalUnit.parent_id ?? '',
    unit_type: props.organizationalUnit.unit_type,
    status: props.organizationalUnit.status,
    sort_order: props.organizationalUnit.sort_order,
    description: props.organizationalUnit.description ?? '',
    email: props.organizationalUnit.email ?? '',
    telephone: props.organizationalUnit.telephone ?? '',
    address: props.organizationalUnit.address ?? '',
    website_url: props.organizationalUnit.website_url ?? '',
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        parent_id: data.parent_id || null,
    })).put(`/admin/organizational-units/${props.organizationalUnit.id}`);
};

const isActive = computed(() => props.organizationalUnit.status === 'active');
const confirmOpen = ref(false);
const statusForm = useForm({ status: '' });

const toggleStatus = () => {
    statusForm.status = isActive.value ? 'inactive' : 'active';
    statusForm.patch(`/admin/organizational-units/${props.organizationalUnit.id}/status`, {
        onSuccess: () => (confirmOpen.value = false),
    });
};
</script>

<template>
    <Head :title="`Edit unit — ${organizationalUnit.name}`" />

    <PageHeader
        :title="`Edit unit — ${organizationalUnit.name}`"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Organizational Units', href: '/admin/organizational-units' },
            { label: organizationalUnit.name },
        ]"
    >
        <template v-if="can('organizational_units.archive')" #actions>
            <Button :variant="isActive ? 'danger' : 'primary'" @click="confirmOpen = true">
                {{ isActive ? 'Deactivate' : 'Activate' }}
            </Button>
        </template>
    </PageHeader>

    <p v-if="ancestors.length" class="mb-4 text-sm text-text-muted">
        <span v-for="(ancestor, index) in ancestors" :key="ancestor.id">
            <Link :href="`/admin/organizational-units/${ancestor.id}/edit`" class="hover:text-text">
                {{ ancestor.name }}
            </Link>
            <span v-if="index < ancestors.length - 1"> / </span>
        </span>
    </p>

    <div class="grid gap-6 lg:grid-cols-2">
        <Card title="Details">
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

                <Button type="submit" :loading="form.processing">Save unit</Button>
            </form>
        </Card>

        <Card title="Sub-units">
            <ul v-if="children.length" class="divide-y divide-border">
                <li v-for="child in children" :key="child.id" class="flex items-center justify-between py-2">
                    <Link
                        :href="`/admin/organizational-units/${child.id}/edit`"
                        class="text-sm font-medium text-primary hover:text-primary-hover"
                    >
                        {{ child.name }}
                    </Link>
                    <Badge :tone="child.status === 'active' ? 'success' : 'neutral'">{{ child.status }}</Badge>
                </li>
            </ul>
            <p v-else class="text-sm text-text-muted">This unit has no sub-units.</p>
        </Card>
    </div>

    <ConfirmDialog
        :open="confirmOpen"
        :title="isActive ? 'Deactivate this unit?' : 'Activate this unit?'"
        :danger="isActive"
        :confirm-label="isActive ? 'Deactivate' : 'Activate'"
        :processing="statusForm.processing"
        @confirm="toggleStatus"
        @cancel="confirmOpen = false"
    >
        {{
            isActive
                ? `${organizationalUnit.name} will no longer appear in the portal directory.`
                : `${organizationalUnit.name} will appear in the portal directory again.`
        }}
    </ConfirmDialog>
</template>
