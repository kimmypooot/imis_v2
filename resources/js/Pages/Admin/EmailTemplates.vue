<script setup>
import { computed, ref, watch } from 'vue';
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

const fill = (text) => (text ?? '').replace(/\{([a-z_]+)\}/g, (match, name) => props.sample[name] ?? match);
const paragraphs = (text) => fill(text).split(/\n\s*\n/).map((part) => part.trim()).filter(Boolean);
// Written in braces; built here because "}}" inside a template expression ends it.
const braced = (placeholder) => `{${placeholder}}`;

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
        description="The wording of the emails IMIS sends. Changes apply to the next email. The preview fills the placeholders with sample values."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'System' }, { label: 'Email Templates' }]"
    />

    <div class="grid gap-6 lg:grid-cols-[16rem_1fr]">
        <nav aria-label="Emails" class="space-y-1">
            <button
                v-for="template in templates"
                :key="template.key"
                type="button"
                class="w-full rounded-md px-3 py-2 text-left text-sm focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="template.key === selectedKey ? 'bg-primary text-white' : 'text-text hover:bg-surface-muted'"
                :aria-current="template.key === selectedKey ? 'true' : undefined"
                @click="selectedKey = template.key"
            >
                {{ template.label }}
                <span v-if="template.current.edited" class="block text-xs opacity-80">Edited</span>
            </button>
        </nav>

        <div v-if="selected" class="grid gap-6 xl:grid-cols-2">
            <Card :title="selected.label" :description="selected.description">
                <template #actions>
                    <Badge v-if="selected.current.edited" tone="info">Edited</Badge>
                    <Badge v-else tone="neutral">Default</Badge>
                </template>

                <form class="space-y-4" @submit.prevent="save" @focusin="rememberField">
                    <fieldset :disabled="!canUpdate" class="space-y-4">
                        <div>
                            <TextInput id="template-subject" v-model="form.subject" label="Subject" required :invalid="!!form.errors.subject" />
                            <InputError :message="form.errors.subject" />
                        </div>
                        <FormGroup id="template-intro" label="Opening" :error="form.errors.intro">
                            <Textarea id="template-intro" v-model="form.intro" :rows="5" :invalid="!!form.errors.intro" />
                        </FormGroup>
                        <div>
                            <TextInput id="template-button" v-model="form.button" label="Button label" :invalid="!!form.errors.button" />
                            <InputError :message="form.errors.button" />
                        </div>
                        <FormGroup id="template-outro" label="Closing" :error="form.errors.outro">
                            <Textarea id="template-outro" v-model="form.outro" :rows="4" :invalid="!!form.errors.outro" />
                        </FormGroup>
                    </fieldset>

                    <div class="text-xs text-text-muted">
                        Placeholders:
                        <button
                            v-for="placeholder in selected.placeholders"
                            :key="placeholder"
                            type="button"
                            class="mr-1 mb-1 rounded-sm bg-surface-muted px-1.5 py-0.5 font-mono text-text hover:bg-border disabled:cursor-default"
                            :disabled="!canUpdate"
                            :title="canUpdate ? 'Insert where you last clicked' : undefined"
                            @click="insert(placeholder)"
                        >
                            {{ braced(placeholder) }}
                        </button>
                    </div>

                    <div v-if="canUpdate" class="flex flex-wrap gap-2">
                        <Button type="submit" :loading="form.processing" :disabled="!form.isDirty">Save</Button>
                        <Button variant="outline" @click="sendTest">
                            <Icon name="envelope" size="sm" />
                            Send me a test
                        </Button>
                        <Button v-if="selected.current.edited" variant="ghost" @click="restore">Put back default</Button>
                    </div>
                    <p v-if="selected.updatedBy" class="text-xs text-text-muted">
                        Last changed by {{ selected.updatedBy }}, {{ formatDateTime(selected.updatedAt) }}.
                    </p>
                </form>
            </Card>

            <Card title="Preview" icon="eye" description="With sample values. The real email uses IMIS's mail layout.">
                <div class="rounded-md border border-border bg-surface-muted p-4 text-sm">
                    <p class="text-xs text-text-muted">Subject</p>
                    <p class="font-semibold text-text">{{ fill(form.subject) }}</p>
                    <div class="mt-4 space-y-3 rounded-md bg-surface p-4 text-text">
                        <p v-if="selectedKey === 'notification'" class="italic text-text-muted">(The notification's own text appears here.)</p>
                        <p v-for="(paragraph, index) in paragraphs(form.intro)" :key="`intro-${index}`" class="whitespace-pre-line">{{ paragraph }}</p>
                        <p v-if="selectedKey === 'weekly_summary'" class="italic text-text-muted">(The list of systems and figures appears here.)</p>
                        <p v-if="form.button" class="py-1">
                            <span class="inline-block rounded-md bg-primary px-4 py-2 font-medium text-white">{{ fill(form.button) }}</span>
                        </p>
                        <p v-for="(paragraph, index) in paragraphs(form.outro)" :key="`outro-${index}`" class="whitespace-pre-line">{{ paragraph }}</p>
                        <p class="text-text-muted">{{ sample.organization }}</p>
                    </div>
                </div>
            </Card>
        </div>
    </div>
</template>
