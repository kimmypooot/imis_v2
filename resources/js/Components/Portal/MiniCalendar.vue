<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';

/**
 * A small month calendar for the dashboard's Calendar of Activities data
 * (UpcomingActivities on the server): a dot on days with activities,
 * day-off holidays in red, today highlighted, and each day opening the full
 * calendar on that day. ‹ › reload only the `activities` prop with a
 * `month`, so the rest of the page stays put.
 */
const props = defineProps({
    // { month: 'YYYY-MM', current, days: ['YYYY-MM-DD'], holidays: [{ date, name }] }
    month: { type: Object, required: true },
    // 'YYYY-MM-DD'
    today: { type: String, required: true },
});

const pad = (n) => String(n).padStart(2, '0');
const parseDate = (iso) => {
    const [year, month, day] = iso.split('-').map(Number);
    return new Date(year, month - 1, day);
};

// Weeks start on Monday.
const weekdays = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
const first = computed(() => parseDate(`${props.month.month}-01`));
const monthTitle = computed(() => first.value.toLocaleDateString(undefined, { month: 'long', year: 'numeric' }));
const cells = computed(() => {
    const start = first.value;
    const list = Array.from({ length: (start.getDay() + 6) % 7 }, () => null);
    const last = new Date(start.getFullYear(), start.getMonth() + 1, 0).getDate();
    for (let day = 1; day <= last; day++) {
        list.push(`${start.getFullYear()}-${pad(start.getMonth() + 1)}-${pad(day)}`);
    }
    while (list.length % 7) list.push(null);
    return list;
});
const activityDays = computed(() => new Set(props.month.days));
const holidayNames = computed(() => {
    const names = {};
    for (const holiday of props.month.holidays) (names[holiday.date] ??= []).push(holiday.name);
    return names;
});
const dayClass = (iso) => {
    if (iso === props.today) return 'bg-primary font-semibold text-white';
    if (holidayNames.value[iso]) return 'bg-danger-bg font-semibold text-danger-fg';
    return 'text-text hover:bg-surface-muted';
};
const dayLabel = (iso) => {
    const parts = [parseDate(iso).toLocaleDateString(undefined, { dateStyle: 'long' })];
    if (activityDays.value.has(iso)) parts.push('has activities');
    if (holidayNames.value[iso]) parts.push(`holiday: ${holidayNames.value[iso].join(', ')}`);
    return parts.join(', ');
};

const loading = ref(false);
const shift = (by) => {
    const target = new Date(first.value.getFullYear(), first.value.getMonth() + by, 1);
    router.reload({
        only: ['activities'],
        data: { month: `${target.getFullYear()}-${pad(target.getMonth() + 1)}` },
        preserveScroll: true,
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
};
</script>

<template>
    <div :aria-busy="loading">
        <div class="mb-1 flex items-center justify-between gap-2">
            <h3 class="text-sm font-semibold text-text">{{ monthTitle }}</h3>
            <div class="flex gap-1">
                <button type="button" class="rounded-md p-1 text-text-muted hover:bg-surface-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:opacity-50" aria-label="Previous month" :disabled="loading" @click="shift(-1)">
                    <Icon name="chevronLeft" class="h-4 w-4" />
                </button>
                <button type="button" class="rounded-md p-1 text-text-muted hover:bg-surface-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:opacity-50" aria-label="Next month" :disabled="loading" @click="shift(1)">
                    <Icon name="chevronRight" class="h-4 w-4" />
                </button>
            </div>
        </div>

        <div class="grid grid-cols-7 gap-0.5 text-center text-xs transition-opacity" :class="{ 'opacity-60': loading }">
            <span v-for="weekday in weekdays" :key="weekday" class="py-1 text-2xs font-semibold text-text-muted">{{ weekday }}</span>
            <template v-for="(iso, index) in cells" :key="iso ?? `blank-${index}`">
                <span v-if="!iso" aria-hidden="true" />
                <Link
                    v-else
                    :href="`/activities?month=${month.month}&day=${iso}`"
                    :title="holidayNames[iso]?.join(', ')"
                    :aria-label="dayLabel(iso)"
                    class="relative rounded-md pt-1 pb-1.5 tabular-nums focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    :class="dayClass(iso)"
                >
                    {{ Number(iso.slice(8)) }}
                    <span
                        v-if="activityDays.has(iso)"
                        class="absolute bottom-0.5 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full"
                        :class="iso === today ? 'bg-white' : 'bg-primary'"
                        aria-hidden="true"
                    />
                </Link>
            </template>
        </div>

        <div class="mt-2 flex gap-4 text-xs text-text-muted">
            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary" aria-hidden="true" />Activities</span>
            <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-danger" aria-hidden="true" />Holiday</span>
        </div>
    </div>
</template>
