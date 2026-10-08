<script setup>
import TextInput from '../Forms/TextInput.vue';
import InputError from '../Forms/InputError.vue';
import FormGroup from '../Forms/FormGroup.vue';
import Select from '../Forms/Select.vue';
import { computed, watch } from 'vue';

/**
 * The legacy Add/Edit User profile fields, shared by both modals. Binds
 * straight to the parent's Inertia form (`form.first_name`, …) so errors
 * come back on the same keys.
 */
const props = defineProps({
    form: { type: Object, required: true },
    officeOptions: { type: Array, required: true },
    idPrefix: { type: String, required: true },
    // Division Chief / Field Director: the labels, and whether this viewer may set it.
    designationOptions: { type: Array, default: () => [] },
    canDesignate: { type: Boolean, default: false },
    // Atty., Dr., … — shown in front of the name, never part of it.
    honorificOptions: { type: Array, default: () => [] },
});

// The designation the chosen office takes (RSU → Division Chief, field/satellite → Field Director).
const officeDesignation = computed(() => {
    const office = props.officeOptions
        .flatMap((group) => group.options ?? [group])
        .find((option) => String(option.value) === String(props.form.organizational_unit_id));

    return props.designationOptions.find((option) => option.value === office?.designation) ?? null;
});

// Another type of office: the designation no longer fits.
watch(officeDesignation, (designation) => {
    if (props.form.office_designation && props.form.office_designation !== designation?.value) {
        props.form.office_designation = '';
    }
});

const honorifics = computed(() => [{ value: '', label: 'None' }, ...props.honorificOptions.map((title) => ({ value: title, label: title }))]);

const sexOptions = [
    { value: '', label: 'Prefer not to say' },
    { value: 'male', label: 'Male' },
    { value: 'female', label: 'Female' },
];
</script>

<template>
    <div class="grid gap-4 sm:grid-cols-[6.5rem_1fr_1fr_1fr_6rem]">
        <FormGroup :id="`${idPrefix}-honorific`" label="Title" :error="form.errors.honorific">
            <Select :id="`${idPrefix}-honorific`" v-model="form.honorific" :options="honorifics" />
        </FormGroup>
        <div>
            <TextInput :id="`${idPrefix}-first_name`" v-model="form.first_name" label="First name" required :invalid="!!form.errors.first_name" />
            <InputError :message="form.errors.first_name" />
        </div>
        <div>
            <TextInput :id="`${idPrefix}-middle_name`" v-model="form.middle_name" label="Middle name" :invalid="!!form.errors.middle_name" />
            <InputError :message="form.errors.middle_name" />
        </div>
        <div>
            <TextInput :id="`${idPrefix}-last_name`" v-model="form.last_name" label="Last name" required :invalid="!!form.errors.last_name" />
            <InputError :message="form.errors.last_name" />
        </div>
        <div>
            <TextInput :id="`${idPrefix}-name_extension`" v-model="form.name_extension" label="Ext. (Jr.)" :invalid="!!form.errors.name_extension" />
            <InputError :message="form.errors.name_extension" />
        </div>
    </div>

    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div>
            <TextInput :id="`${idPrefix}-email`" v-model="form.email" type="email" label="Email" required :invalid="!!form.errors.email" />
            <InputError :message="form.errors.email" />
        </div>
        <div>
            <TextInput :id="`${idPrefix}-position`" v-model="form.position" label="Position" :invalid="!!form.errors.position" />
            <InputError :message="form.errors.position" />
        </div>
        <FormGroup :id="`${idPrefix}-sex`" label="Sex" :error="form.errors.sex">
            <Select :id="`${idPrefix}-sex`" v-model="form.sex" :options="sexOptions" />
        </FormGroup>
        <div>
            <TextInput :id="`${idPrefix}-birthday`" v-model="form.birthday" type="date" label="Birthday" :invalid="!!form.errors.birthday" />
            <InputError :message="form.errors.birthday" />
        </div>
        <FormGroup :id="`${idPrefix}-office`" label="Division / Office" :error="form.errors.organizational_unit_id" class="sm:col-span-2">
            <Select
                :id="`${idPrefix}-office`"
                v-model="form.organizational_unit_id"
                :options="[{ value: '', label: '— Not set —' }, ...officeOptions]"
            />
        </FormGroup>
        <FormGroup
            v-if="officeDesignation"
            :id="`${idPrefix}-designation`"
            label="Designation"
            :error="form.errors.office_designation"
            :helper-text="`The ${officeDesignation.label} is overall admin of the office's systems. One per office.`"
            class="sm:col-span-2"
        >
            <Select
                :id="`${idPrefix}-designation`"
                v-model="form.office_designation"
                :options="[{ value: '', label: '— None —' }, officeDesignation]"
                :disabled="!canDesignate"
            />
        </FormGroup>
    </div>
</template>
