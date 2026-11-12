<script setup>
import { computed } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import FormGroup from '../Forms/FormGroup.vue';
import TextInput from '../Forms/TextInput.vue';
import Textarea from '../Forms/Textarea.vue';
import InputError from '../Forms/InputError.vue';

/**
 * "Send feedback" — opened from the account menu on every page. The shell
 * keeps this mounted, so closing the dialog (or moving to another page)
 * keeps a half-written message until it is sent. The page you were on is
 * attached automatically (its path only) so you don't have to describe it.
 */
defineProps({
    open: { type: Boolean, required: true },
});

const emit = defineEmits(['close']);

const page = usePage();

const types = [
    { value: 'feedback', label: 'Feedback', hint: 'What works well, or what does not.' },
    { value: 'suggestion', label: 'Suggestion', hint: 'An idea for something new or better.' },
    { value: 'recommendation', label: 'Recommendation', hint: 'A change to how something should work.' },
];

const MAX_MESSAGE = 5000;

const form = useForm({
    type: 'feedback',
    subject: '',
    message: '',
    page_path: '',
});

const remaining = computed(() => MAX_MESSAGE - form.message.length);

const submit = () => {
    form.page_path = page.url;
    form.post('/feedback', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('close');
        },
    });
};
</script>

<template>
    <Modal :open="open" title="Send feedback" icon="chat" tone="primary" size="lg" @close="emit('close')">
        <form id="feedback-form" class="space-y-4" @submit.prevent="submit">
            <p class="text-sm text-text-muted">
                Tell us what to improve in IMIS. Administrators read every message and may reply — you will see replies under
                <Link href="/my/feedback" class="text-primary underline" @click="emit('close')">My feedback</Link>.
            </p>

            <fieldset>
                <legend class="mb-1 text-sm font-medium text-text">What kind is it?</legend>
                <div class="grid gap-2 sm:grid-cols-3">
                    <label
                        v-for="type in types"
                        :key="type.value"
                        class="cursor-pointer rounded-md border p-2.5 text-sm has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-focus-ring"
                        :class="form.type === type.value ? 'border-primary bg-info-bg' : 'border-border-strong'"
                    >
                        <input v-model="form.type" type="radio" name="feedback-type" :value="type.value" class="sr-only" />
                        <span class="block font-medium text-text">{{ type.label }}</span>
                        <span class="block text-xs text-text-muted">{{ type.hint }}</span>
                    </label>
                </div>
                <InputError :message="form.errors.type" />
            </fieldset>

            <div>
                <TextInput id="feedback-subject" v-model="form.subject" label="Subject" required :error="form.errors.subject" />
            </div>

            <FormGroup
                id="feedback-message"
                label="Your message"
                required
                :error="form.errors.message"
                :helper-text="`${remaining} characters left. Please don't include passwords or other people's personal details.`"
            >
                <Textarea id="feedback-message" v-model="form.message" :rows="6" required :invalid="!!form.errors.message" />
            </FormGroup>

            <p class="text-xs text-text-muted">
                Sent with your name and the page you are on ({{ page.url.split('?')[0] }}).
            </p>
        </form>

        <template #footer>
            <Button variant="secondary" @click="emit('close')">Not now</Button>
            <Button type="submit" form="feedback-form" :loading="form.processing">Send</Button>
        </template>
    </Modal>
</template>
