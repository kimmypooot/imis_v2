<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import Icon from '../UI/Icon.vue';
import Skeleton from '../UI/Skeleton.vue';
import { formatDateTime } from '../../Utils/datetime';

/**
 * The top-bar bell: a small dropdown with the latest notifications, a
 * "Mark all as read" shortcut, and "Show all notifications" at the bottom.
 * The list is fetched each time it opens. On phones the panel spans the
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
                class="absolute -right-0.5 -top-0.5 min-w-[1.1rem] rounded-full bg-danger px-1 text-center text-[0.65rem] font-bold leading-[1.1rem] text-white"
                aria-hidden="true"
            >{{ badge }}</span>
        </button>

        <div
            v-if="open"
            id="notification-menu-panel"
            class="fixed inset-x-2 top-[4.25rem] z-50 flex max-h-[calc(100dvh-5rem)] flex-col rounded-md border border-border bg-surface shadow-lg sm:absolute sm:inset-x-auto sm:right-0 sm:top-auto sm:mt-2 sm:w-96"
        >
            <div class="flex shrink-0 items-center justify-between gap-3 border-b border-border px-3 py-2">
                <h2 class="text-sm font-semibold text-text">Notifications</h2>
                <button
                    v-if="count"
                    type="button"
                    class="rounded text-xs text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
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
                <p v-else-if="!items.length" class="px-3 py-6 text-center text-sm text-text-muted">You're all caught up.</p>
                <ul v-else class="divide-y divide-border">
                    <li v-for="item in items" :key="item.id">
                        <a :href="`/notifications/${item.id}`" class="flex gap-3 px-3 py-2.5 hover:bg-surface-muted focus:outline-none focus-visible:bg-surface-muted focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring">
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full" :class="item.read ? 'bg-transparent' : 'bg-primary'" aria-hidden="true" />
                            <span class="min-w-0">
                                <span class="block text-sm text-text" :class="item.read ? '' : 'font-semibold'">
                                    {{ item.title }}<span v-if="!item.read" class="sr-only"> (unread)</span>
                                </span>
                                <span class="line-clamp-2 block text-sm text-text-muted">{{ item.message }}</span>
                                <span class="block text-xs text-text-muted">{{ formatDateTime(item.created_at) }}</span>
                            </span>
                        </a>
                    </li>
                </ul>
            </div>

            <Link
                href="/notifications"
                class="shrink-0 rounded-b-md border-t border-border px-3 py-2.5 text-center text-sm font-medium text-primary hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-focus-ring"
                @click="close"
            >Show all notifications</Link>
        </div>
    </div>
</template>
