<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Icon from '../Components/UI/Icon.vue';
import SidebarNav from '../Components/Navigation/SidebarNav.vue';
import UserMenu from '../Components/Navigation/UserMenu.vue';
import NotificationMenu from '../Components/Navigation/NotificationMenu.vue';
import PortalSearch from '../Components/Portal/PortalSearch.vue';
import AboutDialog from '../Components/Portal/AboutDialog.vue';
import FeedbackDialog from '../Components/Portal/FeedbackDialog.vue';
import SessionTimeoutDialog from '../Components/Feedback/SessionTimeoutDialog.vue';
import { firstName, useSplash } from '../Composables/useSplash';
import { useBranding } from '../Composables/useBranding';

/**
 * Shared authenticated shell that PortalLayout and AdminLayout both wrap
 * around their own `navItems` — it never hardcodes a business module.
 *
 * Follows the legacy IMIS layouts: a fixed top bar (logo, search, user
 * menu) and a hamburger-toggled sidebar. The portal starts with the
 * sidebar closed (legacy dashboard); the admin area starts with it open
 * and white, like the legacy NiceAdmin panel. On desktop the sidebar docks
 * beside the content and each area remembers its own choice per browser;
 * on smaller screens it overlays the page and closes on navigation.
 */
const props = defineProps({
    navItems: {
        type: Array,
        required: true,
    },
    variant: {
        type: String,
        default: 'portal',
        validator: (value) => ['portal', 'admin'].includes(value),
    },
});

const STORAGE_KEY = props.variant === 'admin' ? 'imis.admin-sidebar-open' : 'imis.sidebar-open';
const DEFAULT_OPEN = props.variant === 'admin';
const DESKTOP_QUERY = '(min-width: 1024px)';

const page = usePage();
const brand = useBranding();
// From System Settings (shared by HandleInertiaRequests).
const portalNotice = computed(() => page.props.portal?.notice);
const unread = computed(() => page.props.notifications?.unread ?? 0);

// Right after signing in, open with the legacy "Login Successful" splash.
// Raised during setup, so it paints in the same frame as this page, and
// `instant` (no fade-in) so the page never shows through first.
const { show: showSplash, hide: hideSplash } = useSplash();
if (page.props.flash?.signedIn) {
    showSplash({
        instant: true,
        title: 'Login Successful',
        subtitle: 'Welcome back, {name}',
        name: firstName(page.props.auth.user?.name),
        note: 'Redirecting to your dashboard…',
    });
    hideSplash(1400);
}

const aboutOpen = ref(false);
const feedbackOpen = ref(false);
const sidebarOpen = ref(false);
const isDesktop = ref(false);
const menuButtonRef = ref(null);
const sidebarRef = ref(null);

const readPreference = () => {
    try {
        const saved = window.localStorage.getItem(STORAGE_KEY);

        return saved === null ? DEFAULT_OPEN : saved === '1';
    } catch {
        return DEFAULT_OPEN;
    }
};

const savePreference = (open) => {
    try {
        window.localStorage.setItem(STORAGE_KEY, open ? '1' : '0');
    } catch {
        // Storage unavailable (private mode, blocked) — the toggle still works for this page.
    }
};

const setSidebar = async (open) => {
    sidebarOpen.value = open;

    if (isDesktop.value) {
        savePreference(open);

        return;
    }

    // Overlay mode behaves like a dialog: move focus in, and back out.
    await nextTick();
    if (open) {
        sidebarRef.value?.querySelector('a')?.focus();
    } else {
        menuButtonRef.value?.focus();
    }
};

const toggleSidebar = () => setSidebar(!sidebarOpen.value);

const onKeydown = (event) => {
    if (event.key === 'Escape' && sidebarOpen.value && !isDesktop.value) {
        setSidebar(false);
    }
};

const isTypingTarget = (element) =>
    element instanceof HTMLElement &&
    (element.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(element.tagName));

/**
 * `/` (when not already typing somewhere) or Ctrl/⌘+K focuses the search
 * box. Several boxes may exist (desktop header, mobile header, the search
 * page's own); the last visible one wins, so on /search it's the page's.
 */
const onGlobalKeydown = (event) => {
    const isSlash = event.key === '/' && !event.ctrlKey && !event.metaKey && !event.altKey;
    const isCommandK = event.key.toLowerCase() === 'k' && (event.ctrlKey || event.metaKey);

    if (!(isCommandK || (isSlash && !isTypingTarget(event.target)))) return;

    const visible = [...document.querySelectorAll('[data-portal-search]')].filter((input) => input.offsetParent !== null);
    const target = visible.at(-1);

    if (target) {
        event.preventDefault();
        target.focus();
        target.select();
    }
};

let mediaQuery;
let removeNavigateListener;

const onMediaChange = () => {
    isDesktop.value = mediaQuery.matches;
    // Docked on desktop per the saved choice; overlays always start closed.
    sidebarOpen.value = isDesktop.value ? readPreference() : false;
};

onMounted(() => {
    mediaQuery = window.matchMedia(DESKTOP_QUERY);
    onMediaChange();
    mediaQuery.addEventListener('change', onMediaChange);
    window.addEventListener('keydown', onGlobalKeydown);
    removeNavigateListener = router.on('navigate', () => {
        if (!isDesktop.value) sidebarOpen.value = false;
    });
});

onBeforeUnmount(() => {
    mediaQuery?.removeEventListener('change', onMediaChange);
    window.removeEventListener('keydown', onGlobalKeydown);
    removeNavigateListener?.();
});
</script>

<template>
    <div class="min-h-screen bg-surface-muted" @keydown="onKeydown">
        <a
            href="#main-content"
            class="sr-only z-50 rounded-md bg-surface px-4 py-2 text-sm font-medium text-primary shadow-sm focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:outline-none focus:ring-2 focus:ring-focus-ring"
        >
            Skip to main content
        </a>

        <header
            class="fixed inset-x-0 top-0 z-40 flex h-16 items-center gap-2 border-b border-border bg-surface px-3 sm:px-4"
        >
            <button
                ref="menuButtonRef"
                type="button"
                class="rounded-md p-2 text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :aria-label="sidebarOpen ? 'Hide navigation menu' : 'Show navigation menu'"
                aria-controls="app-sidebar"
                :aria-expanded="sidebarOpen"
                @click="toggleSidebar"
            >
                <Icon name="menu" size="md" />
            </button>

            <Link
                href="/dashboard"
                class="flex items-center gap-2 rounded-md pr-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
            >
                <img :src="brand.logo" alt="CSC" class="h-9 w-auto" />
                <span class="hidden text-lg font-semibold text-primary sm:inline">{{ brand.header_name }}</span>
            </Link>

            <div class="hidden flex-1 justify-center px-6 md:flex">
                <PortalSearch id="header-search" />
            </div>

            <div class="ml-auto flex items-center gap-1">
                <NotificationMenu v-if="page.props.notifications" :unread="unread" />
                <UserMenu v-if="page.props.auth.user" :user="page.props.auth.user" @about="aboutOpen = true" @feedback="feedbackOpen = true" />
            </div>
        </header>

        <AboutDialog :open="aboutOpen" @close="aboutOpen = false" />
        <FeedbackDialog v-if="page.props.auth.user" :open="feedbackOpen" @close="feedbackOpen = false" />
        <SessionTimeoutDialog v-if="page.props.auth.user" />

        <!-- Overlay backdrop (below desktop width only) -->
        <div
            v-if="sidebarOpen && !isDesktop"
            class="fixed inset-0 top-16 z-30 bg-text/50"
            aria-hidden="true"
            @click="setSidebar(false)"
        />

        <aside
            id="app-sidebar"
            ref="sidebarRef"
            class="fixed bottom-0 left-0 top-16 z-30 flex w-64 flex-col transition-transform duration-200 motion-reduce:transition-none"
            :class="[sidebarOpen ? 'translate-x-0' : '-translate-x-full', variant === 'admin' ? 'border-r border-border bg-surface text-text shadow-sm' : 'bg-csc-blue text-white']"
            :role="isDesktop ? undefined : 'dialog'"
            :aria-modal="isDesktop ? undefined : sidebarOpen"
            aria-label="Navigation menu"
            :inert="!sidebarOpen"
        >
            <div class="min-h-0 flex-1 overflow-y-auto py-4" :class="{ 'scrollbar-on-dark': variant !== 'admin' }">
                <!-- Above the links, e.g. AdminLayout's area switcher. -->
                <slot name="sidebar-header" />
                <SidebarNav :items="navItems" :variant="variant" />
            </div>

            <!-- Version and agency, as in the legacy About footer. -->
            <footer
                class="shrink-0 border-t px-6 py-3 text-xs leading-relaxed"
                :class="variant === 'admin' ? 'border-border text-text-muted' : 'border-white/10 text-white/70'"
            >
                <p class="font-medium">IMIS v{{ page.props.app?.version }}</p>
                <p>CSC Regional Office VIII</p>
            </footer>
        </aside>

        <div
            class="flex min-h-screen flex-col pt-16 transition-[padding] duration-200 motion-reduce:transition-none"
            :class="{ 'lg:pl-64': sidebarOpen }"
        >
            <div class="border-b border-border bg-surface px-4 py-2 md:hidden">
                <PortalSearch id="header-search-mobile" />
            </div>

            <!-- Plain text, rendered escaped. role="status" rather than
                 "alert" so it isn't re-announced on every page visit. -->
            <div
                v-if="portalNotice"
                role="status"
                class="flex items-start gap-2 border-b border-warning-border bg-warning-bg px-4 py-2 text-sm text-text sm:px-6"
            >
                <Icon name="info" size="sm" class="mt-0.5 shrink-0 text-warning" />
                <p class="whitespace-pre-line">{{ portalNotice }}</p>
            </div>

            <main id="main-content" tabindex="-1" class="flex-1 px-4 py-6 focus:outline-none sm:px-6 lg:px-8">
                <slot />
            </main>
        </div>
    </div>
</template>
