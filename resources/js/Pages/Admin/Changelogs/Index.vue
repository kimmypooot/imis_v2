<script setup>
import Badge from '../../../Components/UI/Badge.vue';
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import DataTable from '../../../Components/UI/DataTable.vue';
import Button from '../../../Components/UI/Button.vue';
import ActionButton from '../../../Components/UI/ActionButton.vue';
import StatusBadge from '../../../Components/UI/StatusBadge.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import Select from '../../../Components/Forms/Select.vue';
import ReleaseNotes from '../../../Components/Portal/ReleaseNotes.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { confirm } from '../../../Composables/useDialog';
import { formatDate, formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * The legacy "Changelog Management" page: a sticky form card (new release,
 * or the one you picked to edit) with its entries and a live preview,
 * beside the table of releases with publish / unpublish actions.
 */
const props = defineProps({
    changelogs: { type: Array, required: true },
    typeOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const blank = () => ({ version: '', title: '', release_date: '', items: [{ type: 'feature', description: '' }] });
const current = ref(null);
const form = useForm(blank());
const isEditing = computed(() => current.value !== null);
const showPreview = ref(false);

const startEdit = (changelog) => {
    current.value = changelog;
    form.defaults({
        version: changelog.version,
        title: changelog.title,
        release_date: changelog.release_date ?? '',
        items: changelog.items.map((item) => ({ ...item })),
    });
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

const addItem = () => form.items.push({ type: 'feature', description: '' });
const removeItem = (index) => form.items.splice(index, 1);
const moveItem = (index, offset) => {
    const target = index + offset;
    if (target < 0 || target >= form.items.length) return;
    const [item] = form.items.splice(index, 1);
    form.items.splice(target, 0, item);
};

const submit = () => {
    form.transform((data) => ({ ...data, release_date: data.release_date || null }));
    const options = { preserveScroll: true, onSuccess: cancelEdit };

    if (isEditing.value) form.put(`/admin/changelogs/${current.value.id}`, options);
    else form.post('/admin/changelogs', options);
};

const typeLabel = (type) => props.typeOptions.find((option) => option.value === type)?.label ?? type;
const preview = computed(() => ({
    version: form.version || '0.0.0',
    title: form.title || 'Untitled release',
    release_date: form.release_date || null,
    items: form.items.filter((item) => item.description.trim()).map((item) => ({ ...item, type_label: typeLabel(item.type) })),
}));

const toggleStatus = async (changelog) => {
    const publishing = changelog.status !== 'published';
    const confirmed = await confirm({
        title: publishing ? `Publish ${changelog.version}?` : `Unpublish ${changelog.version}?`,
        text: publishing
            ? "It will pop up once on every user's dashboard and appear on the What's New page."
            : "It will disappear from the What's New page and the dashboard pop-up.",
        tone: publishing ? 'success' : 'danger',
        confirmText: publishing ? 'Publish' : 'Unpublish',
    });

    if (confirmed) router.patch(`/admin/changelogs/${changelog.id}/status`, { status: publishing ? 'published' : 'draft' }, { preserveScroll: true });
};

const columns = [
    { key: 'version', label: 'Version' },
    { key: 'title', label: 'Title & entries' },
    { key: 'release_date', label: 'Release date', value: (row) => (row.release_date ? formatDate(row.release_date) : '') },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '6rem' },
];
</script>

<template>
    <Head title="Changelog" />

    <PageHeader
        title="Changelog Management"
        description="Release notes (What's New). Publishing one pops it up once on every user's dashboard."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'Changelog' }]"
    />

    <div class="grid items-start gap-6 xl:grid-cols-12">
        <Card v-if="can('changelogs.manage')" :title="isEditing ? `Edit Release ${current.version}` : 'New Release'" :icon="isEditing ? 'pencil' : 'plus'" class="xl:sticky xl:top-20 xl:col-span-5">
            <template #actions>
                <Badge pill :tone="isEditing ? 'warning' : 'success'">
                    {{ isEditing ? 'Editing' : 'New draft' }}
                </Badge>
            </template>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-3 sm:grid-cols-3">
                    <div>
                        <TextInput id="version" v-model="form.version" label="Version" required :invalid="!!form.errors.version" />
                        <InputError :message="form.errors.version" />
                    </div>
                    <div class="sm:col-span-2">
                        <TextInput id="release_date" v-model="form.release_date" type="date" label="Release date" :invalid="!!form.errors.release_date" />
                        <InputError :message="form.errors.release_date" />
                    </div>
                </div>
                <div>
                    <TextInput id="title" v-model="form.title" label="Title" required :invalid="!!form.errors.title" />
                    <InputError :message="form.errors.title" />
                </div>

                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-text">Entries</legend>
                    <ol class="space-y-2">
                        <li v-for="(item, index) in form.items" :key="index" class="rounded-md border border-border p-2">
                            <div class="flex gap-2">
                                <div class="w-32 shrink-0">
                                    <label :for="`item-type-${index}`" class="sr-only">Entry {{ index + 1 }} kind</label>
                                    <Select :id="`item-type-${index}`" v-model="item.type" :options="typeOptions" />
                                </div>
                                <div class="flex-1">
                                    <label :for="`item-description-${index}`" class="sr-only">Entry {{ index + 1 }} description</label>
                                    <textarea
                                        :id="`item-description-${index}`"
                                        v-model="item.description"
                                        rows="2"
                                        maxlength="500"
                                        class="form-control block w-full px-2 py-1.5"
                                    />
                                </div>
                            </div>
                            <InputError :message="form.errors[`items.${index}.description`] || form.errors[`items.${index}.type`]" />
                            <div class="mt-1 flex justify-end gap-3 text-xs">
                                <button type="button" class="text-primary disabled:text-text-muted" :disabled="index === 0" @click="moveItem(index, -1)">Up</button>
                                <button type="button" class="text-primary disabled:text-text-muted" :disabled="index === form.items.length - 1" @click="moveItem(index, 1)">Down</button>
                                <button type="button" class="text-danger disabled:text-text-muted" :disabled="form.items.length === 1" @click="removeItem(index)">Remove</button>
                            </div>
                        </li>
                    </ol>
                    <InputError :message="form.errors.items" />
                    <Button variant="secondary" class="mt-2" @click="addItem">Add entry</Button>
                </fieldset>

                <div class="flex flex-wrap items-center gap-2">
                    <Button type="submit" :variant="isEditing ? 'primary' : 'success'" :loading="form.processing">{{ isEditing ? 'Save changes' : 'Save draft' }}</Button>
                    <Button v-if="isEditing" variant="secondary" @click="cancelEdit">Cancel</Button>
                    <button type="button" class="ml-auto text-sm text-primary hover:underline" :aria-expanded="showPreview" @click="showPreview = !showPreview">
                        {{ showPreview ? 'Hide preview' : 'Preview' }}
                    </button>
                </div>
                <div v-if="showPreview" class="rounded-md border border-dashed border-border p-3">
                    <ReleaseNotes :release="preview" />
                </div>
            </form>
        </Card>

        <Card title="Releases" icon="megaphone" :class="can('changelogs.manage') ? 'xl:col-span-7' : 'xl:col-span-12'">
            <DataTable :columns="columns" :rows="changelogs" caption="Releases" export-name="imis-changelog" :numbered="false">
                <template #cell-version="{ row }">
                    <span class="rounded-sm bg-info-bg px-2 py-0.5 font-mono text-xs text-primary">v{{ row.version }}</span>
                </template>
                <template #cell-title="{ row }">
                    <div class="font-medium text-text">{{ row.title }}</div>
                    <div class="text-xs text-text-muted">{{ row.items.length }} {{ row.items.length === 1 ? 'entry' : 'entries' }}</div>
                </template>
                <template #cell-status="{ row }">
                    <StatusBadge :status="row.status" />
                    <div v-if="row.published_at" class="mt-1 text-xs text-text-muted">{{ formatDateTime(row.published_at) }}</div>
                </template>
                <template #cell-actions="{ row }">
                    <div class="flex justify-center gap-1">
                        <ActionButton v-if="can('changelogs.manage')" icon="pencil" :label="`Edit ${row.version}`" @click="startEdit(row)" />
                        <ActionButton
                            v-if="can('changelogs.publish')"
                            :icon="row.status === 'published' ? 'xCircle' : 'checkCircle'"
                            :tone="row.status === 'published' ? 'danger' : 'success'"
                            :label="`${row.status === 'published' ? 'Unpublish' : 'Publish'} ${row.version}`"
                            @click="toggleStatus(row)"
                        />
                    </div>
                </template>
            </DataTable>
        </Card>
    </div>
</template>
