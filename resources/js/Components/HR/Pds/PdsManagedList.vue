<script setup>
import { useId } from 'vue';
import { Link } from '@inertiajs/vue3';
import { formatCalendarDate } from '../../../Utils/datetime';

/**
 * A PDS section kept in another module (Section VI comes from Learning &
 * Development): shown read-only, as it will print. `manageLink` shows the
 * way to where it's edited (only on the employee's own PDS).
 */
defineProps({
    section: { type: Object, required: true },
    rows: { type: Array, required: true },
    manageLink: { type: Boolean, default: false },
});

const id = useId();
</script>

<template>
    <section :aria-labelledby="`${id}-title`" class="rounded-lg border border-border p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 :id="`${id}-title`" class="text-sm font-semibold text-text">{{ section.label }}</h3>
                <p class="mt-0.5 text-xs text-text-muted">
                    Kept in {{ section.managed_by.label }}: your approved and pending trainings appear here automatically.
                </p>
            </div>
            <Link v-if="manageLink" :href="section.managed_by.url" class="text-sm font-medium text-primary hover:underline">
                Manage in {{ section.managed_by.label }} →
            </Link>
        </div>

        <p v-if="rows.length === 0" class="mt-4 rounded-md bg-surface-muted px-3 py-4 text-center text-sm text-text-muted">None yet.</p>
        <div v-else class="mt-4 overflow-x-auto">
            <table class="w-full text-left text-sm">
                <caption class="sr-only">{{ section.label }}</caption>
                <thead class="border-b border-border text-xs text-text-muted uppercase">
                    <tr>
                        <th scope="col" class="py-2 pr-3 font-semibold">Title</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Dates</th>
                        <th scope="col" class="py-2 pr-3 text-right font-semibold">Hours</th>
                        <th scope="col" class="py-2 pr-3 font-semibold">Type</th>
                        <th scope="col" class="py-2 font-semibold">Conducted/sponsored by</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="row in rows" :key="row.id">
                        <td class="py-2 pr-3 text-text">{{ row.title }}</td>
                        <td class="py-2 pr-3 whitespace-nowrap text-text-muted">
                            {{ formatCalendarDate(row.date_from) }}<template v-if="row.date_to && row.date_to !== row.date_from"> – {{ formatCalendarDate(row.date_to) }}</template>
                        </td>
                        <td class="py-2 pr-3 text-right text-text-muted tabular-nums">{{ row.hours }}</td>
                        <td class="py-2 pr-3 text-text-muted">{{ row.ld_type || '—' }}</td>
                        <td class="py-2 text-text-muted">{{ row.sponsor || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
