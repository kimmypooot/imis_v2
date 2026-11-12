<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import Textarea from '../../Components/Forms/Textarea.vue';
import FormGroup from '../../Components/Forms/FormGroup.vue';
import InputError from '../../Components/Forms/InputError.vue';
import { confirm } from '../../Composables/useDialog';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * Admin-3: the wording of every email IMIS sends. Pick one, edit it, and
 * see it filled with sample values as you type. Placeholders go in braces;
 * a blank line starts a new paragraph.
 */
const props = defineProps({
    templates: { type: Array, required: true },
    sample: { type: Object, required: true },
    canUpdate: { type: Boolean, default: false },
});

const selectedKey = ref(props.templates[0]?.key);
const selected = computed(() => props.templates.find((template) => template.key === selectedKey.value));

const form = useForm({ subject: '', intro: '', button: '', outro: '' });
const load = () => {
    const current = selected.value.current;
    form.defaults({ subject: current.subject, intro: current.intro ?? '', button: current.button ?? '', outro: current.outro ?? '' });
    form.reset();
    form.clearErrors();
};
watch(selected, load, { immediate: true });

const save = () => form.put(`/admin/email-templates/${selectedKey.value}`, { preserveScroll: true, onSuccess: () => load() });
const restore = async () => {
    if (await confirm({ title: 'Put back the default wording?', text: 'Your changes to this email are discarded.', confirmText: 'Put back default', tone: 'danger' })) {
        router.delete(`/admin/email-templates/${selectedKey.value}`, { preserveScroll: true, onSuccess: () => load() });
    }
};
const sendTest = () => router.post(`/admin/email-templates/${selectedKey.value}/test`, {}, { preserveScroll: true });

// The preview is the real email, rendered by the server in IMIS's mail
// layout with sample values; it follows the draft as it is typed.
const preview = ref(null);
const previewLoading = ref(false);
const previewFailed = ref(false);
const device = ref('desktop');
let previewTimer = null;
let previewRequest = 0;

const xsrfToken = () => decodeURIComponent(document.cookie.split('; ').find((part) => part.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');

const loadPreview = async () => {
    const request = ++previewRequest;
    previewLoading.value = true;
    try {
        const response = await fetch(`/admin/email-templates/${selectedKey.value}/preview`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
            body: JSON.stringify({ subject: form.subject, intro: form.intro, button: form.button, outro: form.outro }),
        });
        if (request !== previewRequest) {
            return;
        }
        previewFailed.value = !response.ok;
        if (response.ok) {
            preview.value = await response.json();
        }
    } catch {
        if (request === previewRequest) {
            previewFailed.value = true;
        }
    } finally {
        if (request === previewRequest) {
            previewLoading.value = false;
        }
    }
};
watch(() => [selectedKey.value, form.subject, form.intro, form.button, form.outro], () => {
    clearTimeout(previewTimer);
    previewTimer = setTimeout(loadPreview, 350);
}, { immediate: true });
onBeforeUnmount(() => clearTimeout(previewTimer));

// Written in braces; built here because "}}" inside a template expression ends it.
const braced = (placeholder) => `{${placeholder}}`;

const templateIcons = { account_setup: 'user', password_reset: 'key', notification: 'bell', weekly_summary: 'chartBar' };
const deviceOptions = [{ key: 'desktop', label: 'Desktop' }, { key: 'mobile', label: 'Phone' }];

// Placeholder chips go into the field last clicked in, at the cursor.
const fieldIds = { 'template-subject': 'subject', 'template-intro': 'intro', 'template-button': 'button', 'template-outro': 'outro' };
const lastFieldId = ref('template-intro');
const rememberField = (event) => {
    if (fieldIds[event.target.id]) {
        lastFieldId.value = event.target.id;
    }
};
const insert = (placeholder) => {
    const element = document.getElementById(lastFieldId.value);
    const field = fieldIds[lastFieldId.value];
    const text = form[field] ?? '';
    const start = element?.selectionStart ?? text.length;
    const end = element?.selectionEnd ?? text.length;
    form[field] = `${text.slice(0, start)}${braced(placeholder)}${text.slice(end)}`;
};
</script>

<template>
    <Head title="Email Templates" />

    <PageHeader
        title="Email Templates"
        description="The wording of the emails IMIS sends. Changes apply to the next email. The preview shows each email exactly as it will look, with sample values."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'System' }, { label: 'Email Templates' }]"
    />

    <nav aria-label="Emails" class="mb-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <button
            v-for="template in templates"
            :key="template.key"
            type="button"
            class="group flex items-start gap-3 rounded-lg border p-4 text-left transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :class="template.key === selectedKey ? 'border-primary bg-info-bg' : 'border-border bg-surface hover:border-border-strong'"
            :aria-current="template.key === selectedKey ? 'true' : undefined"
            @click="selectedKey = template.key"
        >
            <span
                class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md"
                :class="template.key === selectedKey ? 'bg-primary text-white' : 'bg-surface-muted text-text-muted group-hover:text-link'"
                aria-hidden="true"
            >
                <Icon :name="templateIcons[template.key] ?? 'envelope'" size="sm" />
            </span>
            <span class="min-w-0 flex-1">
                <span class="block text-sm font-semibold text-text">{{ template.label }}</span>
                <span class="mt-1 flex items-center gap-2 text-xs text-text-muted">
                    <Badge v-if="template.current.edited" tone="info">Edited</Badge>
                    <Badge v-else tone="neutral">Default</Badge>
                    <span v-if="template.updatedAt" class="truncate">{{ formatDateTime(template.updatedAt) }}</span>
                </span>
            </span>
        </button>
    </nav>

    <div v-if="selected" class="grid items-start gap-6 xl:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
        <Card :title="selected.label" icon="pencil" :description="selected.description">
            <form class="space-y-5" @submit.prevent="save" @focusin="rememberField">
                <p v-if="!canUpdate" class="rounded-md border border-border bg-surface-muted px-3 py-2 text-sm text-text-muted">
                    You can view this email but not change it.
                </p>

                <fieldset :disabled="!canUpdate" class="space-y-5">
                    <div>
                        <TextInput id="template-subject" v-model="form.subject" label="Subject" required :invalid="!!form.errors.subject" />
                        <div class="mt-1 flex justify-between gap-3 text-xs text-text-muted">
                            <span>The line people see in their inbox.</span>
                            <span class="shrink-0 tabular-nums">{{ form.subject.length }}/200</span>
                        </div>
                        <InputError :message="form.errors.subject" />
                    </div>

                    <FormGroup id="template-intro" label="Opening" :error="form.errors.intro">
                        <Textarea id="template-intro" v-model="form.intro" :rows="6" :invalid="!!form.errors.intro" />
                        <div class="mt-1 flex justify-between gap-3 text-xs text-text-muted">
                            <span>Starts the email. A blank line begins a new paragraph.</span>
                            <span class="shrink-0 tabular-nums">{{ form.intro.length }}/3000</span>
                        </div>
                    </FormGroup>

                    <div>
                        <TextInput id="template-button" v-model="form.button" label="Button label" :invalid="!!form.errors.button" />
                        <p class="mt-1 text-xs text-text-muted">Leave empty to send the email without a button.</p>
                        <InputError :message="form.errors.button" />
                    </div>

                    <FormGroup id="template-outro" label="Closing" :error="form.errors.outro">
                        <Textarea id="template-outro" v-model="form.outro" :rows="5" :invalid="!!form.errors.outro" />
                        <div class="mt-1 flex justify-between gap-3 text-xs text-text-muted">
                            <span>Follows the button: reminders, security notes, who to contact.</span>
                            <span class="shrink-0 tabular-nums">{{ form.outro.length }}/3000</span>
                        </div>
                    </FormGroup>
                </fieldset>

                <div class="rounded-md bg-surface-muted p-3">
                    <p class="text-xs font-semibold text-text">Placeholders</p>
                    <p class="mb-2 text-xs text-text-muted">{{ canUpdate ? 'Select one to insert it where you last clicked.' : 'Replaced with real values when the email is sent.' }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="placeholder in selected.placeholders"
                            :key="placeholder"
                            type="button"
                            class="rounded-sm border border-border bg-surface px-1.5 py-0.5 font-mono text-xs text-text hover:border-primary hover:text-link focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:cursor-default disabled:hover:border-border disabled:hover:text-text"
                            :disabled="!canUpdate"
                            :title="sample[placeholder] !== undefined ? `For example: ${sample[placeholder]}` : undefined"
                            @click="insert(placeholder)"
                        >
                            {{ braced(placeholder) }}
                        </button>
                    </div>
                </div>

                <div v-if="canUpdate" class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
                    <Button type="submit" :loading="form.processing" :disabled="!form.isDirty">Save changes</Button>
                    <Button variant="outline" @click="sendTest">
                        <Icon name="envelope" size="sm" />
                        Send me a test
                    </Button>
                    <Button v-if="selected.current.edited" variant="ghost" @click="restore">Put back default</Button>
                    <span v-if="form.isDirty" class="ml-auto flex items-center gap-1.5 text-xs text-warning-fg">
                        <span class="h-1.5 w-1.5 rounded-full bg-warning" aria-hidden="true" />
                        Unsaved changes
                    </span>
                </div>
                <p v-if="selected.updatedBy" class="text-xs text-text-muted">
                    Last changed by {{ selected.updatedBy }}, {{ formatDateTime(selected.updatedAt) }}.
                </p>
            </form>
        </Card>

        <Card title="Preview" icon="eye" description="Exactly as it will arrive, with sample values. It updates as you type." class="xl:sticky xl:top-4">
            <template #actions>
                <span v-if="previewLoading" class="flex items-center gap-1.5 text-xs text-text-muted" role="status">
                    <Icon name="refresh" size="sm" class="animate-spin" />
                    Updating
                </span>
                <div class="inline-flex rounded-md border border-border p-0.5" role="group" aria-label="Preview width">
                    <button
                        v-for="option in deviceOptions"
                        :key="option.key"
                        type="button"
                        class="flex items-center gap-1.5 rounded-sm px-2.5 py-1 text-xs font-medium focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        :class="device === option.key ? 'bg-primary text-white' : 'text-text-muted hover:bg-surface-muted'"
                        :aria-pressed="device === option.key"
                        @click="device = option.key"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </template>

            <p v-if="previewFailed" class="mb-3 rounded-md border border-danger-border bg-danger-bg px-3 py-2 text-sm text-danger-fg">
                The preview could not be loaded. Check your connection and try again.
            </p>

            <div v-if="preview" class="overflow-hidden rounded-lg border border-border">
                <div class="flex items-start gap-3 border-b border-border bg-surface px-4 py-3">
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-sm font-semibold text-white" aria-hidden="true">
                        {{ (preview.from.name || '?').charAt(0) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-semibold text-text">{{ preview.subject }}</p>
                        <p class="truncate text-xs text-text-muted">
                            <span class="font-medium text-text">{{ preview.from.name }}</span> &lt;{{ preview.from.address }}&gt;
                        </p>
                        <p class="truncate text-xs text-text-muted">to {{ preview.to ?? 'you' }}</p>
                    </div>
                </div>
                <div class="bg-surface-muted p-3 transition-opacity" :class="previewLoading ? 'opacity-60' : ''">
                    <iframe
                        :srcdoc="preview.html"
                        sandbox=""
                        title="Email preview"
                        class="mx-auto block h-[42rem] w-full rounded-md border-0 bg-white transition-[max-width]"
                        :style="{ maxWidth: device === 'mobile' ? '24rem' : '100%' }"
                    />
                </div>
            </div>
            <div v-else-if="!previewFailed" class="h-96 animate-pulse rounded-lg bg-surface-muted" role="status" aria-label="Loading preview" />
        </Card>
    </div>
</template>
