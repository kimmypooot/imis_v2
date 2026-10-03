<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import AdminCard from '../../../Components/UI/AdminCard.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import ActionButton from '../../../Components/UI/ActionButton.vue';
import StatusBadge from '../../../Components/UI/StatusBadge.vue';
import AnnouncementForm from '../../../Components/Announcements/AnnouncementForm.vue';
import VisibilityBadge from '../../../Components/Announcements/VisibilityBadge.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { formatDateTime, fromLocalInput, toLocalInput } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Announcement Management" page: a sticky form card (create,
 * or edit the row you picked) beside the table. Publishing, archiving,
 * and featuring happen on the preview page, which shows exactly what the
 * portal will show.
 */
const props = defineProps({
    announcements: { type: Object, required: true },
    editing: { type: Object, default: null },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const blank = () => ({ title: '', summary: '', body: '', publish_at: '', expires_at: '', featured: false });
const fieldsFor = (announcement) => ({
    title: announcement.title,
    summary: announcement.summary ?? '',
    body: announcement.body,
    publish_at: toLocalInput(announcement.publish_at),
    expires_at: toLocalInput(announcement.expires_at),
    featured: announcement.featured,
});

const current = ref(null);
const form = useForm(blank());
const isEditing = computed(() => current.value !== null);

const startEdit = (announcement) => {
    current.value = announcement;
    form.defaults(fieldsFor(announcement));
    form.reset();
    form.clearErrors();
    window.scrollTo({ top: 0, behavior: 'smooth' });
};

const cancelEdit = () => {
    current.value = null;
    form.defaults(blank());
    form.reset();
    form.clearErrors();
};

onMounted(() => {
    if (props.editing) startEdit(props.editing);
});

const submit = () => {
    form.transform((data) => ({ ...data, publish_at: fromLocalInput(data.publish_at), expires_at: fromLocalInput(data.expires_at) }));
    const options = { preserveScroll: true, onSuccess: cancelEdit };

    if (isEditing.value) form.put(`/admin/announcements/${current.value.id}`, options);
    else form.post('/admin/announcements', options);
};

const columns = [
    { key: 'title', label: 'Title & summary', sortable: false },
    { key: 'status', label: 'Status', sortable: false, align: 'center' },
    { key: 'visibility_state', label: 'On portal', sortable: false, align: 'center' },
    { key: 'expires_at', label: 'Expires', sortable: false, value: (row) => (row.expires_at ? formatDateTime(row.expires_at) : '') },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '6rem' },
];

const canWrite = computed(() => (isEditing.value ? can('announcements.update') : can('announcements.create')));
</script>

<template>
    <Head title="Announcements" />

    <PageHeader title="Announcement Management" :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Announcements' }]" />

    <div class="grid items-start gap-6 xl:grid-cols-12">
        <AdminCard v-if="canWrite" :title="isEditing ? 'Edit Announcement' : 'Create Announcement'" :icon="isEditing ? 'pencil' : 'plus'" class="xl:sticky xl:top-20 xl:col-span-5">
            <template #actions>
                <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="isEditing ? 'bg-warning-bg text-warning' : 'bg-success-bg text-success'">
                    {{ isEditing ? 'Editing' : 'New draft' }}
                </span>
            </template>
            <p v-if="isEditing" class="mb-3 text-sm text-text-muted">
                Editing <strong class="text-text">{{ current.title }}</strong>.
                <button type="button" class="text-primary hover:underline" @click="cancelEdit">Cancel and create new instead</button>
            </p>
            <AnnouncementForm
                :form="form"
                :submit-label="isEditing ? 'Save changes' : 'Save draft'"
                :publish-date-required="isEditing && current.status === 'published'"
                @submit="submit"
            />
        </AdminCard>

        <AdminCard title="All Announcements" icon="megaphone" :class="canWrite ? 'xl:col-span-7' : 'xl:col-span-12'">
            <DataTable
                :columns="columns"
                :paginator="announcements"
                :query="filters"
                url="/admin/announcements"
                :only="['announcements', 'filters']"
                caption="Announcements"
                export-name="imis-announcements"
                :numbered="false"
                :page-sizes="[10, 25, 50]"
            >
                <template #cell-title="{ row }">
                    <div class="font-medium text-text">
                        {{ row.title }}
                        <span v-if="row.featured" class="ml-1 rounded bg-info-bg px-1.5 text-xs text-primary">Featured</span>
                    </div>
                    <div v-if="row.summary" class="line-clamp-2 max-w-md text-xs text-text-muted">{{ row.summary }}</div>
                    <div class="text-xs text-text-muted">{{ row.author }} · updated {{ formatDateTime(row.updated_at) }}</div>
                </template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status === 'published' ? 'published' : row.status === 'archived' ? 'inactive' : 'draft'" :label="row.status" /></template>
                <template #cell-visibility_state="{ row }"><VisibilityBadge :state="row.visibility_state" /></template>
                <template #cell-actions="{ row }">
                    <div class="flex justify-center gap-1">
                        <ActionButton v-if="can('announcements.update')" icon="pencil" :label="`Edit ${row.title}`" @click="startEdit(row)" />
                        <ActionButton icon="eye" tone="neutral" :label="`Preview and publish ${row.title}`" :href="`/admin/announcements/${row.id}`" />
                    </div>
                </template>
            </DataTable>
        </AdminCard>
    </div>
</template>
