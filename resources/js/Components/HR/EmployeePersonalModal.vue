<script setup>
import { computed, ref, useId, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import FormGroup from '@cscro8/ui/Components/Forms/FormGroup.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import PhotoPicker from '@cscro8/ui/Components/Forms/PhotoPicker.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';

/**
 * HR corrects a person's own details from the employee record: profile
 * photo (with the cropper), title, name, sex and birthday. The photo saves
 * as soon as it is cropped, like in Manage Users; the rest on "Save".
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    employee: { type: Object, required: true },
    honorificOptions: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const id = useId();

const valuesFor = () => ({
    honorific: props.employee.personal?.honorific ?? '',
    first_name: props.employee.personal?.first_name ?? '',
    middle_name: props.employee.personal?.middle_name ?? '',
    last_name: props.employee.personal?.last_name ?? '',
    name_extension: props.employee.personal?.name_extension ?? '',
    sex: props.employee.personal?.sex ?? '',
    birthday: props.employee.personal?.birthday ?? '',
});

const form = useForm(valuesFor());

watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.defaults(valuesFor());
        form.reset();
        form.clearErrors();
        photoError.value = null;
    },
);

const honorifics = computed(() => [{ value: '', label: 'None' }, ...props.honorificOptions.map((title) => ({ value: title, label: title }))]);
const sexOptions = [
    { value: '', label: 'Prefer not to say' },
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
];

const close = () => emit('close');
const url = computed(() => `/admin/hr/employees/${props.employee.id}`);

const submit = () => {
    form
        .transform((data) => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, key !== 'first_name' && key !== 'last_name' && value === '' ? null : value])))
        .put(`${url.value}/personal`, { preserveScroll: true, onSuccess: close });
};

const photoError = ref(null);
const uploadPhoto = (file) => {
    photoError.value = null;
    router.post(`${url.value}/photo`, { profile_photo: file }, {
        forceFormData: true,
        preserveScroll: true,
        onError: (errors) => (photoError.value = errors.profile_photo),
    });
};

const removePhoto = async () => {
    if (await confirm({ title: 'Remove profile photo?', text: `${props.employee.user.name} will show their initials instead.`, tone: 'danger', confirmText: 'Remove' })) {
        router.delete(`${url.value}/photo`, { preserveScroll: true });
    }
};
</script>

<template>
    <Modal :open="open" :title="`Edit Personal Information — ${employee.user.name}`" tone="primary" icon="pencil" size="lg" @close="close">
        <div class="space-y-5">
            <PhotoPicker :name="employee.user.name" :photo-url="employee.user.profile_photo_url" :error="photoError" @select="uploadPhoto">
                <button
                    v-if="employee.user.profile_photo_url"
                    type="button"
                    class="text-xs font-medium text-danger-fg hover:underline"
                    @click="removePhoto"
                >
                    Remove photo
                </button>
            </PhotoPicker>

            <form :id="`${id}-form`" class="space-y-4" novalidate @submit.prevent="submit">
                <div class="grid gap-4 sm:grid-cols-[6.5rem_1fr_1fr_1fr_6rem]">
                    <FormGroup :id="`${id}-honorific`" label="Title" :error="form.errors.honorific">
                        <Select :id="`${id}-honorific`" v-model="form.honorific" :options="honorifics" />
                    </FormGroup>
                    <TextInput :id="`${id}-first_name`" v-model="form.first_name" label="First name" required :error="form.errors.first_name" />
                    <TextInput :id="`${id}-middle_name`" v-model="form.middle_name" label="Middle name" :error="form.errors.middle_name" />
                    <TextInput :id="`${id}-last_name`" v-model="form.last_name" label="Last name" required :error="form.errors.last_name" />
                    <TextInput :id="`${id}-name_extension`" v-model="form.name_extension" label="Ext. (Jr.)" :error="form.errors.name_extension" />
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormGroup :id="`${id}-sex`" label="Sex" :error="form.errors.sex">
                        <Select :id="`${id}-sex`" v-model="form.sex" :options="sexOptions" />
                    </FormGroup>
                    <TextInput :id="`${id}-birthday`" v-model="form.birthday" type="date" label="Birthday" :error="form.errors.birthday" />
                </div>
            </form>
        </div>

        <template #footer>
            <Button variant="secondary" icon="close" @click="close">Cancel</Button>
            <Button type="submit" :form="`${id}-form`" variant="primary" :loading="form.processing" :disabled="!form.isDirty">Save changes</Button>
        </template>
    </Modal>
</template>
