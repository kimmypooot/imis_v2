<script setup>
import { ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import CellFacts from '@cscro8/ui/Components/UI/CellFacts.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Alert from '@cscro8/ui/Components/Feedback/Alert.vue';
import FormGroup from '@cscro8/ui/Components/Forms/FormGroup.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import { formatDate, formatDateTime, timeAgo } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The bearer tokens connected systems use to call IMIS's APIs. A token is
 * shown once, right after it is issued. The old single secret in `.env`
 * keeps working alongside these until it is removed there.
 */
const props = defineProps({
    integrations: { type: Array, required: true },
    issued: { type: Object, default: null },
});

const modalOpen = ref(false);
const form = useForm({ integration: '', name: '', expires_at: '' });

const openIssue = (integration = '') => {
    form.reset();
    form.clearErrors();
    form.integration = integration;
    // A year by default; clear the date for a token that never expires.
    const nextYear = new Date();
    nextYear.setFullYear(nextYear.getFullYear() + 1);
    form.expires_at = nextYear.toISOString().slice(0, 10);
    modalOpen.value = true;
};

const issue = () => {
    form.transform((data) => ({ ...data, expires_at: data.expires_at || null })).post('/admin/api-tokens', {
        preserveScroll: true,
        onSuccess: () => (modalOpen.value = false),
    });
};

const revoke = async (token) => {
    const confirmed = await confirm({
        title: `Revoke "${token.name}"?`,
        text: 'The system using this token is locked out at once. This cannot be undone; issue a new token to reconnect it.',
        tone: 'danger',
        confirmText: 'Revoke',
    });

    if (confirmed) router.delete(`/admin/api-tokens/${token.id}`, { preserveScroll: true });
};

// The token is visible until the dialog is closed; closing it hides it for good.
const showIssued = ref(false);
const copied = ref(false);

watch(
    () => props.issued,
    (value) => {
        showIssued.value = value !== null;
        copied.value = false;
    },
    { immediate: true },
);


const tokenColumns = [
    { key: 'name', label: 'Name' },
    { key: 'status', label: 'Status' },
    { key: 'dates', label: 'Expires & issued', sortable: false },
    { key: 'last_used_at', label: 'Last used', value: (row) => row.last_used_at ?? '' },
    { key: 'actions', label: '', sortable: false, align: 'right' },
];
const copy = async () => {
    try {
        await navigator.clipboard.writeText(props.issued.token);
        copied.value = true;
    } catch {
        copied.value = false;
    }
};
</script>

<template>
    <Head title="Integration Tokens" />

    <PageHeader
        title="Integration Tokens"
        description="The tokens connected systems send to read IMIS's directory and employee feeds. Issue one per system, rotate by issuing a new one and revoking the old."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Integration Tokens' }]"
    >
        <template #actions>
            <Button variant="success" class="rounded-full" @click="openIssue()">
                <Icon name="plus" size="sm" />
                Issue Token
            </Button>
        </template>
    </PageHeader>

    <div class="space-y-4">
        <Card v-for="integration in integrations" :key="integration.value" :title="integration.label" icon="key">
            <template #actions>
                <Button variant="secondary" size="sm" @click="openIssue(integration.value)">Issue token</Button>
            </template>

            <p v-if="integration.legacy_secret" class="mb-3 text-sm text-text-muted">
                The shared secret in <code class="font-mono">.env</code> is still accepted. Once the system uses an issued token, remove it there.
            </p>

            <DataTable
                :columns="tokenColumns"
                :rows="integration.tokens"
                :caption="`Tokens issued for ${integration.label}`"
                :export-name="`imis-tokens-${integration.value}`"
                :numbered="false"
                :searchable="false"
                :exportable="false"
                empty-text="No issued tokens."
            >
                <template #cell-name="{ row }">
                    <span class="font-medium text-text">{{ row.name }}</span>
                    <div class="font-mono text-xs text-text-muted">{{ row.hint }}…</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
                <template #cell-dates="{ row }">
                    <CellFacts
                        :facts="[
                            { label: 'Expires', value: row.expires_at ? formatDate(row.expires_at) : 'Never' },
                            { label: 'Issued', value: formatDate(row.created_at), note: row.created_by ? `by ${row.created_by}` : null },
                        ]"
                    />
                    <p v-if="row.expires_soon" class="mt-1 text-xs font-medium text-warning-fg">Expires soon: issue a new token and revoke this one</p>
                </template>
                <template #cell-last_used_at="{ row }">
                    <span v-if="row.last_used_at" :title="`${formatDateTime(row.last_used_at)} from ${row.last_used_ip ?? 'unknown'}`">{{ timeAgo(row.last_used_at) }}</span>
                    <span v-else class="text-text-muted">Never</span>
                </template>
                <template #cell-actions="{ row }">
                    <span class="inline-flex"><ActionButton v-if="row.status !== 'revoked'" icon="trash" tone="danger" :label="`Revoke ${row.name}`" @click="revoke(row)" /></span>
                </template>
            </DataTable>
        </Card>
    </div>

    <Modal :open="modalOpen" title="Issue Token" tone="success" icon="key" @close="modalOpen = false">
        <form id="issue-token-form" class="space-y-4" @submit.prevent="issue">
            <FormGroup id="token-integration" label="System" required :error="form.errors.integration">
                <Select
                    id="token-integration"
                    v-model="form.integration"
                    :options="[{ value: '', label: 'Choose a system…' }, ...integrations.map((item) => ({ value: item.value, label: item.label }))]"
                />
            </FormGroup>
            <TextInput id="token-name" v-model="form.name" label="Name" required helper-text="What this token is for, e.g. Production server." :error="form.errors.name" />
            <TextInput id="token-expires" v-model="form.expires_at" type="date" label="Expires on" helper-text="Defaults to one year, so tokens get rotated. Clear the date for a token that does not expire." :error="form.errors.expires_at" />
        </form>
        <template #footer>
            <Button variant="secondary" @click="modalOpen = false">Cancel</Button>
            <Button type="submit" form="issue-token-form" variant="success" :disabled="form.processing">Issue token</Button>
        </template>
    </Modal>

    <Modal :open="showIssued && issued !== null" title="Copy your new token" tone="primary" icon="key" @close="showIssued = false">
        <div v-if="issued" class="space-y-3">
            <Alert tone="warning" title="This is the only time it is shown.">
                Copy it now and give it to {{ issued.integration }}. IMIS keeps only a fingerprint and cannot show it again.
            </Alert>
            <p class="text-sm text-text-muted">{{ issued.name }}</p>
            <code class="block break-all rounded-md border border-border bg-surface-muted p-3 font-mono text-sm text-text">{{ issued.token }}</code>
        </div>
        <template #footer>
            <Button variant="secondary" @click="copy">{{ copied ? 'Copied' : 'Copy token' }}</Button>
            <Button @click="showIssued = false">Done</Button>
        </template>
    </Modal>
</template>
