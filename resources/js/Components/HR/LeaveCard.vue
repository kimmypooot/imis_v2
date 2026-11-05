<script setup>
import { computed, ref } from 'vue';
import Card from '../UI/Card.vue';
import StatusBadge from '../UI/StatusBadge.vue';
import { formatCalendarDate } from '../../Utils/datetime';

/**
 * HR-6: a leave card — today's balances, then the year's ledger, oldest
 * first, each line with the running balance of its type (balances brought
 * forward from the year before). Shared by HR's page and "My Leave". With
 * `canReverse`, lines offer a reverse action (HR).
 */
const props = defineProps({
    year: { type: Number, required: true },
    years: { type: Array, required: true },
    balances: { type: Array, required: true },
    card: { type: Object, required: true },
    // The year laid out like the paper card (LeaveCredits::monthlyCard).
    monthly: { type: Object, default: null },
    canReverse: { type: Boolean, default: false },
});

const emit = defineEmits(['year', 'reverse']);

const days = (value) => Number(value).toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const signed = (value) => (value > 0 ? '+' : '') + days(value);

// Filter the ledger to one type (VL and SL first, as the paper card does).
const filter = ref('');
const lines = computed(() => (filter.value ? props.card.lines.filter((line) => line.type_id === Number(filter.value)) : props.card.lines));
const forwardOf = (typeId) => props.card.forward[typeId] ?? 0;

// Monthly (the paper card's layout) by default; the ledger is the audit trail.
const hasMonthly = computed(() => (props.monthly?.months?.length ?? 0) > 0);
const view = ref('monthly');
const showing = computed(() => (hasMonthly.value ? view.value : 'ledger'));
const views = [
    { value: 'monthly', label: 'Monthly card' },
    { value: 'ledger', label: 'Ledger' },
];
const figure = (value) => (value === null || value === undefined ? '—' : days(value));
// A month with nothing used shows a dash, so the real deductions stand out.
const usedFigure = (value) => (!value ? '—' : days(value));
const hasSystemMonths = computed(() => props.monthly?.months?.some((month) => month.source === 'ledger') ?? false);
const total = (key, field) => props.monthly.months.reduce((sum, month) => sum + (month[key]?.[field] ?? 0), 0);
</script>

<template>
    <div class="space-y-6">
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div v-for="balance in balances" :key="balance.type_id" class="relative overflow-hidden rounded-lg border border-border bg-surface p-4 transition-colors hover:border-primary/40">
                <span class="absolute inset-x-0 top-0 h-0.5" :class="balance.balance > 0 ? 'bg-primary' : 'bg-border-strong'" aria-hidden="true" />
                <dt class="text-xs font-medium text-text-muted" :title="balance.name">{{ balance.code }} · {{ balance.name }}</dt>
                <dd class="mt-1 flex items-baseline gap-1.5 text-2xl font-bold tabular-nums text-text">{{ days(balance.balance) }}<span class="text-xs font-normal text-text-muted">days</span></dd>
            </div>
        </dl>

        <Card
            :title="`Leave card — ${year}`"
            icon="clipboard"
            :description="showing === 'monthly' ? 'Month by month, as on the paper leave card: Vacation and Sick Leave earned, used and left.' : 'Every credit and deduction, with the running balance of its leave type.'"
        >
            <template #actions>
                <div v-if="hasMonthly" class="inline-flex rounded-md border border-border-strong p-0.5 text-sm" role="group" aria-label="Card view">
                    <button
                        v-for="option in views"
                        :key="option.value"
                        type="button"
                        class="rounded px-3 py-1.5 font-medium transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        :class="showing === option.value ? 'bg-primary text-white' : 'text-text-muted hover:text-text'"
                        :aria-pressed="showing === option.value"
                        @click="view = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
                <label v-if="showing === 'ledger'" class="sr-only" for="leave-card-type">Leave type</label>
                <select v-if="showing === 'ledger'" id="leave-card-type" v-model="filter" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring">
                    <option value="">All leave types</option>
                    <option v-for="balance in balances" :key="balance.type_id" :value="balance.type_id">{{ balance.code }} · {{ balance.name }}</option>
                </select>
                <label class="sr-only" for="leave-card-year">Year</label>
                <select
                    id="leave-card-year"
                    :value="year"
                    class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                    @change="emit('year', $event.target.value)"
                >
                    <option v-for="option in years" :key="option" :value="option">{{ option }}</option>
                </select>
            </template>

            <template v-if="showing === 'monthly'">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">Monthly leave card for {{ year }}</caption>
                        <thead class="text-xs text-text-muted">
                            <tr class="border-b border-border">
                                <th scope="col" rowspan="2" class="py-2 pr-4 text-left font-medium">Period</th>
                                <th scope="col" rowspan="2" class="py-2 pr-4 text-left font-medium">Particulars</th>
                                <th scope="colgroup" colspan="3" class="border-l border-border px-3 py-2 text-center font-medium">Vacation leave</th>
                                <th scope="colgroup" colspan="3" class="border-l border-border px-3 py-2 text-center font-medium">Sick leave</th>
                                <th scope="col" rowspan="2" class="border-l border-border py-2 pl-3 text-left font-medium" title="Listed where HR noted them on the card, which is not always the month the leave was taken">Applications noted</th>
                            </tr>
                            <tr class="border-b border-border">
                                <template v-for="group in ['vl', 'sl']" :key="group">
                                    <th scope="col" class="border-l border-border px-3 py-1.5 text-right font-medium">Earned</th>
                                    <th scope="col" class="px-3 py-1.5 text-right font-medium" title="Absences, undertime and leave taken, with pay">Used</th>
                                    <th scope="col" class="px-3 py-1.5 text-right font-medium">Balance</th>
                                </template>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border">
                            <tr v-if="monthly.forward">
                                <td class="py-2 pr-4 align-top font-medium" colspan="2">Balance brought forward from {{ year - 1 }}</td>
                                <td class="border-l border-border px-3 py-2" colspan="2" />
                                <td class="px-3 py-2 text-right align-top font-medium tabular-nums">{{ figure(monthly.forward.vl) }}</td>
                                <td class="border-l border-border px-3 py-2" colspan="2" />
                                <td class="px-3 py-2 text-right align-top font-medium tabular-nums">{{ figure(monthly.forward.sl) }}</td>
                                <td class="border-l border-border py-2 pl-3 text-xs text-text-muted">{{ monthly.forward.source === 'card' ? 'From the leave card' : 'From the system' }}</td>
                            </tr>
                            <tr v-for="month in monthly.months" :key="month.month">
                                <td class="whitespace-nowrap py-2 pr-4 align-top">
                                    {{ month.label }}
                                    <span v-if="month.source === 'ledger'" class="ml-1 text-xs text-text-muted">· system</span>
                                </td>
                                <td class="py-2 pr-4 align-top text-text-muted">{{ month.particulars }}</td>
                                <template v-for="group in ['vl', 'sl']" :key="group">
                                    <td class="border-l border-border px-3 py-2 text-right align-top tabular-nums">{{ figure(month[group].earned) }}</td>
                                    <td class="px-3 py-2 text-right align-top tabular-nums" :class="month[group].used > 0 ? 'text-danger' : 'text-text-muted'">{{ usedFigure(month[group].used) }}</td>
                                    <td class="px-3 py-2 text-right align-top font-medium tabular-nums">
                                        {{ figure(month[group].balance) }}
                                        <div v-if="month[group].adjusted" class="text-xs font-normal text-text-muted">{{ signed(month[group].adjusted) }} adjusted</div>
                                    </td>
                                </template>
                                <td class="border-l border-border py-2 pl-3 align-top text-xs text-text-muted">{{ month.applications }}</td>
                            </tr>
                        </tbody>
                        <tfoot>
                            <tr class="border-t border-border-strong font-medium">
                                <td class="py-2 pr-4" colspan="2">{{ year }} totals</td>
                                <template v-for="group in ['vl', 'sl']" :key="group">
                                    <td class="border-l border-border px-3 py-2 text-right tabular-nums">{{ days(total(group, 'earned')) }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums">{{ days(total(group, 'used')) }}</td>
                                    <td class="px-3 py-2" />
                                </template>
                                <td class="border-l border-border" />
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p v-if="hasSystemMonths" class="mt-3 rounded-md border border-warning-border bg-warning-bg px-3 py-2 text-sm text-warning" role="note">
                    Months marked "system" do not yet include leave that was filed on paper after the card's last month, or undertime. If you took leave
                    in those months, ask the HR office to add it; until then your balance there may read higher than it really is.
                </p>
                <p class="mt-3 text-xs text-text-muted">
                    Months from the paper leave card are shown as HR recorded them. Months marked "system" are worked out from the credit ledger
                    (accruals as earned, leave taken as used). Balances always come from the ledger. Used includes absences and undertime with pay.
                    Applications are listed where HR noted them on the card, which is not always the month the leave was taken.
                </p>
            </template>

            <template v-else>
            <p class="mb-3 text-sm text-text-muted">
                Brought forward from {{ year - 1 }}:
                <template v-for="(balance, index) in balances" :key="balance.type_id">
                    <span class="font-medium text-text">{{ balance.code }} {{ days(forwardOf(balance.type_id)) }}</span><template v-if="index < balances.length - 1"> · </template>
                </template>
            </p>

            <p v-if="lines.length === 0" class="text-sm text-text-muted">No entries in {{ year }}.</p>
            <div v-else class="overflow-x-auto">
                <table class="w-full text-sm">
                    <caption class="sr-only">Leave card for {{ year }}</caption>
                    <thead class="text-left text-xs text-text-muted">
                        <tr>
                            <th scope="col" class="py-2 pr-4 font-medium">Date</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Type</th>
                            <th scope="col" class="py-2 pr-4 font-medium">Particulars</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">Days</th>
                            <th scope="col" class="py-2 pr-4 text-right font-medium">Balance</th>
                            <th v-if="canReverse" scope="col" class="py-2 font-medium"><span class="sr-only">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="line in lines" :key="line.id" :class="line.reversed ? 'text-text-muted line-through' : ''">
                            <td class="whitespace-nowrap py-2 pr-4 align-top">{{ formatCalendarDate(line.date) }}</td>
                            <td class="py-2 pr-4 align-top"><span class="font-medium">{{ line.code }}</span></td>
                            <td class="py-2 pr-4 align-top">
                                {{ line.particulars }}
                                <div class="text-xs text-text-muted no-underline">
                                    {{ line.kind_label }}<template v-if="line.recorded_by"> · {{ line.recorded_by }}</template>
                                </div>
                            </td>
                            <td class="whitespace-nowrap py-2 pr-4 text-right align-top tabular-nums" :class="line.amount < 0 ? 'text-danger' : 'text-success'">{{ signed(line.amount) }}</td>
                            <td class="whitespace-nowrap py-2 pr-4 text-right align-top tabular-nums">{{ days(line.balance) }}</td>
                            <td v-if="canReverse" class="py-2 text-right align-top">
                                <StatusBadge v-if="line.reversed" status="logged_out" label="Reversed" />
                                <button v-else-if="!line.reverses_id" type="button" class="text-sm text-danger hover:underline" @click="emit('reverse', line)">
                                    Reverse<span class="sr-only"> the {{ line.code }} entry of {{ formatCalendarDate(line.date) }}</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            </template>
        </Card>
    </div>
</template>
