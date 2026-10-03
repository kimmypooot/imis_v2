<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '../../Feedback/Modal.vue';
import Button from '../../UI/Button.vue';
import PasswordInput from '../../Forms/PasswordInput.vue';
import InputError from '../../Forms/InputError.vue';

/**
 * The e-signature (CS Form No. 212, item 42): accept the declaration
 * under oath and re-enter your password. The server then freezes the PDS
 * as a signed submission for HR.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
});

const emit = defineEmits(['close']);

const form = useForm({ declaration: false, password: '' });

watch(
    () => props.open,
    (open) => {
        if (open) {
            form.reset();
            form.clearErrors();
        }
    },
);

const sign = () =>
    form.post('/my/pds/submit', {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onFinish: () => form.reset('password'),
    });
</script>

<template>
    <Modal :open="open" title="Sign and submit your PDS" icon="pencil" tone="primary" size="lg" @close="emit('close')">
        <form id="pds-sign-form" class="space-y-4" @submit.prevent="sign">
            <blockquote class="rounded-md border-l-4 border-primary bg-info-bg px-4 py-3 text-sm text-text">
                I declare under oath that I have personally accomplished this Personal Data Sheet which is a true, correct, and complete
                statement pursuant to the provisions of pertinent laws, rules, and regulations of the Republic of the Philippines. I authorize
                the agency head/authorized representative to verify/validate the contents stated herein. I agree that any misrepresentation
                made in this document and its attachments shall cause the filing of administrative/criminal case/s against me.
            </blockquote>

            <div>
                <label class="flex items-start gap-2 text-sm font-medium text-text">
                    <input v-model="form.declaration" type="checkbox" class="mt-0.5 h-4 w-4 rounded border-border text-primary focus:ring-2 focus:ring-focus-ring" />
                    I have read and accept this declaration.
                </label>
                <InputError :message="form.errors.declaration" />
            </div>

            <div>
                <PasswordInput id="pds-sign-password" v-model="form.password" label="Your IMIS password" autocomplete="current-password" required :invalid="!!form.errors.password" />
                <p class="mt-1 text-xs text-text-muted">Re-entering your password is your electronic signature. It's recorded with the date and time.</p>
                <InputError :message="form.errors.password" />
            </div>
        </form>

        <template #footer>
            <Button variant="secondary" @click="emit('close')">Cancel</Button>
            <Button type="submit" form="pds-sign-form" :loading="form.processing" :disabled="!form.declaration || !form.password">Sign and submit</Button>
        </template>
    </Modal>
</template>
