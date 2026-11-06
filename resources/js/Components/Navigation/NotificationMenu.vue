<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import Skeleton from '../UI/Skeleton.vue';
import { dayGroup, formatDateTime, timeAgo } from '../../Utils/datetime';

/**
 * The top-bar bell: a small dropdown with the latest notifications, a
 * "Mark all as read" shortcut, and "Show all notifications" at the bottom.
 * Each item shows an icon for its kind, how long ago it came in, and is
 * grouped by day; unread ones are tinted. The list is fetched each time it opens. On phones the panel spans the
 * screen under the top bar instead of hanging off the bell.
 */
const props = defineProps({
    unread: { type: Number, default: 0 },
});

const open = ref(false);
const loading = ref(false);
const failed = ref(false);
const items = ref([]);
const rootRef = ref(null);
const buttonRef = ref(null);

// The page's count, kept fresh between page loads by the poll below.
const count = ref(props.unread);
watch(() => props.unread, (value) => {
    count.value = value;
});

// Same icons as the full Notifications page, so a list scans by shape.
const kindIcons = { leave: 'calendar', executive: 'chartBar', hr: 'users', feedback: 'chat', payslip: 'database', pds: 'clipboard' };
const iconFor = (item) => kindIcons[item.kind?.split('_')[0]] ?? 'bell';

// Newest first, filed under Today / Yesterday / This week / Earlier.
const groups = computed(() =>
    items.value.reduce((list, item) => {
        const label = dayGroup(item.created_at);
        const last = list.at(-1);

        if (last?.label === label) {
            last.items.push(item);
        } else {
            list.push({ label, items: [item] });
        }

        return list;
    }, []),
);

const badge = computed(() => (count.value > 99 ? '99+' : count.value));

const fetchRecent = async () => {
    const response = await fetch('/notifications/recent', {
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin',
    });
    if (!response.ok) throw new Error(String(response.status));

    const data = await response.json();
    const arrived = data.unread > count.value;
    items.value = data.notifications;
    count.value = data.unread;

    // Something new came in: refresh the sidebar counts (e.g. new feedback) too.
    if (arrived) router.reload({ only: ['badges'] });
};

const load = async () => {
    loading.value = true;
    failed.value = false;

    try {
        await fetchRecent();
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
};

// Live updates. Every request renews the idle-session timer, so the poll
// only runs while the tab is visible and the person has used the page in
// the last couple of minutes (the same activity the session-timeout
// warning tracks). An untouched tab sends nothing and still times out.
const POLL_EVERY = 60_000;
const ACTIVE_WITHIN = 120_000;
let pollTimer = null;

const recentlyActive = () => {
    try {
        return Date.now() - (Number(window.localStorage.getItem('imis.session-activity')) || 0) < ACTIVE_WITHIN;
    } catch {
        return false;
    }
};

const poll = async () => {
    if (document.hidden || !recentlyActive()) return;

    try {
        await fetchRecent();
    } catch {
        // Offline or signed out — the next page load sorts it out.
    }
};

const toggle = () => {
    open.value = !open.value;
    if (open.value) load();
};

const close = () => {
    open.value = false;
};

const markAllRead = () =>
    router.post('/notifications/read-all', {}, { preserveScroll: true, onSuccess: load });

const onDocumentClick = (event) => {
    if (open.value && rootRef.value && !rootRef.value.contains(event.target)) close();
};

const onKeydown = (event) => {
    if (event.key !== 'Escape' || !open.value) return;

    const hadFocus = rootRef.value?.contains(document.activeElement);
    close();
    if (hadFocus) buttonRef.value?.focus();
};

onMounted(() => {
    document.addEventListener('click', onDocumentClick);
    document.addEventListener('keydown', onKeydown);
    document.addEventListener('visibilitychange', poll);
    pollTimer = window.setInterval(poll, POLL_EVERY);
});

onBeforeUnmount(() => {
    document.removeEventListener('click', onDocumentClick);
    document.removeEventListener('keydown', onKeydown);
    document.removeEventListener('visibilitychange', poll);
    window.clearInterval(pollTimer);
});
</script>

<template>
    <div ref="rootRef" class="sm:relative">
        <button
            ref="buttonRef"
            type="button"
            class="relative rounded-md p-2 text-text-muted hover:bg-surface-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            :class="{ 'bg-surface-muted text-text': open }"
            :aria-label="count ? `Notifications, ${count} unread` : 'Notifications'"
            aria-haspopup="true"
            :aria-expanded="open"
            aria-controls="notification-menu-panel"
            @click="toggle"
        >
            <Icon name="bell" size="md" />
            <span
                v-if="count"
                class="absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-danger px-1 text-center text-2xs font-bold leading-[1.1rem] text-white"
                aria-hidden="true"
            >{{ badge }}</span>
        </button>

        <div
            v-if="open"
            id="notification-menu-panel"
            class="fixed inset-x-2 top-[4.25rem] z-50 flex max-h-[calc(100dvh-5rem)] flex-col rounded-md border border-border bg-surface shadow-lg sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-[22rem]"
        >
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-border px-3 py-2">
                <h2 class="flex items-center gap-2 text-compact font-semibold text-text">
                    Notifications
                    <span v-if="count" class="rounded-full bg-info-bg px-2 py-0.5 text-2xs font-medium text-primary">{{ badge }} new</span>
                </h2>
                <button
                    v-if="count"
                    type="button"
                    class="rounded-sm text-xs text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    @click="markAllRead"
                >Mark all as read</button>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto" aria-live="polite">
                <div v-if="loading && !items.length" class="divide-y divide-border" role="status" aria-label="Loading notifications">
                    <div v-for="n in 3" :key="n" class="flex gap-3 px-3 py-3">
                        <Skeleton class="mt-1.5 h-2 w-2 shrink-0 rounded-full" />
                        <div class="min-w-0 flex-1 space-y-2">
                            <Skeleton class="h-3.5 w-2/3" />
                            <Skeleton class="h-3 w-full" />
                            <Skeleton class="h-3 w-16" />
                        </div>
                    </div>
                    <span class="sr-only">Loading…</span>
                </div>
                <p v-else-if="failed" class="px-3 py-6 text-center text-sm text-danger">Couldn't load notifications. Try again.</p>
                <p v-else-if="!items.length" class="px-3 py-6 text-center text-xs text-text-muted">You're all caught up.</p>
                <div v-else>
                    <section v-for="group in groups" :key="group.label" :aria-label="group.label">
                        <h3 class="sticky top-0 border-b border-border bg-surface-muted px-3 py-1 text-2xs font-semibold tracking-wide text-text-muted uppercase">{{ group.label }}</h3>
                        <ul class="divide-y divide-border">
                            <li v-for="item in group.items" :key="item.id">
                                <a
                                    :href="`/notifications/${item.id}`"
                                    class="flex gap-3 px-3 py-3 hover:bg-surface-muted focus:outline-none focus-visible:bg-surface-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring"
                                    :class="item.read ? '' : 'bg-info-bg/40'"
                                >
                                    <span
                                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                                        :class="item.read ? 'bg-surface-muted text-text-muted' : 'bg-info-bg text-primary'"
                                        aria-hidden="true"
                                    >
                                        <Icon :name="iconFor(item)" size="sm" />
                                    </span>
                                    <span class="min-w-0 flex-1">
                                        <span class="flex items-start justify-between gap-2">
                                            <span class="block text-compact leading-snug text-text" :class="item.read ? '' : 'font-semibold'">
                                                {{ item.title }}<span v-if="!item.read" class="sr-only"> (unread)</span>
                                            </span>
                                            <span v-if="!item.read" class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" aria-hidden="true" />
                                        </span>
                                        <span class="line-clamp-2 block text-xs leading-snug text-text-muted">{{ item.message }}</span>
                                        <time :datetime="item.created_at" :title="formatDateTime(item.created_at)" class="mt-0.5 block text-2xs text-text-muted">{{ timeAgo(item.created_at) }}</time>
                                    </span>
                                </a>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>

            <Link
                href="/notifications"
                class="shrink-0 rounded-b-md border-t border-border px-3 py-2 text-center text-xs font-medium text-primary hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring"
                @click="close"
            >Show all notifications</Link>
        </div>
    </div>
</template>
