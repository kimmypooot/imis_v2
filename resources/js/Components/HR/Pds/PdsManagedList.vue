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
        <div v-else class="table-card mt-4">
            <table class="data-table">
                <caption class="sr-only">{{ section.label }}</caption>
                <thead>
                    <tr>
                        <th scope="col" class="font-semibold">Title</th>
                        <th scope="col" class="font-semibold">Dates</th>
                        <th scope="col" class="text-right font-semibold">Hours</th>
                        <th scope="col" class="font-semibold">Type</th>
                        <th scope="col" class="font-semibold">Conducted/sponsored by</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in rows" :key="row.id">
                        <td class="text-text">{{ row.title }}</td>
                        <td class="whitespace-nowrap text-text-muted">
                            {{ formatCalendarDate(row.date_from) }}<template v-if="row.date_to && row.date_to !== row.date_from"> – {{ formatCalendarDate(row.date_to) }}</template>
                        </td>
                        <td class="text-right text-text-muted tabular-nums">{{ row.hours }}</td>
                        <td class="text-text-muted">{{ row.ld_type || '—' }}</td>
                        <td class="text-text-muted">{{ row.sponsor || '—' }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
