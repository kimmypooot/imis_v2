<script setup>
import FileInput from '../../Components/Forms/FileInput.vue';
import InputLabel from '../../Components/Forms/InputLabel.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Button from '../../Components/UI/Button.vue';
import Select from '../../Components/Forms/Select.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import Textarea from '../../Components/Forms/Textarea.vue';
import InputError from '../../Components/Forms/InputError.vue';

defineOptions({ layout: PortalLayout });

/**
 * HR-6b: filing leave on CS Form No. 6 (legacy leave/apply.php), or
 * correcting a returned application. As the type and dates change, the
 * server previews the days counted, the credits left after what's
 * pending, and anything that would stop the filing.
 */
const props = defineProps({
    types: { type: Array, required: true },
    detailOptions: { type: Object, required: true },
    balances: { type: Array, required: true },
    supervisor: { type: String, default: null },
    resubmitting: { type: Object, default: null },
    preview: { type: Object, default: null },
    attachmentKilobytes: { type: Number, required: true },
});

const source = props.resubmitting ?? {};
const form = useForm({
    leave_type_id: source.leave_type_id ?? '',
    date_from: source.date_from ?? '',
    date_to: source.date_to ?? '',
    is_half_day: source.is_half_day ?? false,
    half_day_period: source.half_day_period ?? 'AM',
    detail: source.detail ?? '',
    detail_text: source.detail_text ?? '',
    commutation_requested: source.commutation_requested ?? false,
    is_emergency: source.is_emergency ?? false,
    remarks: source.remarks ?? '',
    attachment: null,
});

const type = computed(() => props.types.find((option) => option.id === Number(form.leave_type_id)) ?? null);
const typeOptions = computed(() =>
    props.types.map((option) => ({ value: option.id, label: option.ineligible.length ? `${option.name} — not available to you` : option.name })),
);
const details = computed(() => (type.value ? (props.detailOptions[type.value.code] ?? []) : []));
const detail = computed(() => details.value.find((option) => option.value === form.detail) ?? null);

// A half day is a single date.
watch(() => form.is_half_day, (half) => half && (form.date_to = form.date_from));
watch(() => form.date_from, (from) => {
    if (form.is_half_day || !form.date_to || form.date_to < from) form.date_to = from;
});
watch(() => form.leave_type_id, () => {
    form.detail = '';
    form.detail_text = '';
});

// Ask the server again whenever what it checks changes (debounced).
let timer = null;
watch(
    () => [form.leave_type_id, form.date_from, form.date_to, form.is_half_day, form.is_emergency, form.detail, form.detail_text],
    () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (!form.leave_type_id || !form.date_from) return;
            router.reload({
                only: ['preview'],
                data: {
                    leave_type_id: form.leave_type_id, date_from: form.date_from, date_to: form.date_to || form.date_from,
                    is_half_day: form.is_half_day ? 1 : 0, is_emergency: form.is_emergency ? 1 : 0, detail: form.detail, detail_text: form.detail_text,
                    ...(props.resubmitting ? { resubmit: props.resubmitting.id } : {}),
                },
                preserveState: true,
                preserveScroll: true,
            });
        }, 300);
    },
    { immediate: !!props.resubmitting },
);

const issues = computed(() => props.preview?.issues ?? {});
const days = (value) => Number(value ?? 0).toLocaleString(undefined, { maximumFractionDigits: 3 });

const submit = () => {
    const options = { preserveScroll: true, forceFormData: true };
    const data = (values) => ({ ...values, is_half_day: values.is_half_day ? 1 : 0, commutation_requested: values.commutation_requested ? 1 : 0, is_emergency: values.is_emergency && type.value?.allows_emergency_filing ? 1 : 0, ...(props.resubmitting ? { _method: 'put' } : {}) });

    form.transform(data).post(props.resubmitting ? `/my/leave/applications/${props.resubmitting.id}` : '/my/leave/applications', options);
};

const field = 'mt-1 block w-full';
</script>

<template>
    <Head :title="resubmitting ? 'Correct leave application' : 'File leave'" />

    <PageHeader
        :title="resubmitting ? `Correct ${resubmitting.reference_number}` : 'File leave'"
        description="CS Form No. 6 (Application for Leave). Your immediate supervisor, HR and the Head of Agency act on it in turn."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Leave', href: '/my/leave' }, { label: resubmitting ? 'Correct application' : 'File leave' }]"
    />

    <div class="grid gap-6 xl:grid-cols-3">
        <Card title="Application" icon="clipboard" class="xl:col-span-2">
            <p v-if="resubmitting?.returned_remarks" class="mb-4 rounded-md border border-warning-border bg-warning-bg px-3 py-2 text-sm text-text">
                <strong>Returned for correction:</strong> {{ resubmitting.returned_remarks }}
            </p>

            <form id="leave-apply-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                <div class="sm:col-span-2">
                    <InputLabel for="apply-type" required>6.A Type of leave</InputLabel>
                    <Select id="apply-type" v-model="form.leave_type_id" :class="field" :options="typeOptions" placeholder="— Select —" required :invalid="!!(form.errors.leave_type_id || issues.leave_type_id)" />
                    <p v-if="type?.ineligible.length" class="mt-1 text-sm text-danger">{{ type.ineligible.join(' ') }}</p>
                    <InputError :message="form.errors.leave_type_id || issues.leave_type_id" />
                    <p v-if="type?.description" class="mt-1 text-xs text-text-muted">{{ type.description }}</p>
                    <p v-if="type?.supporting_documents" class="mt-1 text-xs text-text-muted">Attach: {{ type.supporting_documents }}</p>
                </div>

                <fieldset v-if="details.length" class="sm:col-span-2">
                    <legend class="block text-sm font-medium text-text">6.B Details of leave <span class="text-danger" aria-hidden="true">*</span></legend>
                    <div class="mt-1 flex flex-wrap gap-x-5 gap-y-1">
                        <label v-for="option in details" :key="option.value" class="inline-flex items-center gap-2 text-sm text-text">
                            <input v-model="form.detail" type="radio" :value="option.value" class="h-4 w-4 border-border-strong text-primary focus:ring-2 focus:ring-focus-ring" />
                            {{ option.label }}
                        </label>
                    </div>
                    <TextInput v-if="detail?.needs_text" id="apply-detail-text" v-model="form.detail_text" class="mt-2" label="Specify" required :invalid="!!(form.errors.detail_text || issues.detail_text)" />
                    <InputError :message="form.errors.detail || issues.detail || form.errors.detail_text || issues.detail_text" />
                </fieldset>

                <div>
                    <TextInput id="apply-from" v-model="form.date_from" type="date" label="6.C From" required :invalid="!!(form.errors.date_from || issues.date_from)" />
                    <InputError :message="form.errors.date_from || issues.date_from" />
                </div>
                <div>
                    <TextInput id="apply-to" v-model="form.date_to" type="date" label="To" required :disabled="form.is_half_day" :invalid="!!(form.errors.date_to || issues.date_to)" />
                    <InputError :message="form.errors.date_to || issues.date_to" />
                </div>

                <div v-if="type?.allows_emergency_filing" class="sm:col-span-2">
                    <label class="inline-flex items-center gap-2 text-sm text-text">
                        <input v-model="form.is_emergency" type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-primary focus:ring-2 focus:ring-focus-ring" />
                        Emergency: I'm filing on my return instead of {{ type.advance_filing_days }} days ahead
                    </label>
                </div>

                <div v-if="type?.allows_half_day" class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <label class="inline-flex items-center gap-2 text-sm text-text">
                        <input v-model="form.is_half_day" type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-primary focus:ring-2 focus:ring-focus-ring" />
                        Half day
                    </label>
                    <template v-if="form.is_half_day">
                        <label v-for="period in ['AM', 'PM']" :key="period" class="inline-flex items-center gap-1.5 text-sm text-text">
                            <input v-model="form.half_day_period" type="radio" :value="period" class="h-4 w-4 border-border-strong text-primary focus:ring-2 focus:ring-focus-ring" />{{ period }}
                        </label>
                    </template>
                    <InputError :message="form.errors.is_half_day || issues.is_half_day || form.errors.half_day_period" />
                </div>

                <fieldset class="sm:col-span-2">
                    <legend class="block text-sm font-medium text-text">6.D Commutation</legend>
                    <div class="mt-1 flex gap-5">
                        <label class="inline-flex items-center gap-2 text-sm text-text"><input v-model="form.commutation_requested" type="radio" :value="false" class="h-4 w-4 border-border-strong text-primary" />Not requested</label>
                        <label class="inline-flex items-center gap-2 text-sm text-text"><input v-model="form.commutation_requested" type="radio" :value="true" class="h-4 w-4 border-border-strong text-primary" />Requested</label>
                    </div>
                </fieldset>

                <div class="sm:col-span-2">
                    <InputLabel for="apply-remarks">Remarks (optional)</InputLabel>
                    <Textarea id="apply-remarks" v-model="form.remarks" class="mt-1" :invalid="!!form.errors.remarks" />
                    <InputError :message="form.errors.remarks" />
                </div>

                <div class="sm:col-span-2">
                    <label for="apply-attachment" class="block text-sm font-medium text-text">
                        Supporting document
                        <template v-if="type?.medical_certificate_after_days !== null && type?.medical_certificate_after_days !== undefined">
                            (a medical certificate is required over {{ type.medical_certificate_after_days }} days)
                        </template>
                    </label>
                    <p v-if="resubmitting?.has_attachment" class="mt-1 text-sm text-text">Already attached: {{ resubmitting.attachment_name }}. Choose a file only to replace it.</p>
                    <FileInput
                        id="apply-attachment"
                        v-model="form.attachment"
                        class="mt-1"
                        accept="application/pdf,image/jpeg,image/png"
                        :max-size-mb="Math.round(attachmentKilobytes / 1024)"
                        :invalid="!!(form.errors.attachment || issues.attachment)"
                        :hint="`PDF, JPG or PNG, up to ${Math.round(attachmentKilobytes / 1024)} MB. Only you, your approvers and HR can open it.`"
                    />
                    <InputError :message="form.errors.attachment || issues.attachment" />
                </div>
            </form>

            <div class="mt-6 flex justify-end gap-3">
                <Link href="/my/leave/applications" class="rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted">Cancel</Link>
                <Button type="submit" form="leave-apply-form" variant="success" :loading="form.processing">{{ resubmitting ? 'Resubmit' : 'Submit application' }}</Button>
            </div>
        </Card>

        <div class="space-y-6">
            <Card title="Summary" icon="calendar">
                <dl class="space-y-3 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-text-muted">{{ type?.counts_calendar_days ? 'Calendar days' : 'Working days applied for' }}</dt>
                        <dd class="font-semibold tabular-nums text-text">{{ preview ? days(preview.days) : '—' }}</dd>
                    </div>
                    <template v-if="preview?.credit">
                        <div class="flex justify-between gap-3">
                            <dt class="text-text-muted">{{ preview.credit.code }} balance</dt>
                            <dd class="tabular-nums text-text">{{ days(preview.credit.balance) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3">
                            <dt class="text-text-muted">Pending applications</dt>
                            <dd class="tabular-nums text-text">− {{ days(preview.credit.pending) }}</dd>
                        </div>
                        <div class="flex justify-between gap-3 border-t border-border pt-2">
                            <dt class="font-medium text-text">Available</dt>
                            <dd class="font-semibold tabular-nums text-text">{{ days(preview.credit.available) }}</dd>
                        </div>
                    </template>
                    <div class="border-t border-border pt-2">
                        <dt class="text-text-muted">Immediate supervisor</dt>
                        <dd class="mt-0.5 text-text">{{ supervisor || 'Not set — ask HR' }}</dd>
                    </div>
                </dl>
                <p v-if="preview && Object.keys(issues).length === 0 && preview.days > 0" class="mt-4 rounded-md bg-success-bg px-3 py-2 text-sm text-success">Ready to submit.</p>
            </Card>

            <Card title="Your credits" icon="chartBar">
                <ul class="divide-y divide-border text-sm">
                    <li v-for="balance in balances" :key="balance.type_id" class="flex justify-between py-1.5">
                        <span class="text-text">{{ balance.code }} · {{ balance.name }}</span>
                        <span class="tabular-nums font-medium text-text">{{ days(balance.balance) }}</span>
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
