<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import Button from '../../../Components/UI/Button.vue';
import Icon from '../../../Components/UI/Icon.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
import Modal from '../../../Components/Feedback/Modal.vue';
import PasswordInput from '../../../Components/Forms/PasswordInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import { confirm, toast } from '../../../Composables/useDialog';
import { formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Backup Database" page, plus the backups kept on the server
 * (nightly, or "Back up now"). Downloading needs the current password
 * again; files are fetched (not an Inertia visit) so they can be saved
 * directly, with a wrong password reported inline.
 */
const props = defineProps({
    tables: { type: Array, required: true },
    connection: { type: Object, required: true },
    backups: { type: Array, default: () => [] },
    schedule: { type: Object, required: true },
});

const totalRows = computed(() => props.tables.reduce((sum, table) => sum + (table.rows ?? 0), 0));

const xsrfToken = () => decodeURIComponent(document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

/**
 * POSTs the password to `url` and saves the file it returns. Resolves to
 * an error message for the password field, or null on success.
 */
const fetchFile = async (url, password, fallbackName) => {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify({ password }),
    });

    if (response.status === 422) return (await response.json()).errors?.password?.[0] ?? 'Please check the password.';
    if (response.status === 429) return 'Too many downloads requested. Please wait a few minutes.';
    if (!response.ok) throw new Error(String(response.status));

    const name = /filename="?([^";]+)"?/.exec(response.headers.get('Content-Disposition') ?? '')?.[1] ?? fallbackName;
    const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(await response.blob()), download: name });
    link.click();
    URL.revokeObjectURL(link.href);
    toast.success(`Backup downloaded (${name}). Store it somewhere safe — it contains every record in IMIS.`);

    return null;
};

// ── Download a fresh dump ────────────────────────────────────────────
const password = ref('');
const error = ref(null);
const downloading = ref(false);

const download = async () => {
    error.value = null;
    downloading.value = true;
    try {
        error.value = await fetchFile('/admin/backup', password.value, 'imis-backup.sql');
        if (!error.value) password.value = '';
    } catch {
        toast.error('The backup could not be created. Please try again.');
    } finally {
        downloading.value = false;
    }
};

// ── Backups on the server ────────────────────────────────────────────
const saving = ref(false);
const backUpNow = () => {
    router.post('/admin/backup/files', {}, {
        preserveScroll: true,
        onStart: () => (saving.value = true),
        onFinish: () => (saving.value = false),
    });
};

const pending = ref(null);
const storedPassword = ref('');
const storedError = ref(null);
const storedDownloading = ref(false);

const openDownload = (backup) => {
    pending.value = backup;
    storedPassword.value = '';
    storedError.value = null;
};

const downloadStored = async () => {
    storedError.value = null;
    storedDownloading.value = true;
    try {
        storedError.value = await fetchFile(`/admin/backup/files/${encodeURIComponent(pending.value.name)}/download`, storedPassword.value, pending.value.name);
        if (!storedError.value) pending.value = null;
    } catch {
        toast.error('The backup could not be downloaded. Please try again.');
    } finally {
        storedDownloading.value = false;
    }
};

const remove = async (backup) => {
    const ok = await confirm({
        title: 'Delete this backup?',
        text: `${backup.name} will be deleted from the server. This can't be undone.`,
        tone: 'danger',
        confirmText: 'Delete',
    });
    if (ok) router.delete(`/admin/backup/files/${encodeURIComponent(backup.name)}`, { preserveScroll: true });
};

const formatSize = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);

const backupColumns = [
    { key: 'created_at', label: 'Created' },
    { key: 'automatic', label: 'Type', value: (backup) => (backup.automatic ? 'Automatic' : 'Manual') },
    { key: 'size', label: 'Size', align: 'right' },
    { key: 'actions', label: 'Actions', sortable: false, align: 'right' },
];

const columns = [
    { key: 'name', label: 'Table' },
    { key: 'rows', label: 'Rows', align: 'right', value: (table) => (table.structure_only ? '' : table.rows) },
    { key: 'structure_only', label: 'Exported', sortable: false, align: 'center', value: (table) => (table.structure_only ? 'Structure only' : 'Structure and data') },
];
</script>

<template>
    <Head title="Backup Database" />

    <PageHeader title="Backup Database" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Backup Database' }]" />

    <Alert tone="warning" class="mb-6">
        Encrypted PDS identity numbers, items 34–40 and signed submissions can only be read with this server's
        <code>APP_KEY</code>. Keep a copy of the key somewhere safe and separate from the backups: a backup restored
        without the same key loses that data.
    </Alert>

    <div class="grid items-start gap-6 xl:grid-cols-12">
        <Card title="Download a Backup" icon="database" class="xl:col-span-5">
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
                        <PasswordInput id="backup-password" v-model="password" label="Confirm your password" autocomplete="current-password" required :error="error" />
                    </div>
                    <Button type="submit" variant="success" :loading="downloading" :disabled="!password">
                        <Icon name="download" size="sm" />
                        Download backup
                    </Button>
                </form>
            </div>
        </Card>

        <Card title="Backups on the Server" icon="clock" class="xl:col-span-7">
            <div class="space-y-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-text-muted">
                        <template v-if="schedule.enabled">
                            A compressed backup is saved every night at 02:00; the newest {{ schedule.keep }} are kept.
                        </template>
                        <template v-else>
                            Nightly backups are <strong class="text-danger">off</strong>. Only backups made here are saved
                            (newest {{ schedule.keep }} kept).
                        </template>
                        <Link href="/admin/settings" class="text-primary underline">Change in System Settings</Link>
                    </p>
                    <Button variant="primary" :loading="saving" @click="backUpNow">
                        <Icon name="database" size="sm" />
                        Back up now
                    </Button>
                </div>

                <DataTable :columns="backupColumns" :rows="backups" row-key="name" caption="Backups kept on the server" :exportable="false" :numbered="false" empty-text="No backups on the server yet.">
                    <template #cell-created_at="{ row }">
                        <span class="whitespace-nowrap">{{ formatDateTime(row.created_at) }}</span>
                        <code class="block text-xs text-text-muted">{{ row.name }}</code>
                    </template>
                    <template #cell-size="{ row }"><span class="tabular-nums">{{ formatSize(row.size) }}</span></template>
                    <template #cell-actions="{ row }">
                        <div class="flex justify-end gap-2">
                            <Button size="sm" variant="secondary" icon-only :aria-label="`Download ${row.name}`" @click="openDownload(row)">
                                <Icon name="download" size="sm" />
                            </Button>
                            <Button size="sm" variant="danger" icon-only :aria-label="`Delete ${row.name}`" @click="remove(row)">
                                <Icon name="trash" size="sm" />
                            </Button>
                        </div>
                    </template>
                </DataTable>
                <p class="text-xs text-text-muted">
                    These stay on the same server as IMIS. Download one to other storage regularly, so a disk failure
                    doesn't take the backups with it.
                </p>
            </div>
        </Card>

        <Card title="What's Included" icon="clipboard" class="xl:col-span-12">
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
        </Card>
    </div>

    <Modal :open="pending !== null" title="Download backup" icon="download" tone="primary" @close="pending = null">
        <form id="stored-download" class="space-y-3" @submit.prevent="downloadStored">
            <p class="text-sm text-text">
                <code class="text-xs">{{ pending?.name }}</code> contains every record in IMIS. The download is recorded in
                the audit log.
            </p>
            <div>
                <PasswordInput id="stored-backup-password" v-model="storedPassword" label="Confirm your password" autocomplete="current-password" required :error="storedError" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="pending = null">Cancel</Button>
            <Button type="submit" form="stored-download" variant="success" :loading="storedDownloading" :disabled="!storedPassword">Download</Button>
        </template>
    </Modal>
</template>
