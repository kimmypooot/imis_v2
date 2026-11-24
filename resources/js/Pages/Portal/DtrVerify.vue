<script setup>
import { computed, ref } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import { formatCalendarDate } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * DTR: the days your people entered themselves on their Daily Time Record
 * (CS Form 48 asks the supervisor or office head to verify the office hours).
 * Verify the ones you can vouch for; leave the rest, and tell the employee.
 */
const props = defineProps({
    entries: { type: Array, required: true },
});

const picked = ref([]);
const form = useForm({ ids: [] });
const people = computed(() => {
    const groups = {};
    for (const entry of props.entries) (groups[entry.employee] ??= []).push(entry);
    return Object.entries(groups).map(([name, rows]) => ({ name, rows }));
});
const clock = (value) => {
    if (!value) return '—';
    const [h, m] = value.split(':').map(Number);
    return `${h % 12 || 12}:${String(m).padStart(2, '0')} ${h < 12 ? 'AM' : 'PM'}`;
};
const allIds = computed(() => props.entries.map((entry) => entry.id));
const toggleAll = (event) => (picked.value = event.target.checked ? [...allIds.value] : []);
const verify = (ids) => {
    form.ids = ids;
    form.post('/dtr/entries', { preserveScroll: true, onSuccess: () => (picked.value = []) });
};
</script>

<template>
    <Head title="DTR entries to verify" />

    <PageHeader title="DTR entries to verify" description="Times your people entered on their own DTR, for a day the fingerprint reader missed." :breadcrumbs="[{ label: 'Home', href: '/' }, { label: 'DTR entries to verify' }]" />

    <Card v-if="!entries.length">
        <EmptyState icon="check" title="Nothing to verify" description="When someone you supervise or head enters times on their DTR, they appear here and you are told under the bell." />
    </Card>

    <div v-else class="space-y-6">
        <Card :title="`${entries.length} ${entries.length === 1 ? 'day' : 'days'} waiting`" icon="clock">
            <template #actions>
                <label class="flex items-center gap-2 text-sm text-text">
                    <input type="checkbox" class="h-4 w-4 rounded-sm border-border-strong text-link" :checked="picked.length === allIds.length" aria-label="Select all days" @change="toggleAll" />
                    Select all
                </label>
                <Button icon="check" :disabled="!picked.length" :loading="form.processing" @click="verify(picked)">Verify {{ picked.length || '' }} selected</Button>
            </template>
            <p class="mb-4 text-sm text-text-muted">Verify only the days you can vouch for: you saw them at work, or you know of the failed reader, the event or the travel. The employee already certified the entry as true and correct.</p>
            <div v-for="person in people" :key="person.name" class="mb-4 last:mb-0">
                <h3 class="mb-1 text-sm font-semibold text-text">{{ person.name }}</h3>
                <ul class="divide-y divide-border rounded-md border border-border text-sm">
                    <li v-for="entry in person.rows" :key="entry.id" class="flex items-start gap-3 px-3 py-2">
                        <input v-model="picked" type="checkbox" :value="entry.id" class="mt-1 h-4 w-4 rounded-sm border-border-strong text-link" :aria-label="`Select ${person.name}, ${formatCalendarDate(entry.date)}`" />
                        <div class="min-w-0 flex-1">
                            <span class="font-medium">{{ formatCalendarDate(entry.date) }}</span>
                            <span class="ml-2 tabular-nums">{{ clock(entry.time_in) }} to {{ clock(entry.time_out) }}</span>
                            <span class="block text-text-muted">{{ entry.remark }}</span>
                        </div>
                        <Button variant="secondary" :loading="form.processing" @click="verify([entry.id])">Verify</Button>
                    </li>
                </ul>
            </div>
        </Card>
    </div>
</template>
