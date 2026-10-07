<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import Alert from '../../../../Components/Feedback/Alert.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Textarea from '../../../../Components/Forms/Textarea.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import PdsEditor from '../../../../Components/HR/Pds/PdsEditor.vue';
import { formatDateTime } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-2b: reviewing a signed PDS. Shows exactly what the employee signed,
 * flags the sections that changed since their last verified PDS, and
 * verifies it (into the 201 file) or returns it with remarks.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    schema: { type: Object, required: true },
    submission: { type: Object, required: true },
    changedSections: { type: Array, default: null },
    previousVerifiedAt: { type: String, default: null },
    canReview: { type: Boolean, required: true },
});

const decision = ref(null); // 'verify' | 'return'
const form = useForm({ remarks: '' });

const decide = (kind) => form.post(`/admin/hr/pds-verifications/${props.submission.id}/${kind}`);

const changedLabels = computed(() =>
    (props.changedSections ?? []).map((key) => (key === 'identity' ? 'Name and identity' : props.schema.sections[key]?.label ?? key)),
);

const badge = { submitted: 'info', verified: 'active', returned: 'inactive', superseded: 'draft' };

const breadcrumbs = computed(() => [
    { label: 'Human Resources', href: '/admin/hr' },
    { label: 'PDS Verification', href: '/admin/hr/pds-verifications' },
    { label: props.employee.name },
]);
</script>

<template>
    <Head :title="`PDS review — ${employee.name}`" />

    <PageHeader :title="`PDS of ${employee.name}`" :breadcrumbs="breadcrumbs" />

    <div class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-3">
            <Card title="Signature" icon="pencil" class="lg:col-span-2">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="space-y-1 text-sm">
                        <StatusBadge :status="badge[submission.status]" :label="submission.status_label" />
                        <p class="text-text">E-signed by <strong>{{ submission.signer }}</strong> on {{ formatDateTime(submission.signed_at) }}</p>
                        <p v-if="submission.signed_ip" class="text-xs text-text-muted">From {{ submission.signed_ip }}</p>
                        <p v-if="submission.reviewed_at" class="text-text-muted">
                            {{ submission.status === 'verified' ? 'Verified' : 'Returned' }} by {{ submission.reviewer }} on {{ formatDateTime(submission.reviewed_at) }}
                        </p>
                        <p v-if="submission.remarks" class="whitespace-pre-line text-text">Remarks: {{ submission.remarks }}</p>
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

            <Card title="Photo" icon="camera">
                <img v-if="submission.photo_url" :src="submission.photo_url" alt="PDS photo as signed" class="h-36 w-28 rounded border border-border object-cover" />
                <p v-else class="text-sm text-warning">No photo was attached.</p>
            </Card>
        </div>

        <Alert v-if="changedSections === null" tone="info">This is the employee's first PDS submission — review every section.</Alert>
        <Alert v-else-if="changedSections.length" tone="warning" title="Changed since the last verified PDS">
            {{ changedLabels.join(' · ') }}<template v-if="previousVerifiedAt"> (verified {{ formatDateTime(previousVerifiedAt) }})</template>.
            Changed tabs are marked below.
        </Alert>
        <Alert v-else tone="success">Nothing changed in the content since the last verified PDS.</Alert>

        <PdsEditor :schema="schema" :pds="submission.pds" readonly :highlight="changedSections ?? []" />

        <Card v-if="canReview" title="Decision" icon="checkCircle">
            <div class="space-y-3">
                <div>
                    <label for="pds-review-remarks" class="block text-sm font-medium text-text">
                        Remarks <span class="font-normal text-text-muted">(required when returning — tell the employee what to fix)</span>
                    </label>
                    <Textarea id="pds-review-remarks" v-model="form.remarks" class="mt-1" :invalid="!!form.errors.remarks" />
                    <InputError :message="form.errors.remarks" />
                </div>
                <div class="flex flex-wrap justify-end gap-2">
                    <Button variant="danger" :loading="form.processing && decision === 'return'" :disabled="form.processing" @click="(decision = 'return'), decide('return')">
                        Return to employee
                    </Button>
                    <Button variant="success" :loading="form.processing && decision === 'verify'" :disabled="form.processing" @click="(decision = 'verify'), decide('verify')">
                        <Icon name="check" size="sm" />Verify and file
                    </Button>
                </div>
            </div>
        </Card>
        <Alert v-else-if="submission.status === 'submitted'" tone="info">
            You can't review this submission (it's your own, or you don't have PDS verification access).
        </Alert>
    </div>
</template>
