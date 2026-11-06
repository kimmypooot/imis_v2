<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import Alert from '../../../../Components/Feedback/Alert.vue';
import Modal from '../../../../Components/Feedback/Modal.vue';
import Button from '../../../../Components/UI/Button.vue';
import Textarea from '../../../../Components/Forms/Textarea.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import DocumentList from '../../../../Components/HR/DocumentList.vue';
import DocumentUploadForm from '../../../../Components/HR/DocumentUploadForm.vue';
import { formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-2c: an employee's 201 file for HR — documents (add, archive with a
 * reason, restore) and the verified PDS history. Nothing is ever deleted.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    documents: { type: Array, required: true },
    pdsHistory: { type: Array, required: true },
    typeOptions: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
    maxKilobytes: { type: Number, required: true },
});

const base = computed(() => `/admin/hr/employees/${props.employee.id}/documents`);

const showArchived = ref(false);
const active = computed(() => props.documents.filter((document) => !document.archived_at));
const archived = computed(() => props.documents.filter((document) => document.archived_at));

const archiving = ref(null);
const archiveForm = useForm({ reason: '' });

const openArchive = (document) => {
    archiveForm.reset();
    archiveForm.clearErrors();
    archiving.value = document;
};

const archive = () =>
    archiveForm.patch(`${base.value}/${archiving.value.id}/archive`, {
        preserveScroll: true,
        onSuccess: () => (archiving.value = null),
    });

const restore = (document) => router.patch(`${base.value}/${document.id}/restore`, {}, { preserveScroll: true });

const breadcrumbs = computed(() => [
    { label: 'Human Resources', href: '/admin/hr' },
    { label: 'Employees', href: '/admin/hr/employees' },
    { label: props.employee.name, href: `/admin/hr/employees/${props.employee.id}` },
    { label: '201 File' },
]);
</script>

<template>
    <Head :title="`201 File — ${employee.name}`" />

    <PageHeader :title="`201 File — ${employee.name}`" :breadcrumbs="breadcrumbs" />

    <Alert tone="info" class="mb-6">
        Personal records under the Data Privacy Act. Opening or downloading a document is logged. Documents are never deleted — archive a
        wrong or outdated one instead.
    </Alert>

    <div class="space-y-6">
        <Card v-if="canManage" title="Add a document" icon="plus">
            <DocumentUploadForm :url="base" :type-options="typeOptions" :max-kilobytes="maxKilobytes" />
        </Card>

        <Card title="Documents" icon="clipboard" :description="`${active.length} current`">
            <template v-if="archived.length" #actions>
                <label class="flex items-center gap-2 text-sm text-text">
                    <input v-model="showArchived" type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-primary focus:ring-2 focus:ring-focus-ring" />
                    Show archived ({{ archived.length }})
                </label>
            </template>

            <DocumentList :documents="showArchived ? [...active, ...archived] : active" empty="No documents in this 201 file yet.">
                <template v-if="canManage" #actions="{ document }">
                    <button v-if="!document.archived_at" type="button" class="text-danger hover:underline" @click="openArchive(document)">
                        Archive<span class="sr-only"> {{ document.type_label }}</span>
                    </button>
                    <button v-else type="button" class="text-success hover:underline" @click="restore(document)">
                        Restore<span class="sr-only"> {{ document.type_label }}</span>
                    </button>
                </template>
            </DocumentList>
        </Card>

        <Card title="Verified PDS history" icon="checkCircle" description="Each verified Personal Data Sheet, exactly as signed.">
            <p v-if="pdsHistory.length === 0" class="text-sm text-text-muted">No verified PDS yet.</p>
            <ul v-else class="divide-y divide-border text-sm">
                <li v-for="item in pdsHistory" :key="item.id" class="flex flex-wrap items-center justify-between gap-3 py-2 first:pt-0 last:pb-0">
                    <span class="text-text">Signed {{ formatDateTime(item.signed_at) }} · verified by {{ item.reviewer }} on {{ formatDateTime(item.reviewed_at) }}</span>
                    <span class="flex gap-3 font-medium">
                        <Link :href="`/admin/hr/pds-verifications/${item.id}`" class="text-primary hover:underline">View</Link>
                        <a :href="`/pds/submissions/${item.id}/print`" target="_blank" rel="noopener" class="text-primary hover:underline">
                            Print<span class="sr-only"> (opens in a new tab)</span>
                        </a>
                    </span>
                </li>
            </ul>
        </Card>
    </div>

    <Modal :open="archiving !== null" title="Archive this document?" icon="exclamation" tone="danger" @close="archiving = null">
        <p class="text-sm text-text">
            <strong>{{ archiving?.type_label }}</strong> will be hidden from the 201 file and from the employee, but kept in its history. You can
            restore it later.
        </p>
        <div class="mt-4">
            <label for="archive-reason" class="block text-sm font-medium text-text">Reason <span class="text-danger" aria-hidden="true">*</span></label>
            <Textarea id="archive-reason" v-model="archiveForm.reason" class="mt-1" :rows="2" required :invalid="!!archiveForm.errors.reason" />
            <InputError :message="archiveForm.errors.reason" />
        </div>
        <template #footer>
            <Button variant="secondary" @click="archiving = null">Cancel</Button>
            <Button variant="danger" :loading="archiveForm.processing" :disabled="!archiveForm.reason.trim()" @click="archive">Archive</Button>
        </template>
    </Modal>
</template>
