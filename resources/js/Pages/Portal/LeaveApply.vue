<script setup>
import FileInput from '@cscro8/ui/Components/Forms/FileInput.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Select from '@cscro8/ui/Components/Forms/Select.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import Textarea from '@cscro8/ui/Components/Forms/Textarea.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import PasswordInput from '@cscro8/ui/Components/Forms/PasswordInput.vue';

defineOptions({ layout: PortalLayout });

/**
 * HR-6b: filing leave on CS Form No. 6 (legacy leave/apply.php), or
 * correcting a returned application. The dates are a from–to range, or so
 * many days each picked (e.g. VL on separate days). As the type and dates change, the
 * server previews the days counted, the credits left after what's
 * pending, and anything that would stop the filing.
 */
const props = defineProps({
    types: { type: Array, required: true },
    detailOptions: { type: Object, required: true },
    balances: { type: Array, required: true },
    supervisor: { type: String, default: null },
    hasSignature: { type: Boolean, default: false },
    resubmitting: { type: Object, default: null },
    // A day to file for, e.g. from an absence on the DTR.
    prefill: { type: Object, default: null },
    preview: { type: Object, default: null },
    maxChosenDates: { type: Number, default: 60 },
    attachmentKilobytes: { type: Number, required: true },
});

const source = props.resubmitting ?? props.prefill ?? {};
const form = useForm({
    leave_type_id: source.leave_type_id ?? '',
    date_from: source.date_from ?? '',
    date_to: source.date_to ?? '',
    // 'range': from–to; 'chosen': so many days, each date picked (e.g. VL on separate days).
    date_mode: source.dates?.length ? 'chosen' : 'range',
    day_count: source.dates?.length ?? '',
    dates: source.dates ? [...source.dates] : [],
    is_half_day: source.is_half_day ?? false,
    half_day_period: source.half_day_period ?? 'AM',
    detail: source.detail ?? '',
    detail_text: source.detail_text ?? '',
    commutation_requested: source.commutation_requested ?? false,
    is_emergency: source.is_emergency ?? false,
    remarks: source.remarks ?? '',
    attachment: null,
    // CSC filing rules: a reason when filed later than "whenever possible",
    // the date the leave depends on (calamity), the clearance for 30+ days.
    late_reason: source.late_reason ?? '',
    event_date: source.event_date ?? '',
    clearance: null,
    // Optional e-signature (item 6.C): ticked, it needs the account password.
    sign: false,
    password: '',
});

const type = computed(() => props.types.find((option) => option.id === Number(form.leave_type_id)) ?? null);
const typeOptions = computed(() =>
    props.types.map((option) => ({ value: option.id, label: option.ineligible.length ? `${option.name} — not available to you` : option.name })),
);
const details = computed(() => (type.value ? (props.detailOptions[type.value.code] ?? []) : []));
const detail = computed(() => details.value.find((option) => option.value === form.detail) ?? null);

// Picking dates: one date field per day asked for (kept as the count changes).
const chosen = computed(() => form.date_mode === 'chosen');
const pickedDates = computed(() => form.dates.filter(Boolean));
watch(() => form.day_count, (count) => {
    const n = Math.max(0, Math.min(props.maxChosenDates, Math.floor(Number(count) || 0)));
    form.dates = Array.from({ length: n }, (_, index) => form.dates[index] ?? '');
});
watch(() => form.date_mode, (mode) => {
    if (mode === 'chosen') form.is_half_day = false;
});
// Leave counted in calendar days (maternity) runs on consecutive days.
watch(() => type.value?.counts_calendar_days, (calendar) => {
    if (calendar) form.date_mode = 'range';
});
const datesError = computed(() => form.errors.dates || Object.entries(form.errors).find(([key]) => key.startsWith('dates.'))?.[1] || issues.value.dates);
const missingDates = ref(false);

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
    () => [form.leave_type_id, form.date_mode, form.date_from, form.date_to, pickedDates.value.join(','), form.is_half_day, form.is_emergency, form.detail, form.detail_text, form.late_reason, form.event_date],
    () => {
        clearTimeout(timer);
        timer = setTimeout(() => {
            if (!form.leave_type_id || (chosen.value ? !pickedDates.value.length : !form.date_from)) return;
            const dates = chosen.value ? { dates: pickedDates.value } : { date_from: form.date_from, date_to: form.date_to || form.date_from };
            router.reload({
                only: ['preview'],
                data: {
                    leave_type_id: form.leave_type_id, ...dates,
                    is_half_day: form.is_half_day ? 1 : 0, is_emergency: form.is_emergency ? 1 : 0, detail: form.detail, detail_text: form.detail_text, late_reason: form.late_reason, event_date: form.event_date,
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
    missingDates.value = chosen.value && (!form.dates.length || pickedDates.value.length < form.dates.length);
    if (missingDates.value) return;

    const options = { preserveScroll: true, forceFormData: true, onFinish: () => form.reset('password') };
    const dates = (values) => (values.date_mode === 'chosen'
        ? { dates: values.dates.filter(Boolean), date_from: '', date_to: '' }
        : { dates: [] });
    const data = ({ date_mode, day_count, ...values }) => ({ ...values, ...dates({ ...values, date_mode }), is_half_day: values.is_half_day ? 1 : 0, commutation_requested: values.commutation_requested ? 1 : 0, is_emergency: values.is_emergency && type.value?.allows_emergency_filing ? 1 : 0, sign: values.sign ? 1 : 0, ...(props.resubmitting ? { _method: 'put' } : {}) });

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
                    <p v-if="type?.ineligible.length" class="mt-1 text-sm text-danger-fg">{{ type.ineligible.join(' ') }}</p>
                    <InputError :message="form.errors.leave_type_id || issues.leave_type_id" />
                    <p v-if="type?.description" class="mt-1 text-xs text-text-muted">{{ type.description }}</p>
                    <p v-if="type?.supporting_documents" class="mt-1 text-xs text-text-muted">Attach: {{ type.supporting_documents }}</p>
                </div>

                <fieldset v-if="details.length" class="sm:col-span-2">
                    <legend class="block text-sm font-medium text-text">6.B Details of leave <span class="text-danger-fg" aria-hidden="true">*</span></legend>
                    <div class="mt-1 flex flex-wrap gap-x-5 gap-y-1">
                        <label v-for="option in details" :key="option.value" class="inline-flex items-center gap-2 text-sm text-text">
                            <input v-model="form.detail" type="radio" :value="option.value" class="h-4 w-4 border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                            {{ option.label }}
                        </label>
                    </div>
                    <TextInput
                        v-if="detail?.takes_text"
                        id="apply-detail-text"
                        v-model="form.detail_text"
                        class="mt-2"
                        :label="form.detail === 'within_ph' ? 'Where (optional)' : 'Specify'"
                        :required="detail.needs_text"
                        :helper-text="form.detail === 'within_ph' ? 'Printed on the form, e.g. Residence, or the city or province.' : ''"
                        :invalid="!!(form.errors.detail_text || issues.detail_text)"
                    />
                    <InputError :message="form.errors.detail || issues.detail || form.errors.detail_text || issues.detail_text" />
                </fieldset>

                <fieldset class="sm:col-span-2">
                    <legend class="block text-sm font-medium text-text">6.C Dates</legend>
                    <div class="mt-1 flex flex-wrap gap-x-5 gap-y-1">
                        <label class="inline-flex items-center gap-2 text-sm text-text">
                            <input v-model="form.date_mode" type="radio" value="range" class="h-4 w-4 border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                            From – to (days in a row)
                        </label>
                        <label class="inline-flex items-center gap-2 text-sm" :class="type?.counts_calendar_days ? 'text-text-muted' : 'text-text'">
                            <input v-model="form.date_mode" type="radio" value="chosen" :disabled="!!type?.counts_calendar_days" class="h-4 w-4 border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                            Pick the dates (separate days)
                        </label>
                    </div>
                    <p v-if="type?.counts_calendar_days" class="mt-1 text-xs text-text-muted">{{ type.name }} runs on consecutive days, so it takes a start and end date.</p>
                </fieldset>

                <template v-if="!chosen">
                    <div>
                        <TextInput id="apply-from" v-model="form.date_from" type="date" label="From" required :invalid="!!(form.errors.date_from || issues.date_from)" />
                        <InputError :message="form.errors.date_from || issues.date_from" />
                    </div>
                    <div>
                        <TextInput id="apply-to" v-model="form.date_to" type="date" label="To" required :disabled="form.is_half_day" :invalid="!!(form.errors.date_to || issues.date_to)" />
                        <InputError :message="form.errors.date_to || issues.date_to" />
                    </div>
                </template>

                <div v-else class="sm:col-span-2">
                    <TextInput
                        id="apply-day-count"
                        v-model="form.day_count"
                        type="number"
                        label="How many days?"
                        required
                        :helper-text="`Then pick each date below (working days only, up to ${maxChosenDates}).`"
                        class="max-w-48"
                    />
                    <div v-if="form.dates.length" class="mt-3 grid gap-3 sm:grid-cols-3 lg:grid-cols-4">
                        <TextInput
                            v-for="(date, index) in form.dates"
                            :id="`apply-date-${index}`"
                            :key="index"
                            v-model="form.dates[index]"
                            type="date"
                            :label="`Day ${index + 1}`"
                            required
                            :invalid="!!(datesError || (missingDates && !date))"
                        />
                    </div>
                    <InputError :message="missingDates ? `Pick all ${form.dates.length} dates.` : datesError" />
                </div>

                <div v-if="type?.event_label" class="sm:col-span-2 sm:max-w-xs">
                    <TextInput
                        id="apply-event-date"
                        v-model="form.event_date"
                        type="date"
                        :label="type.event_label"
                        required
                        :helper-text="type.event_window_days ? `The leave is taken within ${type.event_window_days} days of it.` : ''"
                        :invalid="!!(form.errors.event_date || issues.event_date)"
                    />
                    <InputError :message="form.errors.event_date || issues.event_date" />
                </div>

                <div v-if="preview?.needs?.late_reason" class="sm:col-span-2">
                    <InputLabel for="apply-late-reason" required>Why it's filed less than {{ type?.advance_filing_days }} days ahead</InputLabel>
                    <Textarea id="apply-late-reason" v-model="form.late_reason" class="mt-1" :invalid="!!(form.errors.late_reason || issues.late_reason)" />
                    <p class="mt-1 text-xs text-text-muted">{{ type?.name }} is filed {{ type?.advance_filing_days }} days ahead whenever possible. Your approvers see this reason.</p>
                    <InputError :message="form.errors.late_reason || issues.late_reason" />
                </div>

                <div v-if="type?.allows_emergency_filing" class="sm:col-span-2">
                    <label class="inline-flex items-center gap-2 text-sm text-text">
                        <input v-model="form.is_emergency" type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                        Emergency: I'm filing on my return instead of {{ type.advance_filing_days }} days ahead
                    </label>
                </div>

                <div v-if="type?.allows_half_day && !chosen" class="flex flex-wrap items-center gap-4 sm:col-span-2">
                    <label class="inline-flex items-center gap-2 text-sm text-text">
                        <input v-model="form.is_half_day" type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                        Half day
                    </label>
                    <template v-if="form.is_half_day">
                        <label v-for="period in ['AM', 'PM']" :key="period" class="inline-flex items-center gap-1.5 text-sm text-text">
                            <input v-model="form.half_day_period" type="radio" :value="period" class="h-4 w-4 border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />{{ period }}
                        </label>
                    </template>
                    <InputError :message="form.errors.is_half_day || issues.is_half_day || form.errors.half_day_period" />
                </div>

                <fieldset class="sm:col-span-2">
                    <legend class="block text-sm font-medium text-text">6.D Commutation</legend>
                    <div class="mt-1 flex gap-5">
                        <label class="inline-flex items-center gap-2 text-sm text-text"><input v-model="form.commutation_requested" type="radio" :value="false" class="h-4 w-4 border-border-strong text-link" />Not requested</label>
                        <label class="inline-flex items-center gap-2 text-sm text-text"><input v-model="form.commutation_requested" type="radio" :value="true" class="h-4 w-4 border-border-strong text-link" />Requested</label>
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
                        <span v-if="type?.requires_attachment" class="text-danger-fg" aria-hidden="true">*</span>
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

                <div v-if="preview?.needs?.clearance" class="sm:col-span-2">
                    <label for="apply-clearance" class="block text-sm font-medium text-text">Clearance from money, property and work accountabilities <span class="text-danger-fg" aria-hidden="true">*</span></label>
                    <p v-if="resubmitting?.has_clearance" class="mt-1 text-sm text-text">Already attached: {{ resubmitting.clearance_name }}. Choose a file only to replace it.</p>
                    <FileInput
                        id="apply-clearance"
                        v-model="form.clearance"
                        class="mt-1"
                        accept="application/pdf,image/jpeg,image/png"
                        :max-size-mb="Math.round(attachmentKilobytes / 1024)"
                        :invalid="!!(form.errors.clearance || issues.clearance)"
                        hint="Needed for leave of 30 calendar days or more (CSC MC No. 2, s. 1985). PDF, JPG or PNG."
                    />
                    <InputError :message="form.errors.clearance || issues.clearance" />
                </div>

                <div class="rounded-md border border-border bg-surface-muted px-3 py-3 sm:col-span-2">
                    <label class="flex items-start gap-2 text-sm font-medium text-text">
                        <input v-model="form.sign" type="checkbox" class="mt-0.5 h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring" />
                        <span>Sign electronically <span class="font-normal text-text-muted">(optional)</span></span>
                    </label>
                    <template v-if="form.sign">
                        <p v-if="hasSignature" class="mt-1 pl-6 text-xs text-success-fg">Your saved signature will be placed on the form.</p>
                        <p v-else class="mt-1 pl-6 text-xs text-text-muted">No saved signature yet. <a href="/my/employee-record" class="text-link hover:underline">Add one</a> to have it appear on the printed form.</p>
                    </template>
                    <div v-if="form.sign" class="mt-3 max-w-sm">
                        <PasswordInput id="apply-sign-password" v-model="form.password" label="Your IMIS password" autocomplete="current-password" required :invalid="!!form.errors.password" />
                        <p class="mt-1 text-xs text-text-muted">Re-entering your password is your electronic signature as applicant. It is recorded with the date and time.</p>
                        <InputError :message="form.errors.password" />
                    </div>
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
                    <template v-if="preview?.pay && (Object.keys(preview.pay.charges).length > 1 || preview.pay.without_pay > 0)">
                        <div v-for="(charged, code) in preview.pay.charges" :key="code" class="flex justify-between gap-3">
                            <dt class="text-text-muted">Charged to {{ code }}</dt>
                            <dd class="tabular-nums text-text">{{ days(charged) }}</dd>
                        </div>
                        <div v-if="preview.pay.without_pay > 0" class="flex justify-between gap-3">
                            <dt class="text-text-muted">Without pay</dt>
                            <dd class="tabular-nums text-text">{{ days(preview.pay.without_pay) }}</dd>
                        </div>
                    </template>
                    <div class="border-t border-border pt-2">
                        <dt class="text-text-muted">Immediate supervisor</dt>
                        <dd class="mt-0.5 text-text">{{ supervisor || 'Not set — ask HR' }}</dd>
                    </div>
                </dl>
                <ul v-if="preview?.notices?.length" class="mt-4 space-y-2">
                    <li v-for="notice in preview.notices" :key="notice" class="rounded-md border border-warning-border bg-warning-bg px-3 py-2 text-sm text-text">{{ notice }}</li>
                </ul>
                <p v-if="preview && Object.keys(issues).length === 0 && preview.days > 0" class="mt-4 rounded-md bg-success-bg px-3 py-2 text-sm text-success-fg">Ready to submit.</p>
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
