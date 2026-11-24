<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import PasswordInput from '@cscro8/ui/Components/Forms/PasswordInput.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';

/**
 * Super Administrators only: wipes the whole leave-credit ledger. Two
 * checks stand in the way, the current password and the phrase typed out;
 * the server checks both again.
 */
const props = defineProps({
    entries: { type: Number, required: true },
    phrase: { type: String, required: true },
});

const open = ref(false);
const form = useForm({ password: '', confirmation: '' });
const ready = computed(() => form.password !== '' && form.confirmation === props.phrase);

const close = () => {
    open.value = false;
    form.reset();
    form.clearErrors();
};

const submit = () => {
    if (!ready.value) return;

    form.delete('/admin/settings/leave-credits', {
        preserveScroll: true,
        onSuccess: close,
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Card title="Danger zone" description="Actions here cannot be undone. Only Super Administrators can see them.">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0 max-w-xl">
                <p class="text-sm font-medium text-text">Delete all leave credits</p>
                <p class="mt-0.5 text-sm text-text-muted">
                    Empties every employee's leave card ({{ entries.toLocaleString() }} ledger lines now) and the tardiness
                    charges that point at them. Leave applications and leave types stay. Take a backup first.
                </p>
            </div>
            <Button variant="danger" icon="trash" :disabled="entries === 0" @click="open = true">Delete all leave credits</Button>
        </div>
    </Card>

    <Modal :open="open" title="Delete all leave credits" icon="trash" tone="danger" @close="close">
        <form id="purge-leave-credits" class="space-y-4" @submit.prevent="submit">
            <Alert tone="danger" icon="xCircle">
                This truncates the leave ledger for every employee. There is no undo; the only way back is a database backup.
            </Alert>
            <div>
                <PasswordInput id="purge-password" v-model="form.password" label="Confirm your password" autocomplete="current-password" required :error="form.errors.password" />
            </div>
            <div>
                <label for="purge-confirmation" class="mb-1 block text-sm font-medium text-text">
                    Type <code class="rounded bg-surface-muted px-1 text-xs">{{ phrase }}</code> to continue
                </label>
                <input
                    id="purge-confirmation"
                    v-model="form.confirmation"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    class="w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-text"
                />
                <InputError :message="form.errors.confirmation" class="mt-1" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" icon="close" @click="close">Cancel</Button>
            <Button type="submit" form="purge-leave-credits" variant="danger" icon="trash" :loading="form.processing" :disabled="!ready">Delete everything</Button>
        </template>
    </Modal>
</template>
