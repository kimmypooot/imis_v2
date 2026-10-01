<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Button from '../../../Components/UI/Button.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import Select from '../../../Components/Forms/Select.vue';
import ConfirmDialog from '../../../Components/Feedback/ConfirmDialog.vue';
import ReleaseNotes from '../../../Components/Portal/ReleaseNotes.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

/**
 * Create (changelog = null) and edit a release note. Entries are an
 * ordered list edited inline; a live preview shows exactly what users
 * will see in the pop-up.
 */
const props = defineProps({
    changelog: { type: Object, default: null },
    typeOptions: { type: Array, required: true },
});

const { can } = useAuthorization();
const isNew = computed(() => props.changelog === null);
const isPublished = computed(() => props.changelog?.status === 'published');

const form = useForm({
    version: props.changelog?.version ?? '',
    title: props.changelog?.title ?? '',
    release_date: props.changelog?.release_date ?? '',
    items: props.changelog?.items?.map((item) => ({ ...item })) ?? [{ type: 'feature', description: '' }],
});

const addItem = () => form.items.push({ type: 'feature', description: '' });
const removeItem = (index) => form.items.splice(index, 1);
const moveItem = (index, offset) => {
    const target = index + offset;
    if (target < 0 || target >= form.items.length) return;
    const [item] = form.items.splice(index, 1);
    form.items.splice(target, 0, item);
};

const submit = () => {
    const payload = { preserveScroll: true };
    form.transform((data) => ({ ...data, release_date: data.release_date || null }));

    if (isNew.value) {
        form.post('/admin/changelogs', payload);
    } else {
        form.put(`/admin/changelogs/${props.changelog.id}`, payload);
    }
};

const typeLabel = (type) => props.typeOptions.find((option) => option.value === type)?.label ?? type;
const preview = computed(() => ({
    version: form.version || '0.0.0',
    title: form.title || 'Untitled release',
    release_date: form.release_date || null,
    items: form.items
        .filter((item) => item.description.trim())
        .map((item) => ({ ...item, type_label: typeLabel(item.type) })),
}));

const confirmStatus = ref(false);
const statusForm = useForm({ status: '' });
const toggleStatus = () => {
    statusForm.status = isPublished.value ? 'draft' : 'published';
    statusForm.patch(`/admin/changelogs/${props.changelog.id}/status`, {
        preserveScroll: true,
        onFinish: () => (confirmStatus.value = false),
    });
};
</script>

<template>
    <Head :title="isNew ? 'New release note' : `Edit release ${changelog.version}`" />

    <PageHeader
        :title="isNew ? 'New release note' : `Release ${changelog.version}`"
        :breadcrumbs="[
            { label: 'Administration', href: '/admin' },
            { label: `What's New`, href: '/admin/changelogs' },
            { label: isNew ? 'New' : `v${changelog.version}` },
        ]"
    >
        <template v-if="!isNew && can('changelogs.publish')" #actions>
            <Badge :tone="isPublished ? 'success' : 'neutral'">{{ changelog.status }}</Badge>
            <Button :variant="isPublished ? 'secondary' : 'primary'" @click="confirmStatus = true">
                {{ isPublished ? 'Unpublish' : 'Publish' }}
            </Button>
        </template>
    </PageHeader>

    <div class="grid gap-6 lg:grid-cols-5">
        <Card class="lg:col-span-3">
            <form class="space-y-5" @submit.prevent="submit">
                <div class="grid gap-5 sm:grid-cols-3">
                    <div>
                        <TextInput id="version" v-model="form.version" label="Version" required :invalid="!!form.errors.version" />
                        <InputError :message="form.errors.version" />
                    </div>
                    <div class="sm:col-span-2">
                        <TextInput id="title" v-model="form.title" label="Title" required :invalid="!!form.errors.title" />
                        <InputError :message="form.errors.title" />
                    </div>
                    <div>
                        <TextInput
                            id="release_date"
                            v-model="form.release_date"
                            type="date"
                            label="Release date"
                            :invalid="!!form.errors.release_date"
                        />
                        <InputError :message="form.errors.release_date" />
                    </div>
                </div>

                <fieldset>
                    <legend class="mb-2 text-sm font-medium text-text">Entries</legend>
                    <ol class="space-y-3">
                        <li v-for="(item, index) in form.items" :key="index" class="rounded-md border border-border p-3">
                            <div class="flex flex-col gap-3 sm:flex-row">
                                <div class="sm:w-36">
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
                                        class="block w-full rounded-md border border-border bg-surface px-3 py-2 text-sm text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                                        :aria-invalid="!!form.errors[`items.${index}.description`]"
                                    />
                                    <InputError :message="form.errors[`items.${index}.description`] || form.errors[`items.${index}.type`]" />
                                </div>
                            </div>
                            <div class="mt-2 flex justify-end gap-3 text-xs">
                                <button type="button" class="text-primary disabled:text-text-muted" :disabled="index === 0" @click="moveItem(index, -1)">
                                    Move up
                                </button>
                                <button
                                    type="button"
                                    class="text-primary disabled:text-text-muted"
                                    :disabled="index === form.items.length - 1"
                                    @click="moveItem(index, 1)"
                                >
                                    Move down
                                </button>
                                <button type="button" class="text-danger disabled:text-text-muted" :disabled="form.items.length === 1" @click="removeItem(index)">
                                    Remove
                                </button>
                            </div>
                        </li>
                    </ol>
                    <InputError :message="form.errors.items" />
                    <Button variant="secondary" class="mt-3" @click="addItem">Add entry</Button>
                </fieldset>

                <Button type="submit" :loading="form.processing">{{ isNew ? 'Save draft' : 'Save changes' }}</Button>
            </form>
        </Card>

        <Card title="Preview" class="lg:col-span-2">
            <ReleaseNotes :release="preview" />
        </Card>
    </div>

    <ConfirmDialog
        v-if="!isNew"
        :open="confirmStatus"
        :title="isPublished ? 'Unpublish this release note?' : 'Publish this release note?'"
        :danger="isPublished"
        :confirm-label="isPublished ? 'Unpublish' : 'Publish'"
        :processing="statusForm.processing"
        @confirm="toggleStatus"
        @cancel="confirmStatus = false"
    >
        {{
            isPublished
                ? 'It will disappear from the What\'s New page and the dashboard pop-up.'
                : 'It will pop up once on every user\'s dashboard and appear on the What\'s New page. Save any edits first.'
        }}
    </ConfirmDialog>
</template>
