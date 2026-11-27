<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import Checkbox from '@cscro8/ui/Components/Forms/Checkbox.vue';
import FormGroup from '@cscro8/ui/Components/Forms/FormGroup.vue';

/**
 * Calendar of Activities: move an activity to a new date (and time), with
 * the reason, which the calendar keeps in its move history. A month-only
 * activity given its date this way is simply scheduled (no reason needed).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    // The activity (details JSON), or null.
    activity: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({ starts_on: '', ends_on: '', all_day: false, starts_at: '', ends_at: '', move_reason: '' });

watch(
    () => [props.open, props.activity],
    ([open]) => {
        if (!open || !props.activity) return;
        const activity = props.activity;
        form.clearErrors();
        form.defaults({
            starts_on: activity.starts_on ?? '',
            ends_on: activity.ends_on && activity.ends_on !== activity.starts_on ? activity.ends_on : '',
            all_day: activity.all_day ?? false,
            starts_at: activity.starts_at ?? '',
            ends_at: activity.ends_at ?? '',
            move_reason: '',
        });
        form.reset();
    },
    { immediate: true },
);

const dated = computed(() => !!props.activity?.starts_on);

const submit = () => {
    form.transform((data) => ({
        starts_on: data.starts_on,
        ends_on: data.ends_on || null,
        all_day: !!data.all_day,
        starts_at: data.all_day ? null : data.starts_at || null,
        ends_at: data.all_day ? null : data.ends_at || null,
        move_reason: data.move_reason || null,
    })).post(`/activities/${props.activity.id}/move`, { preserveScroll: true, onSuccess: () => emit('close') });
};
</script>

<template>
    <Modal :open="open" :title="activity ? `Move “${activity.title}”` : 'Move activity'" tone="primary" icon="calendar" size="md" @close="emit('close')">
        <form id="move-activity-form" class="space-y-4" novalidate @submit.prevent="submit">
            <Alert v-if="form.errors.activity" tone="danger">{{ form.errors.activity }}</Alert>
            <div class="grid gap-4 sm:grid-cols-2">
                <FormGroup id="move-starts-on" label="New date" required :error="form.errors.starts_on">
                    <input id="move-starts-on" v-model="form.starts_on" type="date" required data-autofocus class="block w-full form-control px-3 py-2" :aria-invalid="!!form.errors.starts_on" />
                </FormGroup>
                <FormGroup id="move-ends-on" label="Last date" helper-text="For several days." :error="form.errors.ends_on">
                    <input id="move-ends-on" v-model="form.ends_on" type="date" :min="form.starts_on || undefined" class="block w-full form-control px-3 py-2" :aria-invalid="!!form.errors.ends_on" />
                </FormGroup>
            </div>
            <Checkbox id="move-all-day" v-model="form.all_day" label="Whole day" />
            <div v-if="!form.all_day" class="grid gap-4 sm:grid-cols-2">
                <FormGroup id="move-starts-at" label="Start time" :error="form.errors.starts_at">
                    <input id="move-starts-at" v-model="form.starts_at" type="time" class="block w-full form-control px-3 py-2" />
                </FormGroup>
                <FormGroup id="move-ends-at" label="End time" :error="form.errors.ends_at">
                    <input id="move-ends-at" v-model="form.ends_at" type="time" class="block w-full form-control px-3 py-2" />
                </FormGroup>
            </div>
            <TextInput
                id="move-reason"
                v-model="form.move_reason"
                label="Reason"
                :required="dated"
                :helper-text="dated ? 'Shown in the activity\'s history and on the printed sheet.' : 'Optional: this activity had only a month.'"
                :maxlength="255"
                :error="form.errors.move_reason"
            />
        </form>
        <template #footer>
            <Button variant="secondary" @click="emit('close')">Cancel</Button>
            <Button type="submit" form="move-activity-form" :loading="form.processing">Move</Button>
        </template>
    </Modal>
</template>
