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
    canReverse: { type: Boolean, default: false },
});

const emit = defineEmits(['year', 'reverse']);

const days = (value) => Number(value).toLocaleString(undefined, { minimumFractionDigits: 3, maximumFractionDigits: 3 });
const signed = (value) => (value > 0 ? '+' : '') + days(value);

// Filter the ledger to one type (VL and SL first, as the paper card does).
const filter = ref('');
const lines = computed(() => (filter.value ? props.card.lines.filter((line) => line.type_id === Number(filter.value)) : props.card.lines));
const forwardOf = (typeId) => props.card.forward[typeId] ?? 0;
</script>

<template>
    <div class="space-y-6">
        <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
            <div v-for="balance in balances" :key="balance.type_id" class="rounded-lg bg-surface p-4 shadow-sm ring-1 ring-border/60">
                <dt class="text-xs font-medium text-text-muted" :title="balance.name">{{ balance.code }} · {{ balance.name }}</dt>
                <dd class="mt-1 text-2xl font-bold tabular-nums text-text">{{ days(balance.balance) }}</dd>
            </div>
        </dl>

        <Card :title="`Leave card — ${year}`" icon="clipboard" description="Every credit and deduction, with the running balance of its leave type.">
            <template #actions>
                <label class="sr-only" for="leave-card-type">Leave type</label>
                <select id="leave-card-type" v-model="filter" class="rounded-md border border-border-strong bg-surface px-3 py-2 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring">
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
        </Card>
    </div>
</template>
