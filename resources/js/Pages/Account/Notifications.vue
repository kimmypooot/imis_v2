<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Button from '../../Components/UI/Button.vue';
import Checkbox from '../../Components/Forms/Checkbox.vue';

defineOptions({ layout: PortalLayout });

/**
 * Admin-3: which notifications also come by email. Everything always
 * shows under the bell.
 */
const props = defineProps({
    kinds: { type: Array, required: true },
    email: { type: String, default: null },
});

const form = useForm({ email: Object.fromEntries(props.kinds.map((kind) => [kind.kind, kind.email])) });
const save = () => form.put('/account/notifications', { preserveScroll: true, onSuccess: () => form.defaults() });
</script>

<template>
    <Head title="Notification Settings" />

    <PageHeader
        title="Notification Settings"
        description="Everything IMIS tells you shows under the bell at the top of the page. Choose what you'd also like by email."
        :breadcrumbs="[{ label: 'Notification Settings' }]"
    />

    <Card class="max-w-2xl" title="Also send by email" icon="envelope" :description="email ? `Emails go to ${email}.` : 'Your account has no email address, so nothing can be emailed.'">
        <form class="space-y-4" @submit.prevent="save">
            <div v-for="kind in kinds" :key="kind.kind" class="rounded-md border border-border p-3">
                <Checkbox :id="`email-${kind.kind}`" v-model="form.email[kind.kind]" :label="kind.label" />
                <p class="mt-1 pl-6 text-sm text-text-muted">{{ kind.description }}</p>
            </div>

            <p class="text-xs text-text-muted">Password reset and set-password emails are always sent.</p>

            <Button type="submit" :loading="form.processing" :disabled="!form.isDirty || !email">Save</Button>
        </form>
    </Card>
</template>
