<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../Components/UI/AdminCard.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import Button from '../../../Components/UI/Button.vue';
import Icon from '../../../Components/UI/Icon.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
import PasswordInput from '../../../Components/Forms/PasswordInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import { toast } from '../../../Composables/useDialog';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Backup Database" page. Downloading needs the current
 * password again; the file is fetched (not an Inertia visit) so it can be
 * saved directly, with a wrong password reported inline.
 */
const props = defineProps({
    tables: { type: Array, required: true },
    connection: { type: Object, required: true },
});

const password = ref('');
const error = ref(null);
const downloading = ref(false);

const totalRows = computed(() => props.tables.reduce((sum, table) => sum + (table.rows ?? 0), 0));

const xsrfToken = () => decodeURIComponent(document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

const download = async () => {
    error.value = null;
    downloading.value = true;

    try {
        const response = await fetch('/admin/backup', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
            body: JSON.stringify({ password: password.value }),
        });

        if (response.status === 422) {
            error.value = (await response.json()).errors?.password?.[0] ?? 'Please check the password.';

            return;
        }
        if (response.status === 429) {
            error.value = 'Too many backups requested. Please wait a few minutes.';

            return;
        }
        if (!response.ok) throw new Error(String(response.status));

        const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? 'imis-backup.sql';
        const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(await response.blob()), download: name });
        link.click();
        URL.revokeObjectURL(link.href);
        password.value = '';
        toast.success(`Backup downloaded (${name}). Store it somewhere safe — it contains every record in IMIS.`);
    } catch {
        toast.error('The backup could not be created. Please try again.');
    } finally {
        downloading.value = false;
    }
};

const columns = [
    { key: 'name', label: 'Table' },
    { key: 'rows', label: 'Rows', align: 'right', value: (table) => (table.structure_only ? '' : table.rows) },
    { key: 'structure_only', label: 'Exported', sortable: false, align: 'center', value: (table) => (table.structure_only ? 'Structure only' : 'Structure and data') },
];
</script>

<template>
    <Head title="Backup Database" />

    <PageHeader title="Backup Database" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Backup Database' }]" />

    <div class="grid items-start gap-6 xl:grid-cols-12">
        <AdminCard title="Download a Backup" icon="database" class="xl:col-span-5">
            <div class="space-y-4">
                <p class="text-sm text-text-muted">
                    A full SQL backup of <strong class="text-text">{{ connection.database }}</strong> ({{ connection.driver }}):
                    {{ tables.length }} tables, {{ totalRows.toLocaleString() }} rows. It can be restored with any MySQL client.
                </p>
                <Alert tone="warning">
                    The file contains every record in IMIS, including password hashes. Keep it somewhere secure and
                    never share it. Every download is recorded in the audit log.
                </Alert>
                <p class="text-xs text-text-muted">
                    Left out on purpose: sessions, cache, queued jobs, password-reset tokens, and "remember me" tokens
                    (restoring signs everyone out).
                </p>
                <form class="space-y-3" @submit.prevent="download">
                    <div>
                        <PasswordInput id="backup-password" v-model="password" label="Confirm your password" autocomplete="current-password" required :invalid="!!error" />
                        <InputError :message="error" />
                    </div>
                    <Button type="submit" variant="success" :loading="downloading" :disabled="!password">
                        <Icon name="download" size="sm" />
                        Download backup
                    </Button>
                </form>
            </div>
        </AdminCard>

        <AdminCard title="What's Included" icon="clipboard" class="xl:col-span-7">
            <DataTable :columns="columns" :rows="tables" row-key="name" caption="Tables in the backup" :exportable="false" :numbered="false" :default-page-size="50">
                <template #cell-name="{ row }"><code class="text-xs">{{ row.name }}</code></template>
                <template #cell-rows="{ row }">
                    <span class="tabular-nums">{{ row.structure_only ? '—' : row.rows.toLocaleString() }}</span>
                </template>
                <template #cell-structure_only="{ row }">
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="row.structure_only ? 'bg-surface-muted text-text-muted' : 'bg-success-bg text-success'">
                        {{ row.structure_only ? 'Structure only' : 'Structure and data' }}
                    </span>
                </template>
            </DataTable>
        </AdminCard>
    </div>
</template>
