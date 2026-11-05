<script setup>
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import StatusBadge from '../../../../Components/UI/StatusBadge.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import Modal from '../../../../Components/Feedback/Modal.vue';
import Select from '../../../../Components/Forms/Select.vue';
import TextInput from '../../../../Components/Forms/TextInput.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import LeaveCard from '../../../../Components/HR/LeaveCard.vue';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * HR-6a: one person's leave card for HR — balances and the year's ledger,
 * opening balances / adjustments, reversals, and their leave settings
 * (immediate supervisor for HR-6b's approvals, solo parent). Never on
 * your own card.
 */
const props = defineProps({
    employee: { type: Object, required: true },
    year: { type: Number, required: true },
    years: { type: Array, required: true },
    balances: { type: Array, required: true },
    card: { type: Object, required: true },
    monthly: { type: Object, default: null },
    settings: { type: Object, required: true },
    canManage: { type: Boolean, required: true },
    manageOptions: { type: Object, default: null },
});

const base = computed(() => `/admin/hr/employees/${props.employee.id}/leave`);
const changeYear = (value) => router.get(base.value, { year: value }, { preserveScroll: true });
const typeOptions = computed(() => props.balances.map((balance) => ({ value: balance.type_id, label: `${balance.code} · ${balance.name}` })));

// ── Opening balance / adjustment ─────────────────────────────────────
const entryOpen = ref(false);
const entry = useForm({ leave_type_id: '', kind: 'opening', entry_date: '', amount: '', particulars: '' });
const openEntry = () => {
    entry.reset();
    entry.clearErrors();
    entry.entry_date = new Date().toISOString().slice(0, 10);
    entryOpen.value = true;
};
const submitEntry = () => entry.post(`${base.value}/entries`, { preserveScroll: true, onSuccess: () => (entryOpen.value = false) });

// ── Reversal ──────────────────────────────────────────────────────────
const reversing = ref(null);
const reversal = useForm({ reason: '' });
const openReverse = (line) => {
    reversal.reset();
    reversal.clearErrors();
    reversing.value = line;
};
const submitReverse = () => reversal.post(`${base.value}/entries/${reversing.value.id}/reverse`, { preserveScroll: true, onSuccess: () => (reversing.value = null) });

// ── Leave settings ────────────────────────────────────────────────────
const settingsOpen = ref(false);
const settingsForm = useForm({ immediate_supervisor_id: '', is_solo_parent: false, solo_parent_id_number: '', solo_parent_id_expiry: '' });
const openSettings = () => {
    settingsForm.defaults({
        immediate_supervisor_id: props.settings.immediate_supervisor_id ?? '',
        is_solo_parent: props.settings.is_solo_parent,
        solo_parent_id_number: props.settings.solo_parent_id_number ?? '',
        solo_parent_id_expiry: props.settings.solo_parent_id_expiry ?? '',
    });
    settingsForm.reset();
    settingsForm.clearErrors();
    settingsOpen.value = true;
};
const submitSettings = () =>
    settingsForm
        .transform((data) => Object.fromEntries(Object.entries(data).map(([key, value]) => [key, value === '' ? null : value])))
        .put(`${base.value}/settings`, { preserveScroll: true, onSuccess: () => (settingsOpen.value = false) });

const field = 'mt-1 block w-full';
</script>

<template>
    <Head :title="`Leave — ${employee.name}`" />

    <PageHeader
        :title="`Leave — ${employee.name}`"
        :breadcrumbs="[
            { label: 'Human Resources', href: '/admin/hr' },
            { label: 'Leave Credits', href: '/admin/hr/leave' },
            { label: employee.name, href: `/admin/hr/employees/${employee.id}` },
            { label: 'Leave' },
        ]"
    >
        <template v-if="canManage" #actions>
            <Button variant="success" @click="openEntry"><Icon name="plus" size="sm" />Opening balance / adjustment</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <Card title="Leave settings" icon="cog">
            <template v-if="canManage" #actions>
                <Button variant="secondary" @click="openSettings"><Icon name="pencil" size="sm" />Edit</Button>
            </template>
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-text-muted">Immediate supervisor</dt>
                    <dd class="mt-0.5 font-medium text-text">
                        {{ settings.supervisor || 'Not set' }}
                        <p v-if="settings.supervisor_position" class="text-xs font-normal text-text-muted">
                            {{ settings.supervisor_position }} · {{ settings.supervisor_source === 'set' ? 'set by HR' : 'by position and office' }}
                        </p>
                        <p v-if="!settings.supervisor" class="text-xs font-normal text-warning">Needed before they can file leave (supervisor's recommendation).</p>
                    </dd>
                </div>
                <div>
                    <dt class="text-text-muted">Employment status</dt>
                    <dd class="mt-0.5 font-medium text-text">{{ employee.status }}</dd>
                </div>
                <div>
                    <dt class="text-text-muted">Solo parent</dt>
                    <dd class="mt-0.5 text-text">
                        <template v-if="settings.is_solo_parent">
                            <StatusBadge :status="settings.solo_parent_valid ? 'active' : 'expired'" :label="settings.solo_parent_valid ? 'Valid ID' : 'ID Expired'" />
                            <span v-if="settings.solo_parent_id_expiry" class="ml-2 text-xs text-text-muted">until {{ formatCalendarDate(settings.solo_parent_id_expiry) }}</span>
                        </template>
                        <template v-else>No</template>
                    </dd>
                </div>
            </dl>
        </Card>

        <LeaveCard :year="year" :years="years" :balances="balances" :card="card" :monthly="monthly" :can-reverse="canManage" @year="changeYear" @reverse="openReverse" />
    </div>

    <Modal :open="entryOpen" title="Opening balance or adjustment" icon="plus" tone="success" size="lg" @close="entryOpen = false">
        <form id="leave-entry-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitEntry">
            <div>
                <label for="entry-type" class="block text-sm font-medium text-text">Leave type <span class="text-danger" aria-hidden="true">*</span></label>
                <Select id="entry-type" v-model="entry.leave_type_id" :class="field" :options="typeOptions" placeholder="— Select —" required :invalid="!!entry.errors.leave_type_id" />
                <InputError :message="entry.errors.leave_type_id" />
            </div>
            <div>
                <label for="entry-kind" class="block text-sm font-medium text-text">Kind <span class="text-danger" aria-hidden="true">*</span></label>
                <Select id="entry-kind" v-model="entry.kind" :class="field" :options="manageOptions?.kindOptions ?? []" required :invalid="!!entry.errors.kind" />
                <InputError :message="entry.errors.kind" />
            </div>
            <div>
                <TextInput id="entry-date" v-model="entry.entry_date" type="date" label="As of" required :invalid="!!entry.errors.entry_date" />
                <InputError :message="entry.errors.entry_date" />
            </div>
            <div>
                <label for="entry-amount" class="block text-sm font-medium text-text">Days <span class="text-danger" aria-hidden="true">*</span></label>
                <input
                    id="entry-amount"
                    v-model="entry.amount"
                    type="number"
                    step="0.001"
                    required
                    :aria-invalid="!!entry.errors.amount"
                    class="mt-1 block w-full rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                />
                <p class="mt-1 text-xs text-text-muted">An adjustment can be negative (a deduction).</p>
                <InputError :message="entry.errors.amount" />
            </div>
            <div class="sm:col-span-2">
                <TextInput id="entry-particulars" v-model="entry.particulars" label="Particulars (e.g. Balance carried over from the paper leave card)" required :invalid="!!entry.errors.particulars" />
                <InputError :message="entry.errors.particulars" />
            </div>
        </form>
        <template #footer>
            <Button variant="secondary" @click="entryOpen = false">Cancel</Button>
            <Button type="submit" form="leave-entry-form" variant="success" :loading="entry.processing">Post</Button>
        </template>
    </Modal>

    <Modal :open="reversing !== null" title="Reverse this entry?" tone="danger" @close="reversing = null">
        <form id="leave-reverse-form" class="space-y-3" @submit.prevent="submitReverse">
            <p v-if="reversing" class="text-sm text-text">
                {{ reversing.code }} {{ reversing.amount > 0 ? '+' : '' }}{{ reversing.amount }} on {{ formatCalendarDate(reversing.date) }} — {{ reversing.particulars }}.
                An equal and opposite entry is posted today; the original stays on the card.
            </p>
            <TextInput id="reverse-reason" v-model="reversal.reason" label="Reason" required :invalid="!!reversal.errors.reason" />
            <InputError :message="reversal.errors.reason" />
        </form>
        <template #footer>
            <Button variant="secondary" @click="reversing = null">Cancel</Button>
            <Button type="submit" form="leave-reverse-form" variant="danger" :loading="reversal.processing">Reverse</Button>
        </template>
    </Modal>

    <Modal :open="settingsOpen" title="Leave settings" icon="cog" tone="primary" size="lg" @close="settingsOpen = false">
        <form id="leave-settings-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitSettings">
            <div class="sm:col-span-2">
                <label for="settings-supervisor" class="block text-sm font-medium text-text">Immediate supervisor</label>
                <Select id="settings-supervisor" v-model="settingsForm.immediate_supervisor_id" :class="field" :options="manageOptions?.supervisorOptions ?? []" :placeholder="settings.supervisor_by_rule ? `— By position: ${settings.supervisor_by_rule} —` : '— Not set —'" :invalid="!!settingsForm.errors.immediate_supervisor_id" />
                <p class="mt-1 text-xs text-text-muted">
                    Recommends their leave applications (CS Form No. 6, Part B). Leave it empty to follow position and office: the Chief Human Resource Specialist for Regional Support Unit staff, the Director II for Field Office staff, the Director IV for ORD staff, the Director III, the Chief Human Resource Specialists and the Attorney VI, and the CSC Chairperson for the Director IV.
                </p>
                <InputError :message="settingsForm.errors.immediate_supervisor_id" />
            </div>
            <div class="sm:col-span-2">
                <label class="inline-flex items-center gap-2 text-sm text-text">
                    <input v-model="settingsForm.is_solo_parent" type="checkbox" class="h-4 w-4 rounded border-border-strong text-primary focus:ring-2 focus:ring-focus-ring" />
                    Solo parent (for Solo Parent Leave)
                </label>
            </div>
            <template v-if="settingsForm.is_solo_parent">
                <div>
                    <TextInput id="settings-solo-id" v-model="settingsForm.solo_parent_id_number" label="Solo Parent ID number" required :invalid="!!settingsForm.errors.solo_parent_id_number" />
                    <InputError :message="settingsForm.errors.solo_parent_id_number" />
                </div>
                <div>
                    <TextInput id="settings-solo-expiry" v-model="settingsForm.solo_parent_id_expiry" type="date" label="ID valid until" :invalid="!!settingsForm.errors.solo_parent_id_expiry" />
                    <InputError :message="settingsForm.errors.solo_parent_id_expiry" />
                </div>
            </template>
        </form>
        <template #footer>
            <Button variant="secondary" @click="settingsOpen = false">Cancel</Button>
            <Button type="submit" form="leave-settings-form" :loading="settingsForm.processing">Save</Button>
        </template>
    </Modal>
</template>
