<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Table from '../../../Components/UI/Table.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Button from '../../../Components/UI/Button.vue';
import Icon from '../../../Components/UI/Icon.vue';
import FileInput from '../../../Components/Forms/FileInput.vue';
import FormGroup from '../../../Components/Forms/FormGroup.vue';
import Checkbox from '../../../Components/Forms/Checkbox.vue';

defineOptions({ layout: AdminLayout });

/**
 * Admin-3: import staff accounts from a CSV. Step 1 uploads and checks
 * every row; step 2 creates the valid ones, each exactly as Add User
 * would (employee record, set-password email, audit). Office designations
 * and the Super Administrator role are set one account at a time.
 */
const props = defineProps({
    columns: { type: Array, required: true },
    maxRows: { type: Number, required: true },
    preview: { type: Object, default: null },
    result: { type: Object, default: null },
});

const upload = useForm({ file: null });
const check = () => upload.post('/users/import/preview', { forceFormData: true, preserveScroll: true });

const sendLinks = ref(true);
const importing = ref(false);
const runImport = () => {
    importing.value = true;
    router.post('/users/import', { token: props.preview.token, send_links: sendLinks.value }, { preserveScroll: true, onFinish: () => (importing.value = false) });
};

const showErrorsOnly = ref(false);
const rows = computed(() => (props.preview ? props.preview.rows.filter((row) => !showErrorsOnly.value || row.errors.length) : []));
</script>

<template>
    <Head title="Import Users" />

    <PageHeader
        title="Import Users"
        description="Create many staff accounts from a spreadsheet saved as CSV. Nothing is created until you check the preview and confirm."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Manage Users', href: '/users' }, { label: 'Import' }]"
    />

    <div class="space-y-6">
        <Card v-if="result" title="Import finished" icon="checkCircle">
            <p class="text-sm text-text">
                {{ result.created.length }} {{ result.created.length === 1 ? 'account' : 'accounts' }} created.
                <template v-if="result.skipped.length">{{ result.skipped.length }} skipped because they no longer passed the checks.</template>
            </p>
            <ul v-if="result.skipped.length" class="mt-2 list-disc pl-5 text-sm text-danger-fg">
                <li v-for="row in result.skipped" :key="row.line">Line {{ row.line }}: {{ row.errors.join(' ') }}</li>
            </ul>
            <template #footer>
                <Button size="sm" variant="secondary" href="/users">Back to Manage Users</Button>
            </template>
        </Card>

        <Card title="1. Upload the file" icon="clipboard">
            <div class="space-y-3 text-sm text-text">
                <p>
                    One person per row, up to {{ maxRows }} per file. Columns:
                    <code class="text-xs">{{ columns.join(', ') }}</code>.
                </p>
                <ul class="list-disc space-y-1 pl-5 text-text-muted">
                    <li><strong class="text-text">Required:</strong> first_name, last_name and email.</li>
                    <li><strong class="text-text">sex:</strong> male or female · <strong class="text-text">birthday:</strong> YYYY-MM-DD.</li>
                    <li><strong class="text-text">office:</strong> the office's code, short name or name, as in Organizational Units.</li>
                    <li><strong class="text-text">username:</strong> leave blank to make one the usual way (initials and surname).</li>
                    <li><strong class="text-text">roles:</strong> role names separated by semicolons. Super Administrator and office designations can't be imported.</li>
                </ul>
                <Button size="sm" variant="outline" href="/users/import/template" external>
                    <Icon name="download" size="sm" />
                    Download the template
                </Button>
            </div>

            <form class="mt-5 flex flex-wrap items-end gap-3" @submit.prevent="check">
                <FormGroup id="import-file" label="CSV file" :error="upload.errors.file" class="min-w-64 flex-1">
                    <FileInput id="import-file" v-model="upload.file" accept=".csv,text/csv" required :invalid="!!upload.errors.file" />
                </FormGroup>
                <Button type="submit" :loading="upload.processing" :disabled="!upload.file">Check the file</Button>
            </form>
        </Card>

        <Card v-if="preview" title="2. Check and import" icon="eye">
            <template #actions>
                <Badge tone="success">{{ preview.valid }} ready</Badge>
                <Badge v-if="preview.invalid" tone="danger">{{ preview.invalid }} with problems</Badge>
            </template>

            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <Checkbox v-if="preview.invalid" id="errors-only" v-model="showErrorsOnly" label="Show only rows with problems" />
                <p class="text-xs text-text-muted">Rows with problems are skipped. Fix them in the file and upload it again to add them.</p>
            </div>

            <Table>
                <thead>
                    <tr>
                        <th>Line</th>
                        <th>Name</th>
                        <th>Email</th>
                        <th>Username</th>
                        <th>Office</th>
                        <th>Roles</th>
                        <th>Check</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.line" :class="row.errors.length ? 'bg-danger-bg/40' : ''">
                        <td>{{ row.line }}</td>
                        <td class="font-medium">{{ row.name }}</td>
                        <td>{{ row.email }}</td>
                        <td>
                            <code class="text-xs">{{ row.username ?? '—' }}</code>
                            <span v-if="row.username_generated" class="block text-xs text-text-muted">made from the name</span>
                        </td>
                        <td>{{ row.office ?? '—' }}</td>
                        <td>{{ row.roles.join(', ') || '—' }}</td>
                        <td>
                            <span v-if="!row.errors.length" class="inline-flex items-center gap-1 text-success-fg">
                                <Icon name="check" size="sm" />
                                Ready
                            </span>
                            <ul v-else class="space-y-0.5 text-xs text-danger-fg">
                                <li v-for="error in row.errors" :key="error">{{ error }}</li>
                            </ul>
                        </td>
                    </tr>
                </tbody>
            </Table>

            <template #footer>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <Checkbox id="send-links" v-model="sendLinks" label="Email each person a link to set their password" />
                    <Button variant="success" :loading="importing" :disabled="!preview.valid" @click="runImport">
                        <Icon name="userPlus" size="sm" />
                        Create {{ preview.valid }} {{ preview.valid === 1 ? 'account' : 'accounts' }}
                    </Button>
                </div>
            </template>
        </Card>
    </div>
</template>
