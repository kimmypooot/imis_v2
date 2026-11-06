<script setup>
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../../Components/UI/PageHeader.vue';
import Card from '../../../../Components/UI/Card.vue';
import Button from '../../../../Components/UI/Button.vue';
import Icon from '../../../../Components/UI/Icon.vue';
import FileInput from '../../../../Components/Forms/FileInput.vue';
import InputError from '../../../../Components/Forms/InputError.vue';
import { confirm } from '../../../../Composables/useDialog';
import { formatCalendarDate } from '../../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * Syncing IMIS with the leave card workbook HR keeps as its master file.
 * Upload it, see each person's card against the system, choose who to
 * sync, and apply: the card history is refreshed and any difference in
 * the Vacation and Sick Leave balance is posted as one visible entry on
 * the person's leave card (which can be reversed there).
 */
const props = defineProps({
    upload: { type: Object, default: null },
    rows: { type: Array, default: null },
    summary: { type: Object, default: null },
    large: { type: Number, required: true },
});

const form = useForm({ file: null });
const send = () => form.post('/admin/hr/leave/sync', { forceFormData: true, onSuccess: () => form.reset() });

const matched = computed(() => (props.rows ?? []).filter((row) => row.ready));
const unmatched = computed(() => (props.rows ?? []).filter((row) => !row.ready));

// Everyone is selected except large differences, which want a second look first.
const selected = ref([]);
watch(
    () => props.rows,
    () => {
        selected.value = matched.value.filter((row) => row.sync.status !== 'large').map((row) => row.employee_id);
    },
    { immediate: true },
);

const show = ref('attention');
const shown = computed(() => (show.value === 'attention' ? matched.value.filter((row) => row.sync.status !== 'in_sync' || row.warnings.length) : matched.value));
const allShownSelected = computed(() => shown.value.length > 0 && shown.value.every((row) => selected.value.includes(row.employee_id)));
const toggleShown = () => {
    const ids = shown.value.map((row) => row.employee_id);
    selected.value = allShownSelected.value ? selected.value.filter((id) => !ids.includes(id)) : [...new Set([...selected.value, ...ids])];
};

const days = (value) => Number(value).toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const signed = (value) => (Math.abs(value) < 0.0005 ? '—' : (value > 0 ? '+' : '') + days(value));
const statuses = {
    in_sync: { label: 'In sync', class: 'bg-success-bg text-success' },
    adjust: { label: 'Will adjust', class: 'bg-info-bg text-primary' },
    large: { label: 'Check first', class: 'bg-warning-bg text-warning' },
    check: { label: 'History only', class: 'bg-surface-muted text-text-muted' },
};

const applying = ref(false);
const apply = async () => {
    const adjusting = matched.value.filter((row) => selected.value.includes(row.employee_id) && ['adjust', 'large'].includes(row.sync.status)).length;
    const ok = await confirm({
        title: `Sync ${selected.value.length} ${selected.value.length === 1 ? 'person' : 'people'} with the workbook?`,
        text: `Their card history is refreshed, and ${adjusting} ${adjusting === 1 ? 'person has' : 'people have'} a balance difference posted as an entry on their leave card. You can reverse any entry there.`,
        confirmText: 'Sync',
    });
    if (ok) {
        router.post('/admin/hr/leave/sync/apply', { employee_ids: selected.value }, { onStart: () => (applying.value = true), onFinish: () => (applying.value = false) });
    }
};
const discard = () => router.delete('/admin/hr/leave/sync');
</script>

<template>
    <Head title="Sync leave cards" />

    <PageHeader
        title="Sync with the leave card file"
        description="HR's leave card workbook is the master. Upload it to bring each person's card history and Vacation and Sick Leave balance in line."
        :breadcrumbs="[{ label: 'Human Resources', href: '/admin/hr' }, { label: 'Leave Credits', href: '/admin/hr/leave' }, { label: 'Sync with the leave card file' }]"
    />

    <div class="space-y-6">
        <Card title="1. Upload the workbook" icon="download">
            <p class="text-sm text-text">
                Save the workbook from Excel as <strong>.xlsx</strong> and upload it. Nothing is saved until you review the comparison below and apply it. Reading a large workbook can
                take a minute.
            </p>
            <form class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="send">
                <div class="min-w-64 flex-1">
                    <label for="sync-file" class="block text-sm font-medium text-text">Leave card workbook (.xlsx)</label>
                    <FileInput id="sync-file" class="mt-1" accept=".xlsx" required :invalid="!!form.errors.file" @update:model-value="(file) => (form.file = file)" />
                    <InputError :message="form.errors.file" />
                </div>
                <Button type="submit" :loading="form.processing" :disabled="!form.file"><Icon name="checkCircle" size="sm" />Check the file</Button>
            </form>
        </Card>

        <Card v-if="upload && summary" :title="`2. Review: ${upload.name}`" icon="clipboard">
            <template #actions>
                <div class="flex flex-wrap gap-1" role="group" aria-label="Show">
                    <button
                        v-for="option in [{ key: 'attention', label: 'Needs attention' }, { key: 'all', label: 'Everyone' }]"
                        :key="option.key"
                        type="button"
                        class="rounded-md px-3 py-1.5 text-sm font-medium"
                        :class="show === option.key ? 'bg-primary text-white' : 'text-text hover:bg-surface-muted'"
                        :aria-pressed="show === option.key"
                        @click="show = option.key"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </template>

            <p class="text-sm text-text">
                {{ summary.sheets }} sheets: <strong>{{ summary.in_sync }}</strong> already in sync, <strong>{{ summary.adjust }}</strong> with a balance difference,
                <strong v-if="summary.check">{{ summary.check }} to check, </strong>
                <strong :class="summary.errors ? 'text-danger' : ''">{{ summary.errors }}</strong> that could not be matched to an employee.
            </p>
            <p class="mt-1 text-xs text-text-muted">
                Difference = what the card says minus what the system has at the card's last month. Differences over {{ large }} days are not selected until you look at them.
            </p>

            <div class="mt-4 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <caption class="sr-only">Each person's leave card compared with the system</caption>
                    <thead class="border-b border-border text-xs text-text-muted">
                        <tr>
                            <th scope="col" rowspan="2" class="py-2 pr-3 font-medium">
                                <input type="checkbox" :checked="allShownSelected" :aria-label="'Select everyone shown'" @change="toggleShown" />
                            </th>
                            <th scope="col" rowspan="2" class="py-2 pr-4 font-medium">Employee</th>
                            <th scope="col" rowspan="2" class="py-2 pr-4 font-medium">Card to</th>
                            <th scope="colgroup" colspan="3" class="border-l border-border px-3 py-1.5 text-center font-medium">Vacation leave</th>
                            <th scope="colgroup" colspan="3" class="border-l border-border px-3 py-1.5 text-center font-medium">Sick leave</th>
                            <th scope="col" rowspan="2" class="border-l border-border py-2 pl-3 font-medium">Status</th>
                        </tr>
                        <tr>
                            <template v-for="group in ['vl', 'sl']" :key="group">
                                <th scope="col" class="border-l border-border px-3 py-1 text-right font-medium">Card</th>
                                <th scope="col" class="px-3 py-1 text-right font-medium">System</th>
                                <th scope="col" class="px-3 py-1 text-right font-medium">Difference</th>
                            </template>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in shown" :key="row.employee_id">
                            <td class="py-2 pr-3 align-top">
                                <input v-model="selected" type="checkbox" :value="row.employee_id" :aria-label="`Sync ${row.name}`" />
                            </td>
                            <td class="py-2 pr-4 align-top">
                                <a :href="`/admin/hr/employees/${row.employee_id}/leave`" class="font-medium text-primary hover:underline">{{ row.name }}</a>
                                <div class="text-xs text-text-muted">{{ row.months }} card months</div>
                                <ul v-if="row.warnings.length" class="mt-1 space-y-0.5 text-xs text-warning">
                                    <li v-for="warning in row.warnings" :key="warning">{{ warning }}</li>
                                </ul>
                            </td>
                            <td class="whitespace-nowrap py-2 pr-4 align-top">{{ formatCalendarDate(row.sync.date) }}</td>
                            <template v-for="group in ['vl', 'sl']" :key="group">
                                <template v-if="row.sync[group]">
                                    <td class="border-l border-border px-3 py-2 text-right align-top tabular-nums">{{ days(row.sync[group].card) }}</td>
                                    <td class="px-3 py-2 text-right align-top tabular-nums">{{ days(row.sync[group].system) }}</td>
                                    <td class="px-3 py-2 text-right align-top font-medium tabular-nums" :class="Math.abs(row.sync[group].diff) >= 0.0005 ? (Math.abs(row.sync[group].diff) > large ? 'text-warning' : 'text-primary') : 'text-text-muted'">
                                        {{ signed(row.sync[group].diff) }}
                                    </td>
                                </template>
                                <td v-else class="border-l border-border px-3 py-2 text-center text-text-muted" colspan="3">Not on the card</td>
                            </template>
                            <td class="border-l border-border py-2 pl-3 align-top">
                                <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="statuses[row.sync.status].class">{{ statuses[row.sync.status].label }}</span>
                            </td>
                        </tr>
                        <tr v-if="!shown.length">
                            <td colspan="10" class="py-4 text-center text-text-muted">Nothing needs attention: every matched card already agrees with the system.</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <details v-if="unmatched.length" class="mt-4 rounded-md border border-border p-3 text-sm">
                <summary class="cursor-pointer font-medium text-text">{{ unmatched.length }} sheets not matched to an employee (skipped)</summary>
                <ul class="mt-2 space-y-1 text-text-muted">
                    <li v-for="row in unmatched" :key="row.sheet"><span class="font-medium text-text">{{ row.sheet }}:</span> {{ row.errors[0] }}</li>
                </ul>
            </details>

            <div class="mt-4 flex flex-wrap items-center justify-between gap-2">
                <p class="text-sm text-text-muted">{{ selected.length }} selected</p>
                <div class="flex flex-wrap gap-2">
                    <Button variant="secondary" @click="discard">Discard</Button>
                    <Button variant="success" :disabled="!selected.length" :loading="applying" @click="apply">
                        Sync {{ selected.length }} {{ selected.length === 1 ? 'person' : 'people' }}
                    </Button>
                </div>
            </div>
        </Card>
    </div>
</template>
