<script setup>
import { reactive } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import Button from '../../Components/UI/Button.vue';
import FileInput from '../../Components/Forms/FileInput.vue';
import InputError from '../../Components/Forms/InputError.vue';
import { confirm } from '../../Composables/useDialog';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * Admin-3: replace or reset the images that brand IMIS. PNG, JPEG or
 * WebP up to 2 MB. Colors and type stay in the design system.
 */
defineProps({
    assets: { type: Array, required: true },
    canUpdate: { type: Boolean, default: false },
});

const files = reactive({});
const errors = reactive({});
const busy = reactive({});

const upload = (asset) => {
    busy[asset.key] = true;
    router.post(`/admin/branding/${asset.key}`, { image: files[asset.key] }, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            files[asset.key] = null;
            errors[asset.key] = null;
        },
        onError: (result) => (errors[asset.key] = result.image),
        onFinish: () => (busy[asset.key] = false),
    });
};

const reset = async (asset) => {
    if (await confirm({ title: `Put back the built-in ${asset.label.toLowerCase()}?`, confirmText: 'Put back', tone: 'danger' })) {
        router.delete(`/admin/branding/${asset.key}`, { preserveScroll: true });
    }
};
</script>

<template>
    <Head title="Branding" />

    <PageHeader
        title="Branding"
        description="The logos and sign-in photo that brand IMIS. Colors and fonts are part of the design system and don't change here."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'System' }, { label: 'Branding' }]"
    />

    <p class="mb-4 text-sm text-text-muted">
        The name beside the logo is in
        <Link href="/admin/settings" class="text-link hover:underline">System Settings → General</Link>.
    </p>

    <div class="grid gap-6 lg:grid-cols-3">
        <Card v-for="asset in assets" :key="asset.key" :title="asset.label" :description="asset.description">
            <template #actions>
                <Badge :tone="asset.custom ? 'info' : 'neutral'">{{ asset.custom ? 'Uploaded' : 'Built-in' }}</Badge>
            </template>

            <div class="flex h-36 items-center justify-center rounded-md border border-border bg-surface-muted p-3" :class="asset.key === 'login_background' ? 'bg-csc-blue' : ''">
                <img :src="asset.url" :alt="`Current ${asset.label.toLowerCase()}`" class="max-h-full max-w-full object-contain" />
            </div>
            <p class="mt-2 text-xs text-text-muted">{{ asset.hint }}</p>
            <p v-if="asset.custom && asset.updatedBy" class="mt-1 text-xs text-text-muted">Uploaded by {{ asset.updatedBy }}, {{ formatDateTime(asset.updatedAt) }}.</p>

            <form v-if="canUpdate" class="mt-4 space-y-2" @submit.prevent="upload(asset)">
                <label :for="`brand-${asset.key}`" class="sr-only">New {{ asset.label.toLowerCase() }}</label>
                <FileInput :id="`brand-${asset.key}`" v-model="files[asset.key]" accept="image/png,image/jpeg,image/webp" :invalid="!!errors[asset.key]" />
                <InputError :message="errors[asset.key]" />
                <div class="flex flex-wrap gap-2">
                    <Button type="submit" size="sm" :loading="busy[asset.key]" :disabled="!files[asset.key]">Upload</Button>
                    <Button v-if="asset.custom" size="sm" variant="ghost" @click="reset(asset)">Put back built-in</Button>
                </div>
            </form>
        </Card>
    </div>
</template>
