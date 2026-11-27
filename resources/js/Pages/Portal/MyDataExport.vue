<script setup>
import { computed, onMounted } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import AccountLayout from '../../Layouts/AccountLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import PasswordInput from '@cscro8/ui/Components/Forms/PasswordInput.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';

defineOptions({ layout: [PortalLayout, AccountLayout] });

/**
 * RA 10173: download a copy of what IMIS holds about you. Tick the parts
 * you want (all are ticked to start), confirm your password, and the file
 * is built when it is fetched from a link that works for two minutes.
 */
const props = defineProps({
    sections: { type: Array, required: true },
    downloadUrl: { type: String, default: null },
});

const form = useForm({ sections: props.sections.map((section) => section.key), password: '' });

const allChosen = computed(() => form.sections.length === props.sections.length);
const toggleAll = () => (form.sections = allChosen.value ? [] : props.sections.map((section) => section.key));

const prepare = () => form.post('/my/data-export', { preserveScroll: true, onSuccess: () => form.reset('password') });

// A plain navigation, so the browser saves the file and the page stays put.
const download = () => {
    if (props.downloadUrl) window.location.assign(props.downloadUrl);
};

onMounted(download);
</script>

<template>
    <Head title="Download my data" />

    <PageHeader
        title="Download my data"
        description="A copy of what IMIS holds about you. You choose which parts to include."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Download my data' }]"
    />

    <Card title="Get my copy" icon="database">
        <p class="mb-4 max-w-2xl text-sm text-text-muted">
            You get a ZIP file with <strong>data.html</strong> to read and <strong>data.json</strong> for another system.
            It holds personal data, so keep it somewhere safe. Stored files (attachments, scans, signatures) are not included.
            To correct something in it, use <a href="/my/corrections" class="text-link hover:underline">Request a correction</a>.
        </p>

        <form class="space-y-5" @submit.prevent="prepare">
            <fieldset aria-describedby="export-sections-helper">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <legend class="text-sm font-medium text-text">What to include</legend>
                    <button type="button" class="text-xs font-medium text-link hover:text-primary-hover" @click="toggleAll">
                        {{ allChosen ? 'Clear all' : 'Select all' }}
                    </button>
                </div>
                <p id="export-sections-helper" class="mt-0.5 text-xs text-text-muted">
                    {{ form.sections.length }} of {{ sections.length }} parts chosen. Untick anything you don't need.
                </p>
                <ul class="mt-3 grid gap-2 md:grid-cols-2">
                    <li v-for="section in sections" :key="section.key">
                        <label
                            :for="`export-${section.key}`"
                            class="flex h-full cursor-pointer gap-3 rounded-lg border p-3 transition-colors"
                            :class="form.sections.includes(section.key) ? 'border-link bg-info-bg' : 'border-border hover:bg-surface-muted'"
                        >
                            <input
                                :id="`export-${section.key}`"
                                v-model="form.sections"
                                type="checkbox"
                                :value="section.key"
                                class="mt-0.5 h-4 w-4 shrink-0 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring"
                            />
                            <span>
                                <span class="block text-sm font-medium text-text">{{ section.label }}</span>
                                <span class="mt-0.5 block text-xs leading-relaxed text-text-muted">{{ section.description }}</span>
                            </span>
                        </label>
                    </li>
                </ul>
                <InputError :message="form.errors.sections" />
            </fieldset>

            <div class="max-w-sm space-y-3">
                <PasswordInput id="export-password" v-model="form.password" label="Your password" autocomplete="current-password" required :error="form.errors.password" />
                <Button type="submit" :disabled="form.processing || !form.sections.length">Prepare my download</Button>
            </div>
        </form>

        <div v-if="downloadUrl" class="mt-4 rounded-md border border-success-border bg-success-bg p-3 text-sm text-success-fg">
            Your download is ready and starts automatically.
            <button type="button" class="font-medium underline" @click="download">Download again</button>
            <span class="text-text-muted">(the link works for two minutes)</span>
        </div>
    </Card>
</template>
