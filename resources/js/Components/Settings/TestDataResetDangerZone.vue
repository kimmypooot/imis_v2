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
 * Development only, Super Administrators: a checklist of test data to
 * delete, with how many rows each would remove now. The people, their
 * records and the setup are never on the list. Every category starts
 * ticked; untick what should stay this time. The server checks the
 * password and phrase again and takes a backup before deleting anything.
 */
const props = defineProps({
    categories: { type: Array, required: true },
    phrase: { type: String, required: true },
});

const open = ref(false);
const form = useForm({ categories: [], password: '', confirmation: '' });

const start = () => {
    form.reset();
    form.clearErrors();
    // Everything with rows starts ticked.
    form.categories = props.categories.filter((category) => category.count > 0).map((category) => category.key);
    open.value = true;
};
const close = () => {
    open.value = false;
    form.reset();
    form.clearErrors();
};

const total = computed(() => props.categories.filter((category) => form.categories.includes(category.key)).reduce((sum, category) => sum + category.count, 0));
const allTicked = computed(() => props.categories.every((category) => category.count === 0 || form.categories.includes(category.key)));
const toggleAll = () => {
    form.categories = allTicked.value ? [] : props.categories.filter((category) => category.count > 0).map((category) => category.key);
};
const ready = computed(() => form.categories.length > 0 && form.password !== '' && form.confirmation === props.phrase);

const submit = () => {
    if (!ready.value) return;

    form.delete('/admin/settings/test-data', {
        preserveScroll: true,
        onSuccess: close,
        onFinish: () => form.reset('password', 'confirmation'),
    });
};
</script>

<template>
    <Card title="Reset test data" description="Development only. Deletes the test activity you tick and keeps the people and the setup.">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="min-w-0 max-w-xl">
                <p class="text-sm text-text-muted">
                    Always kept: accounts and their pictures, employee records, the PDS, service history and the Plantilla, e-signatures,
                    loans and pay items, and all setup (offices, positions, salary schedules, leave types, roles, systems, settings).
                </p>
            </div>
            <Button variant="danger" icon="trash" @click="start">Reset test data…</Button>
        </div>
    </Card>

    <Modal :open="open" title="Reset test data" icon="trash" tone="danger" size="lg" @close="close">
        <form id="reset-test-data" class="space-y-4" @submit.prevent="submit">
            <Alert tone="danger" icon="xCircle">
                What you tick is deleted for everyone. A database backup is taken first; it is the only way back.
            </Alert>

            <fieldset>
                <div class="mb-2 flex items-center justify-between">
                    <legend class="text-sm font-medium text-text">Delete</legend>
                    <button type="button" class="text-xs font-medium text-link hover:underline" @click="toggleAll">{{ allTicked ? 'Untick all' : 'Tick all' }}</button>
                </div>
                <ul class="max-h-80 divide-y divide-border overflow-y-auto rounded-lg border border-border">
                    <li v-for="category in categories" :key="category.key">
                        <label class="flex cursor-pointer items-start gap-3 px-3 py-2.5 hover:bg-surface-muted" :class="{ 'cursor-not-allowed opacity-60': category.count === 0 }">
                            <input
                                v-model="form.categories"
                                type="checkbox"
                                class="mt-0.5 h-4 w-4 rounded border-border-strong text-danger focus:ring-focus-ring"
                                :value="category.key"
                                :disabled="category.count === 0"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="flex items-baseline justify-between gap-3">
                                    <span class="text-sm font-medium text-text">{{ category.label }}</span>
                                    <span class="shrink-0 text-xs tabular-nums text-text-muted">{{ category.count === 0 ? 'empty' : `${category.count.toLocaleString()} ${category.count === 1 ? 'row' : 'rows'}` }}</span>
                                </span>
                                <span class="block text-xs text-text-muted">{{ category.description }}</span>
                            </span>
                        </label>
                    </li>
                </ul>
                <p class="mt-2 text-xs text-text-muted">{{ form.categories.length }} of {{ categories.length }} ticked · {{ total.toLocaleString() }} rows will be deleted.</p>
                <InputError :message="form.errors.categories" class="mt-1" />
            </fieldset>

            <div>
                <PasswordInput id="reset-password" v-model="form.password" label="Confirm your password" autocomplete="current-password" required :error="form.errors.password" />
            </div>
            <div>
                <label for="reset-confirmation" class="mb-1 block text-sm font-medium text-text">
                    Type <code class="rounded bg-surface-muted px-1 text-xs">{{ phrase }}</code> to continue
                </label>
                <input
                    id="reset-confirmation"
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
            <Button type="submit" form="reset-test-data" variant="danger" icon="trash" :loading="form.processing" :disabled="!ready">
                Delete {{ total.toLocaleString() }} rows
            </Button>
        </template>
    </Modal>
</template>
