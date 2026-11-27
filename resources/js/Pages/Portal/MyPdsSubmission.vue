<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import FileInput from '@cscro8/ui/Components/Forms/FileInput.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import PdsEditor from '../../Components/HR/Pds/PdsEditor.vue';
import PdsPnpkiCopy from '../../Components/HR/Pds/PdsPnpkiCopy.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * One of your past PDS submissions, exactly as you signed it (read-only),
 * and its PNPKI-signed copy: print it for PNPKI signing, sign each page's
 * box with your certificate outside IMIS, then attach the PDF here to be
 * checked and filed in your 201 File (PdsPnpki).
 */
const props = defineProps({
    schema: { type: Object, required: true },
    submission: { type: Object, required: true },
    pnpki: { type: Object, required: true },
});

const badge = { submitted: 'info', verified: 'active', returned: 'inactive', superseded: 'draft' };

const form = useForm({ file: null });
const attach = () =>
    form.post(`/my/pds/submissions/${props.submission.id}/pnpki`, {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset(),
    });
</script>

<template>
    <Head title="PDS submission" />

    <PageHeader
        :title="`PDS signed ${formatDateTime(submission.signed_at)}`"
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Personal Data Sheet', href: '/my/pds' }, { label: 'Submission' }]"
    />

    <div class="space-y-6">
        <Card>
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="flex items-start gap-4">
                    <img v-if="submission.photo_url" :src="submission.photo_url" alt="PDS photo as signed" class="h-28 w-22 rounded-sm border border-border object-cover" />
                    <div class="space-y-1">
                        <StatusBadge :status="badge[submission.status]" :label="submission.status_label" />
                        <p v-if="submission.reviewed_at" class="text-sm text-text-muted">
                            {{ submission.status === 'verified' ? 'Verified' : 'Returned' }} by {{ submission.reviewer }} on {{ formatDateTime(submission.reviewed_at) }}
                        </p>
                        <p v-if="submission.remarks" class="text-sm whitespace-pre-line text-text">Remarks: {{ submission.remarks }}</p>
                    </div>
                </div>
                <a
                    :href="`/pds/submissions/${submission.id}/print`"
                    target="_blank"
                    rel="noopener"
                    class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                >
                    <Icon name="printer" size="sm" />Print<span class="sr-only"> (opens in a new tab)</span>
                </a>
            </div>
        </Card>

        <Card
            v-if="pnpki.copy || pnpki.can_attach"
            title="PNPKI-signed copy"
            icon="documentCheck"
            description="Digitally sign this PDS with your PNPKI certificate and attach the signed PDF. It is checked, then filed in your 201 File."
        >
            <PdsPnpkiCopy v-if="pnpki.copy" :copy="pnpki.copy" class="mb-5" />

            <template v-if="pnpki.can_attach">
                <ol v-if="!pnpki.copy" class="mb-4 list-decimal space-y-1 pl-5 text-sm text-text">
                    <li>
                        <a :href="pnpki.print_url" target="_blank" rel="noopener" class="font-medium text-link hover:underline">
                            Print it for PNPKI signing<span class="sr-only"> (opens in a new tab)</span>
                        </a>
                        and choose “Save as PDF” (8.5 × 13 in, margins None, scale 100%).
                    </li>
                    <li>
                        Sign the signature box on every page ({{ pnpki.pages }} pages) with your PNPKI certificate, in Adobe Acrobat or your PNPKI
                        signing tool. Save after each signature.
                    </li>
                    <li>Don't change the PDF after signing, then attach it below.</li>
                </ol>

                <form class="space-y-3" @submit.prevent="attach">
                    <div>
                        <InputLabel for="pnpki-file">{{ pnpki.copy ? 'Replace with a newer signed PDF' : 'The PNPKI-signed PDF' }}</InputLabel>
                        <FileInput id="pnpki-file" v-model="form.file" accept=".pdf,application/pdf" :max-size-mb="20" :invalid="!!form.errors.file" class="mt-1" />
                        <InputError :message="form.errors.file" />
                    </div>
                    <div class="flex justify-end">
                        <Button type="submit" icon="check" :loading="form.processing" :disabled="!form.file">Check and file it</Button>
                    </div>
                </form>
            </template>
        </Card>

        <PdsEditor :schema="schema" :pds="submission.pds" readonly />
    </div>
</template>
