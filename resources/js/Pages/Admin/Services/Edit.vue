<script setup>
import { computed, ref } from 'vue';
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
import ConfirmDialog from '../../../Components/Feedback/ConfirmDialog.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    service: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    visibilityOptions: { type: Array, required: true },
    categoryOptions: { type: Array, required: true },
    organizationalUnitOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const form = useForm({
    name: props.service.name,
    short_name: props.service.short_name ?? '',
    organizational_unit_id: props.service.organizational_unit_id ?? '',
    category_id: props.service.category_id ?? '',
    description: props.service.description ?? '',
    purpose: props.service.purpose ?? '',
    requirements: props.service.requirements ?? '',
    procedure: props.service.procedure ?? '',
    fees: props.service.fees ?? '',
    processing_time: props.service.processing_time ?? '',
    contact_information: props.service.contact_information ?? '',
    external_url: props.service.external_url ?? '',
    status: props.service.status,
    visibility: props.service.visibility,
    featured: props.service.featured,
    sort_order: props.service.sort_order,
});

const submit = () => {
    form.transform((data) => ({
        ...data,
        organizational_unit_id: data.organizational_unit_id || null,
        category_id: data.category_id || null,
    })).put(`/admin/services/${props.service.id}`);
};

const isActive = computed(() => props.service.status === 'active');
const confirmOpen = ref(false);
const statusForm = useForm({ status: '' });

const toggleStatus = () => {
    statusForm.status = isActive.value ? 'inactive' : 'active';
    statusForm.patch(`/admin/services/${props.service.id}/status`, {
        onSuccess: () => (confirmOpen.value = false),
    });
};
</script>

<template>
    <Head :title="`Edit service — ${service.name}`" />

    <PageHeader
        :title="`Edit service — ${service.name}`"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: 'Services', href: '/admin/services' },
            { label: service.name },
        ]"
    >
        <template v-if="can('services.archive')" #actions>
            <Button :variant="isActive ? 'danger' : 'primary'" @click="confirmOpen = true">
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
                    <TextInput id="fees" v-model="form.fees" label="Fees" :invalid="!!form.errors.fees" />
                    <InputError :message="form.errors.fees" />
                </div>

                <div>
                    <TextInput
                        id="processing_time"
                        v-model="form.processing_time"
                        label="Processing time"
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

            <Button type="submit" :loading="form.processing">Save service</Button>
        </form>
    </Card>

    <ConfirmDialog
        :open="confirmOpen"
        :title="isActive ? 'Deactivate this service?' : 'Activate this service?'"
        :danger="isActive"
        :confirm-label="isActive ? 'Deactivate' : 'Activate'"
        :processing="statusForm.processing"
        @confirm="toggleStatus"
        @cancel="confirmOpen = false"
    >
        {{
            isActive
                ? `${service.name} will no longer appear in the portal directory.`
                : `${service.name} will appear in the portal directory again.`
        }}
    </ConfirmDialog>
</template>
