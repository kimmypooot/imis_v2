<script setup>
import { onMounted } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import AccountLayout from '../../Layouts/AccountLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import PasswordInput from '@cscro8/ui/Components/Forms/PasswordInput.vue';

defineOptions({ layout: [PortalLayout, AccountLayout] });

/**
 * RA 10173: download a copy of everything IMIS holds about you. Confirm
 * your password; the file is built when it is fetched from a link that
 * works for two minutes.
 */
const props = defineProps({
    downloadUrl: { type: String, default: null },
});

const form = useForm({ password: '' });

const prepare = () => form.post('/my/data-export', { preserveScroll: true, onSuccess: () => form.reset() });

// A plain navigation, so the browser saves the file and the page stays put.
const download = () => {
    if (props.downloadUrl) window.location.assign(props.downloadUrl);
};

onMounted(download);
</script>

<template>
    <Head title="Download my data" />

    <PageHeader
        title="Download my data"
        description="A copy of what IMIS holds about you: your account, employee record, Personal Data Sheet, service record, training, leave and payroll."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Download my data' }]"
    />

    <Card title="Get my copy" icon="database">
        <p class="mb-3 max-w-2xl text-sm text-text-muted">
            You get a ZIP file with <strong>data.html</strong> to read and <strong>data.json</strong> for another system.
            It holds personal data, so keep it somewhere safe. Attachments and other people's remarks are not included.
            To correct something in it, use <a href="/my/corrections" class="text-link hover:underline">Request a correction</a>.
        </p>

        <form class="max-w-sm space-y-3" @submit.prevent="prepare">
            <PasswordInput id="export-password" v-model="form.password" label="Your password" autocomplete="current-password" required :error="form.errors.password" />
            <Button type="submit" :disabled="form.processing">Prepare my download</Button>
        </form>

        <div v-if="downloadUrl" class="mt-4 rounded-md border border-success-border bg-success-bg p-3 text-sm text-success-fg">
            Your download is ready and starts automatically.
            <button type="button" class="font-medium underline" @click="download">Download again</button>
            <span class="text-text-muted">(the link works for two minutes)</span>
        </div>
    </Card>
</template>
