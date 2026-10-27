<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import Modal from '../../../../Components/Feedback/Modal.vue';
import Select from '../../../../Components/Forms/Select.vue';
import TextInput from '../../../../Components/Forms/TextInput.vue';
import Textarea from '../../../../Components/Forms/Textarea.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import RecordModal from '../../../../Components/Admin/RecordModal.vue';
import { plantillaItemDefaults, plantillaItemFields, plantillaItemNullable } from '../../../../Components/HR/plantillaFields';
import { confirm } from '../../../../Composables/useDialog';
import { formatCalendarDate } from '../../../../Utils/datetime';
import { formatPeso } from '../../../../Utils/money';

defineOptions({ layout: AdminLayout });

/**
 * HR-4: one plantilla item — its details, who holds it, and everyone who
 * has (each period of service). HR appoints, records step increments and
 * vacancies, corrects or removes a period, and abolishes or restores the
 * item. Never on their own appointment.
 */
const props = defineProps({
    item: { type: Object, required: true },
    history: { type: Array, required: true },
    canManage: { type: Boolean, required: true },
    itemForm: { type: Object, default: null },
    appointForm: { type: Object, default: null },
    appointmentOptions: { type: Object, required: true },
});

const base = computed(() => `/admin/hr/plantilla/${props.item.id}`);
const tones = { filled: 'active', vacant: 'warning', abolished: 'logged_out' };
const editOpen = ref(false);

// One modal for every action; `mode` picks the fields and the request.
const mode = ref(null);
const target = ref(null);
const form = useForm({});

const titles = {
    appoint: 'Appoint to this item',
    step: 'Step increment',
    vacate: 'Vacate the item',
    correct: 'Correct this period',
    abolish: 'Abolish the item',
};

const open = (next, row = null) => {
    target.value = row;
    const today = new Date().toISOString().slice(0, 10);
    const values = {
        appoint: { employee_id: '', step: 1, status: 'permanent', nature: 'original', date_from: '', salary_override: '', remarks: '' },
        step: { step: Math.min(8, (row?.step ?? 1) + 1), date_from: '', salary_override: '', remarks: '' },
        vacate: { date_to: '', end_reason: '' },
        correct: row && {
            // An incomplete link has blanks; HR fills them in here.
            step: row.step ?? '', status: row.status ?? '', nature: row.nature ?? '', date_from: row.date_from ?? '',
            date_to: row.date_to ?? '', end_reason: row.end_reason ?? '', salary_override: row.salary_override ?? '', remarks: row.remarks ?? '',
        },
        abolish: { abolished_on: today, abolition_authority: '' },
    }[next];

    // Fields left over from another action are ignored by that action's endpoint.
    form.defaults(values);
    form.reset();
    form.clearErrors();
    mode.value = next;
};

const close = () => (mode.value = null);

const submit = () => {
    const options = { preserveScroll: true, onSuccess: close };
    const appointment = target.value ? `${base.value}/appointments/${target.value.id}` : null;
    const blankToNull = (data) => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value]));

    ({
        appoint: () => form.transform(blankToNull).post(`${base.value}/appointments`, options),
        step: () => form.transform(blankToNull).post(`${appointment}/step-increment`, options),
        vacate: () => form.patch(`${appointment}/vacate`, options),
        correct: () => form.transform(blankToNull).put(appointment, options),
        abolish: () => form.transform(blankToNull).patch(`${base.value}/abolish`, options),
    })[mode.value]();
};

const restore = async () => {
    if (await confirm({ title: 'Restore this item?', text: 'It becomes an active, vacant item again.', confirmText: 'Restore' })) {
        router.patch(`${base.value}/restore`, {}, { preserveScroll: true });
    }
};

const remove = async (row) => {
    if (await confirm({
        title: 'Remove this period?',
        text: 'Only for a period recorded by mistake. If it ended their previous period (a promotion or a step increment), that one is reopened.',
        tone: 'danger',
        confirmText: 'Remove',
    })) {
        router.delete(`${base.value}/appointments/${row.id}`, { preserveScroll: true });
    }
};

const period = (row) => `${row.date_from ? formatCalendarDate(row.date_from) : 'Start not set'} – ${row.date_to ? formatCalendarDate(row.date_to) : 'present'}`;
const steps = Array.from({ length: 8 }, (_, index) => ({ value: index + 1, label: `Step ${index + 1}` }));
const field = 'mt-1 block w-full';
</script>

<template>
    <Head :title="`Item ${item.item_number || item.position_title}`" />

    <PageHeader
        :title="item.position_title"
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Plantilla', href: '/admin/hr/plantilla' }, { label: item.item_number || 'No item no.' }]"
    >
        <template v-if="canManage" #actions>
            <Button v-if="appointForm" variant="success" @click="open('appoint')"><Icon name="plus" size="sm" />Appoint</Button>
            <Button variant="secondary" @click="editOpen = true"><Icon name="pencil" size="sm" />Edit item</Button>
            <Button v-if="item.status === 'vacant'" variant="danger" @click="open('abolish')">Abolish</Button>
            <Button v-if="item.abolished" variant="secondary" @click="restore">Restore</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <Card title="Item" icon="grid">
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div><dt class="text-text-muted">Item number</dt><dd class="mt-0.5 font-medium text-text">{{ item.item_number || 'Not yet assigned' }}</dd></div>
                <div><dt class="text-text-muted">Salary grade</dt><dd class="mt-0.5 font-medium text-text">{{ item.salary_grade ? `SG ${item.salary_grade}` : 'Not set' }}</dd></div>
                <div><dt class="text-text-muted">Level</dt><dd class="mt-0.5 font-medium text-text">{{ item.level_label || '—' }}</dd></div>
                <div><dt class="text-text-muted">Place of assignment</dt><dd class="mt-0.5 font-medium text-text">{{ item.office || '—' }}</dd></div>
                <div>
                    <dt class="text-text-muted">Status</dt>
                    <dd class="mt-0.5"><StatusBadge :status="tones[item.status]" :label="item.status" /></dd>
                </div>
                <div>
                    <dt class="text-text-muted">Incumbent</dt>
                    <dd class="mt-0.5 font-medium text-text">
                        <Link v-if="item.holder" :href="`/admin/hr/employees/${item.holder.employee_id}`" class="text-primary hover:underline">{{ item.holder.name }}</Link>
                        <template v-else>—</template>
                        <div v-if="item.holder && !item.holder.missing.length" class="text-xs font-normal text-text-muted">
                            Step {{ item.holder.step }} · {{ item.holder.status_label }} since {{ formatCalendarDate(item.holder.since) }}
                        </div>
                        <div v-else-if="item.holder" class="text-xs font-normal text-warning">
                            Incomplete link: missing {{ item.holder.missing.join(', ') }}. Use Correct below to complete it.
                        </div>
                        <div v-if="item.serving?.elsewhere" class="text-xs font-normal text-info">
                            Serving at {{ item.serving.office }}<template v-if="item.serving.since"> since {{ formatCalendarDate(item.serving.since) }}</template>
                            <template v-if="item.serving.kind"> ({{ item.serving.kind }}<template v-if="item.serving.order_number"> {{ item.serving.order_number }}</template>)</template>
                        </div>
                    </dd>
                </div>
                <div>
                    <dt class="text-text-muted">Monthly salary</dt>
                    <dd class="mt-0.5 font-medium tabular-nums text-text">{{ formatPeso(item.monthly_salary) }}</dd>
                </div>
                <div><dt class="text-text-muted">Authority</dt><dd class="mt-0.5 text-text">{{ item.authority || '—' }}</dd></div>
                <div v-if="item.abolished" class="sm:col-span-2">
                    <dt class="text-text-muted">Abolished</dt>
                    <dd class="mt-0.5 text-text">{{ formatCalendarDate(item.abolished_on) }}<template v-if="item.abolition_authority"> · {{ item.abolition_authority }}</template></dd>
                </div>
                <div v-if="item.remarks" class="sm:col-span-2 lg:col-span-4">
                    <dt class="text-text-muted">Remarks</dt><dd class="mt-0.5 whitespace-pre-line text-text">{{ item.remarks }}</dd>
                </div>
            </dl>
        </Card>

        <Card title="Holders" icon="users" description="Every period of service on this item, newest first.">
            <p v-if="history.length === 0" class="text-sm text-text-muted">Nobody has held this item yet.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-text-muted">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">Employee</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Step</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Status / nature</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Period</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">Monthly salary</th>
                            <th v-if="canManage" scope="col" class="py-2 font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in history" :key="row.id">
                            <td class="py-2 pr-4 align-top">
                                <Link :href="`/admin/hr/employees/${row.employee_id}`" class="font-medium text-primary hover:underline">{{ row.name }}</Link>
                                <StatusBadge v-if="row.current" status="active" label="Current" class="ml-2" />
                                <div v-if="row.missing.length" class="text-xs text-warning">Incomplete: missing {{ row.missing.join(', ') }}</div>
                            </td>
                            <td class="py-2 pr-4 align-top tabular-nums">{{ row.step ?? '—' }}</td>
                            <td class="py-2 pr-4 align-top">{{ row.status_label ?? '—' }}<div class="text-xs text-text-muted">{{ row.nature_label }}</div></td>
                            <td class="py-2 pr-4 align-top">
                                <span class="whitespace-nowrap">{{ period(row) }}</span>
                                <div v-if="row.end_reason" class="text-xs text-text-muted">{{ row.end_reason }}</div>
                            </td>
                            <td class="py-2 pr-4 text-right align-top tabular-nums">{{ formatPeso(row.monthly_salary) }}<span v-if="row.salary_override !== null" class="block text-xs text-text-muted">Override</span></td>
                            <td v-if="canManage" class="py-2 align-top">
                                <div v-if="row.can_manage" class="flex flex-wrap justify-end gap-3 whitespace-nowrap">
                                    <button v-if="row.current && !row.missing.length && row.step < 8" type="button" class="text-success hover:underline" @click="open('step', row)">Step increment</button>
                                    <button v-if="row.current" type="button" class="text-warning hover:underline" @click="open('vacate', row)">Vacate</button>
                                    <button type="button" class="text-primary hover:underline" @click="open('correct', row)">Correct</button>
                                    <button type="button" class="text-danger hover:underline" @click="remove(row)">Remove</button>
                                </div>
                                <div v-else-if="row.can_correct" class="flex flex-wrap items-center justify-end gap-3 whitespace-nowrap">
                                    <span class="text-xs text-text-muted">Your own</span>
                                    <button type="button" class="text-primary hover:underline" @click="open('correct', row)">Correct</button>
                                </div>
                                <span v-else class="text-xs text-text-muted">Your own</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </Card>
    </div>

    <Modal :open="mode !== null" :title="titles[mode] ?? ''" :tone="mode === 'abolish' ? 'danger' : mode === 'appoint' ? 'success' : 'primary'" size="lg" @close="close">
        <form id="plantilla-action-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
            <template v-if="mode === 'appoint'">
                <div class="sm:col-span-2">
                    <label for="appoint-employee" class="block text-sm font-medium text-text">Employee <span class="text-danger" aria-hidden="true">*</span></label>
                    <Select id="appoint-employee" v-model="form.employee_id" :class="field" :options="appointForm.employees" placeholder="— Select —" required :invalid="!!form.errors.employee_id" />
                    <p class="mt-1 text-xs text-text-muted">
                        <template v-if="appointForm.matches">
                            {{ appointForm.matches === 1 ? '1 employee has' : `${appointForm.matches} employees have` }} “{{ item.position_title }}” as their position; they're listed first.
                        </template>
                        <template v-else>No employee has “{{ item.position_title }}” as their position yet; everyone's current title is shown.</template>
                    </p>
                    <p class="mt-1 text-xs text-text-muted">If they hold another item now (a promotion or transfer), that period ends the day before. Their position title and employment status follow this item.</p>
                    <InputError :message="form.errors.employee_id" />
                </div>
            </template>

            <p v-if="mode === 'correct' && target && !target.can_manage" class="rounded-md border border-warning-border bg-warning-bg px-3 py-2 text-sm text-text sm:col-span-2">
                This is your own record. Correct it only where the data is wrong; the change is logged as a correction of your own record.
            </p>
            <template v-if="mode === 'appoint' || mode === 'correct'">
                <div>
                    <label for="action-status" class="block text-sm font-medium text-text">Status <span class="text-danger" aria-hidden="true">*</span></label>
                    <Select id="action-status" v-model="form.status" :class="field" :options="appointmentOptions.statusOptions" required :invalid="!!form.errors.status" />
                    <InputError :message="form.errors.status" />
                </div>
                <div>
                    <label for="action-nature" class="block text-sm font-medium text-text">Nature <span class="text-danger" aria-hidden="true">*</span></label>
                    <Select
                        id="action-nature"
                        v-model="form.nature"
                        :class="field"
                        :options="mode === 'correct' ? appointmentOptions.allNatureOptions : appointmentOptions.natureOptions"
                        required
                        :invalid="!!form.errors.nature"
                    />
                    <InputError :message="form.errors.nature" />
                </div>
            </template>

            <template v-if="mode === 'appoint' || mode === 'step' || mode === 'correct'">
                <div>
                    <label for="action-step" class="block text-sm font-medium text-text">{{ mode === 'step' ? 'New step' : 'Step' }} <span class="text-danger" aria-hidden="true">*</span></label>
                    <Select id="action-step" v-model="form.step" :class="field" :options="steps" required :invalid="!!form.errors.step" />
                    <InputError :message="form.errors.step" />
                </div>
                <div>
                    <TextInput id="action-from" v-model="form.date_from" type="date" :label="mode === 'correct' ? 'From' : 'Effective date'" required :invalid="!!form.errors.date_from" />
                    <InputError :message="form.errors.date_from" />
                </div>
                <div class="sm:col-span-2">
                    <TextInput id="action-salary-override" v-model="form.salary_override" type="number" step="0.01" min="0" label="Monthly salary override (optional)" :invalid="!!form.errors.salary_override" />
                    <p class="mt-1 text-xs text-text-muted">Leave blank to follow the salary schedule. Set it when the schedule has no amount for this grade and step, or an agreed rate applies. Payroll and the Service Record use it.</p>
                    <InputError :message="form.errors.salary_override" />
                </div>
            </template>

            <template v-if="mode === 'correct' || mode === 'vacate'">
                <div>
                    <TextInput id="action-to" v-model="form.date_to" type="date" label="Last day" :required="mode === 'vacate'" :invalid="!!form.errors.date_to" />
                    <InputError :message="form.errors.date_to" />
                </div>
                <div>
                    <TextInput id="action-reason" v-model="form.end_reason" label="Reason (e.g. Retirement, Resignation)" :required="mode === 'vacate'" :invalid="!!form.errors.end_reason" />
                    <InputError :message="form.errors.end_reason" />
                </div>
            </template>

            <template v-if="mode === 'abolish'">
                <div>
                    <TextInput id="abolish-on" v-model="form.abolished_on" type="date" label="Date abolished" required :invalid="!!form.errors.abolished_on" />
                    <InputError :message="form.errors.abolished_on" />
                </div>
                <div>
                    <TextInput id="abolish-authority" v-model="form.abolition_authority" label="Authority" :invalid="!!form.errors.abolition_authority" />
                    <InputError :message="form.errors.abolition_authority" />
                </div>
            </template>

            <div v-if="mode && mode !== 'vacate' && mode !== 'abolish'" class="sm:col-span-2">
                <label for="action-remarks" class="block text-sm font-medium text-text">Remarks</label>
                <Textarea id="action-remarks" v-model="form.remarks" class="mt-1" :invalid="!!form.errors.remarks" />
                <InputError :message="form.errors.remarks" />
            </div>
        </form>

        <template #footer>
            <Button variant="secondary" @click="close">Cancel</Button>
            <Button type="submit" form="plantilla-action-form" :variant="mode === 'abolish' ? 'danger' : 'primary'" :loading="form.processing">
                {{ { appoint: 'Appoint', step: 'Record increment', vacate: 'Vacate', correct: 'Save', abolish: 'Abolish' }[mode] ?? 'Save' }}
            </Button>
        </template>
    </Modal>

    <RecordModal
        v-if="canManage"
        :open="editOpen"
        noun="Plantilla Item"
        :record="{ ...item, name: item.item_number || item.position_title }"
        :fields="plantillaItemFields(itemForm)"
        :defaults="plantillaItemDefaults"
        :nullable="plantillaItemNullable"
        store-url="/admin/hr/plantilla"
        :update-url="(record) => `/admin/hr/plantilla/${record.id}`"
        @close="editOpen = false"
    />
</template>
