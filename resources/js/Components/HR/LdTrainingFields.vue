<script setup>
import Select from '../Forms/Select.vue';
import TextInput from '../Forms/TextInput.vue';
import InputError from '../Forms/InputError.vue';

/**
 * The training fields of an L&D entry (ldms's "Add Training"), bound to
 * the parent's Inertia form. Used by the single-entry and bulk-add
 * modals; lay them out in a two-column grid.
 */
defineProps({
    form: { type: Object, required: true },
    typeOptions: { type: Array, required: true },
    modalityOptions: { type: Array, required: true },
    idPrefix: { type: String, default: 'ld' },
});

const field = 'mt-1 block w-full';
</script>

<template>
    <div class="sm:col-span-2">
        <TextInput :id="`${idPrefix}-title`" v-model="form.title" label="Title of the L&D intervention / training (write in full)" required :invalid="!!form.errors.title" />
        <InputError :message="form.errors.title" />
    </div>
    <div>
        <TextInput :id="`${idPrefix}-from`" v-model="form.date_from" type="date" label="Start date" required :invalid="!!form.errors.date_from" />
        <InputError :message="form.errors.date_from" />
    </div>
    <div>
        <TextInput :id="`${idPrefix}-to`" v-model="form.date_to" type="date" label="End date" required :invalid="!!form.errors.date_to" />
        <InputError :message="form.errors.date_to" />
    </div>
    <div>
        <label :for="`${idPrefix}-hours`" class="block text-sm font-medium text-text">Number of hours <span class="text-danger" aria-hidden="true">*</span></label>
        <!-- A plain input: hours can be fractional (e.g. 1.5), which needs a step. -->
        <input
            :id="`${idPrefix}-hours`"
            v-model="form.hours"
            type="number"
            min="0.25"
            max="9999"
            step="0.25"
            required
            :aria-invalid="!!form.errors.hours"
            class="mt-1 block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
        />
        <InputError :message="form.errors.hours" />
    </div>
    <div>
        <label :for="`${idPrefix}-modality`" class="block text-sm font-medium text-text">Modality <span class="text-danger" aria-hidden="true">*</span></label>
        <Select :id="`${idPrefix}-modality`" v-model="form.modality" :class="field" :options="modalityOptions" placeholder="— Select —" required :invalid="!!form.errors.modality" />
        <InputError :message="form.errors.modality" />
    </div>
    <div>
        <label :for="`${idPrefix}-type`" class="block text-sm font-medium text-text">Type of L&amp;D <span class="text-danger" aria-hidden="true">*</span></label>
        <Select :id="`${idPrefix}-type`" v-model="form.ld_type" :class="field" :options="typeOptions" placeholder="— Select —" required :invalid="!!form.errors.ld_type" />
        <InputError :message="form.errors.ld_type" />
    </div>
    <div v-if="form.ld_type === 'other'">
        <TextInput :id="`${idPrefix}-type-other`" v-model="form.ld_type_other" label="Type (specify)" required :invalid="!!form.errors.ld_type_other" />
        <InputError :message="form.errors.ld_type_other" />
    </div>
    <div class="sm:col-span-2">
        <TextInput :id="`${idPrefix}-sponsor`" v-model="form.sponsor" label="Conducted/sponsored by (write in full)" required :invalid="!!form.errors.sponsor" />
        <InputError :message="form.errors.sponsor" />
    </div>
    <div>
        <TextInput :id="`${idPrefix}-venue`" v-model="form.venue" label="Venue" :invalid="!!form.errors.venue" />
        <InputError :message="form.errors.venue" />
    </div>
    <div>
        <TextInput :id="`${idPrefix}-speaker`" v-model="form.resource_speaker" label="Resource speaker/s" :invalid="!!form.errors.resource_speaker" />
        <InputError :message="form.errors.resource_speaker" />
    </div>
</template>
