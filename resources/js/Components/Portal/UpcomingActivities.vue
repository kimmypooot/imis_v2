<script setup>
import { computed, ref } from 'vue';
import { Deferred, Link, router } from '@inertiajs/vue3';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import Skeleton from '@cscro8/ui/Components/UI/Skeleton.vue';
import PortalSection from './PortalSection.vue';

/**
 * The dashboard's Calendar of Activities section (UpcomingActivities on the
 * server, a deferred prop): on the left what's coming up in the region in
 * the next 7 days, holidays inline, each opening the full calendar on its
 * day; on the right a month calendar with dots on activity days, day-off
 * holidays in red and today highlighted. ‹ › reload only this prop with a
 * `month`. Hidden when nothing is coming up and this month has no activities.
 */
const props = defineProps({
    // { today, items: [...], month: { month, current, days, holidays }, count }, or null without access.
    activities: { type: Object, default: null },
});

const pad = (n) => String(n).padStart(2, '0');
const parseDate = (iso) => {
    const [year, month, day] = iso.split('-').map(Number);
    return new Date(year, month - 1, day);
};

const shown = computed(() => {
    const data = props.activities;
    return Boolean(data) && (data.items.length > 0 || data.month.days.length > 0 || !data.month.current);
});

const chip = (item) => {
    const date = parseDate(item.date);
    return {
        top: item.today ? 'Today' : date.toLocaleDateString(undefined, { month: 'short' }),
        day: date.getDate(),
        weekday: date.toLocaleDateString(undefined, { weekday: 'short' }),
    };
};
const chipClass = (item) => {
    if (item.type === 'holiday') return 'bg-danger-bg text-danger-fg';
    return item.today ? 'bg-primary text-white' : 'bg-info-bg text-link';
};
const tagClasses = {
    mine: 'bg-info-bg text-info-fg',
    moved_from: 'bg-warning-bg text-warning-fg',
    cancelled: 'bg-surface-muted text-text-muted line-through',
};
const isCancelled = (item) => item.tags.some((tag) => tag.key === 'cancelled');
const details = (item) => [item.time, item.mode, item.venue].filter(Boolean).join(' · ');
const people = (item) => [item.responsible, item.participants].filter(Boolean).join(' · ');

// The mini calendar: weeks from Monday.
const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const first = computed(() => parseDate(`${props.activities.month.month}-01`));
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
const activityDays = computed(() => new Set(props.activities.month.days));
const holidayNames = computed(() => {
    const names = {};
    for (const holiday of props.activities.month.holidays) (names[holiday.date] ??= []).push(holiday.name);
    return names;
});
const dayClass = (iso) => {
    if (iso === props.activities.today) return 'bg-primary font-semibold text-white';
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
    <Deferred data="activities">
        <template #fallback>
            <div class="grid gap-4 lg:grid-cols-[1.65fr_1fr]" role="status" aria-label="Loading the Calendar of Activities">
                <div class="space-y-4 rounded-lg border border-border bg-surface p-4">
                    <div v-for="n in 3" :key="n" class="flex gap-3">
                        <Skeleton class="h-12 w-12 shrink-0" />
                        <div class="min-w-0 flex-1 space-y-2">
                            <Skeleton class="h-4 w-48 max-w-full" />
                            <Skeleton class="h-3 w-64 max-w-full" />
                        </div>
                    </div>
                </div>
                <div class="rounded-lg border border-border bg-surface p-4">
                    <Skeleton class="h-40 w-full" />
                </div>
                <span class="sr-only">Loading the Calendar of Activities…</span>
            </div>
        </template>

        <PortalSection v-if="shown" title="Calendar of Activities" view-all-href="/activities" view-all-label="Open calendar ›">
            <div class="grid gap-4 lg:grid-cols-[1.65fr_1fr]">
                <div class="rounded-lg border border-border bg-surface p-4">
                    <h3 class="mb-3 text-sm font-semibold text-text">Coming up in the region · next 7 days</h3>

                    <p v-if="!activities.items.length" class="text-sm text-text-muted">Nothing on the calendar in the next 7 days.</p>
                    <ul v-else class="divide-y divide-border">
                        <li v-for="item in activities.items" :key="item.key">
                            <Link :href="item.href" class="group flex gap-3 rounded-md py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring">
                                <span class="flex w-12 shrink-0 flex-col items-center rounded-md py-1 text-2xs leading-tight font-semibold uppercase" :class="chipClass(item)">
                                    {{ chip(item).top }}
                                    <span class="text-base leading-tight">{{ chip(item).day }}</span>
                                    <span class="normal-case">{{ chip(item).weekday }}</span>
                                </span>
                                <span class="min-w-0 flex-1 text-sm">
                                    <span class="flex flex-wrap items-center gap-1.5">
                                        <span v-if="item.type === 'holiday'" class="text-xs font-semibold text-danger-fg uppercase">Holiday ·</span>
                                        <span class="font-semibold text-text group-hover:text-link group-hover:underline" :class="{ 'line-through': isCancelled(item) }">{{ item.title }}</span>
                                        <span v-for="tag in item.tags" :key="tag.key" class="rounded-full px-2 py-px text-2xs font-medium" :class="tagClasses[tag.key]">{{ tag.label }}</span>
                                    </span>
                                    <span v-if="item.type === 'holiday'" class="block text-xs text-text-muted">{{ item.kind }}</span>
                                    <template v-else>
                                        <span v-if="details(item)" class="block text-xs text-text-muted">{{ details(item) }}</span>
                                        <span v-if="people(item)" class="block text-xs text-text-muted">{{ people(item) }}</span>
                                    </template>
                                </span>
                            </Link>
                        </li>
                    </ul>
                </div>

                <div class="rounded-lg border border-border bg-surface p-4" :aria-busy="loading">
                    <div class="mb-2 flex items-center justify-between gap-2">
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
                                :href="`/activities?month=${activities.month.month}&day=${iso}`"
                                :title="holidayNames[iso]?.join(', ')"
                                :aria-label="dayLabel(iso)"
                                class="relative rounded-md pt-1.5 pb-2 tabular-nums focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                :class="dayClass(iso)"
                            >
                                {{ Number(iso.slice(8)) }}
                                <span
                                    v-if="activityDays.has(iso)"
                                    class="absolute bottom-0.5 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full"
                                    :class="iso === activities.today ? 'bg-white' : 'bg-primary'"
                                    aria-hidden="true"
                                />
                            </Link>
                        </template>
                    </div>

                    <div class="mt-3 flex gap-4 text-xs text-text-muted">
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary" aria-hidden="true" />Activities</span>
                        <span class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-danger" aria-hidden="true" />Holiday</span>
                    </div>
                    <p class="mt-2 text-xs text-text-muted">Click a day to see its activities.</p>
                </div>
            </div>
        </PortalSection>
    </Deferred>
</template>
