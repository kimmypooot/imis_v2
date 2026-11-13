<script setup>
import InputLabel from '../Forms/InputLabel.vue';
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import Textarea from '../Forms/Textarea.vue';
import InputError from '../Forms/InputError.vue';

/**
 * HR-3b: reject an L&D entry with a reason the employee sees on their log
 * (they can fix the entry and resubmit it). `record` null = closed.
 */
const props = defineProps({
    record: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const form = useForm({ reason: '' });

watch(
    () => props.record?.id,
    (id) => {
        if (!id) return;
        form.reset();
        form.clearErrors();
    },
);

const submit = () =>
    form.post(`/admin/hr/learning-development/reviews/${props.record.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
    });
</script>

<template>
    <Modal :open="record !== null" title="Reject this training?" icon="xCircle" tone="danger" @close="emit('close')">
        <p class="text-sm text-text">
            <strong>{{ record?.title }}</strong>
            <template v-if="record?.employee"> of {{ record.employee.name }}</template>
            leaves their PDS. They'll see your reason and can fix and resubmit it.
        </p>
        <div class="mt-4">
            <InputLabel for="ld-reject-reason" required>Reason</InputLabel>
            <Textarea id="ld-reject-reason" v-model="form.reason" class="mt-1" :rows="3" required :invalid="!!form.errors.reason" />
            <InputError :message="form.errors.reason" />
        </div>
        <template #footer>
            <Button variant="secondary" @click="emit('close')" icon="close">Cancel</Button>
            <Button variant="danger" :loading="form.processing" :disabled="!form.reason.trim()" @click="submit" icon="xCircle">Reject</Button>
        </template>
    </Modal>
</template>
