<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import KitAppShell from '@cscro8/ui/Layouts/AppShell.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import UserMenu from '../Components/Navigation/UserMenu.vue';
import NotificationMenu from '../Components/Navigation/NotificationMenu.vue';
import HelpMenu from '../Components/Navigation/HelpMenu.vue';
import AppFooter from '../Components/Navigation/AppFooter.vue';
import PortalSearch from '../Components/Portal/PortalSearch.vue';
import AboutDialog from '../Components/Portal/AboutDialog.vue';
import FeedbackDialog from '../Components/Portal/FeedbackDialog.vue';
import SessionTimeoutDialog from '../Components/Feedback/SessionTimeoutDialog.vue';
import CommandPalette from '@cscro8/ui/Components/Navigation/CommandPalette.vue';
import { focusFirstErrorAfterInvalidSubmits } from '@cscro8/ui/Utils/focusFirstError.js';
import { accountNavItems, adminAccessPermissions, adminNavItems, flattenNav, hrAccessPermissions, hrNavItems, portalNavItems } from '../Utils/navigation';
import { greetingName, useSplash } from '../Composables/useSplash';
import { useBranding } from '../Composables/useBranding';
import { useAuthorization } from '../Composables/useAuthorization';
import { refreshRememberedAccount } from '../Composables/useRememberedAccounts';

/**
 * IMIS's authenticated shell — the shared AppShell (@cscro8/ui) filled with
 * IMIS's own parts: the office branding, portal search, help / notification /
 * user menus, the portal notice, the footer and its dialogs, and IMIS's
 * sidebar rules (permissions, flags, badges). PortalLayout and AdminLayout
 * wrap their own `navItems` in it; it never hardcodes a business module.
 *
 * The portal starts with the sidebar closed (legacy dashboard); the admin
 * area starts with it open, like the legacy NiceAdmin panel. Each remembers
 * its own choice per browser.
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

const page = usePage();
const brand = useBranding();
const { can, canAny } = useAuthorization();
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
        name: greetingName(page.props.auth.user),
        note: 'Redirecting to your dashboard…',
    });
    hideSplash(1400);
}

const aboutOpen = ref(false);
const feedbackOpen = ref(false);

/**
 * Who may see a sidebar entry: `flag` — only when the server says so (e.g.
 * leave approvers); `permission` — one string (must have it) or an array (at
 * least one). UX only: every route is authorized server-side regardless.
 */
const isPermitted = (item) => {
    if (item.flag && !page.props.flags?.[item.flag]) {
        return false;
    }

    if (!item.permission) {
        return true;
    }

    return Array.isArray(item.permission) ? canAny(item.permission) : can(item.permission);
};

// "Go to…" (Ctrl/⌘+K): every page this person may open, from all three
// areas, by the same rules as the sidebar. HR and Admin pages need access
// to that area first, as the area switcher does.
const paletteItems = computed(() => {
    if (!page.props.auth.user) return [];

    const pages = [
        ...flattenNav(portalNavItems, 'My pages'),
        ...flattenNav(accountNavItems, 'Account'),
        ...(canAny(hrAccessPermissions) ? flattenNav(hrNavItems, 'Human Resources') : []),
        ...(canAny(adminAccessPermissions) ? flattenNav(adminNavItems, 'Administration') : []),
    ];
    const seen = new Set();

    return pages.filter((item) => isPermitted(item) && !seen.has(item.href) && seen.add(item.href));
});

const nav = computed(() => ({
    isPermitted,
    badges: page.props.badges ?? {},
    roots: ['/dashboard', '/admin', '/admin/hr'],
    storageKey: 'imis.nav-open',
    local: Boolean(page.props.app?.local),
}));

// After a submit the server rejects, take the person to the first problem field.
let removeErrorFocus;

onMounted(() => {
    // Keeps this person's saved login-page card (name, photo) current.
    refreshRememberedAccount(page.props.auth.user);
});

onMounted(() => (removeErrorFocus = focusFirstErrorAfterInvalidSubmits()));
onBeforeUnmount(() => removeErrorFocus?.());
</script>

<template>
    <KitAppShell
        :nav-items="navItems"
        :variant="variant"
        :storage-key="props.variant === 'admin' ? 'imis.admin-sidebar-open' : 'imis.sidebar-open'"
        :default-open="props.variant === 'admin'"
        :nav="nav"
    >
        <template #brand>
            <img :src="brand.logo" alt="CSC" class="h-9 w-auto" />
            <span class="hidden flex-col sm:flex">
                <span class="text-lg leading-tight font-semibold text-link">{{ brand.header_name }}</span>
                <span class="text-2xs leading-tight text-text-muted">Integrated Management Information System</span>
            </span>
        </template>

        <template #search>
            <PortalSearch id="header-search" />
        </template>

        <template #search-mobile>
            <PortalSearch id="header-search-mobile" />
        </template>

        <template #actions>
            <HelpMenu v-if="page.props.auth.user" @feedback="feedbackOpen = true" />
            <NotificationMenu v-if="page.props.notifications" :unread="unread" />
            <UserMenu v-if="page.props.auth.user" :user="page.props.auth.user" @feedback="feedbackOpen = true" />
        </template>

        <template #overlays>
            <AboutDialog :open="aboutOpen" @close="aboutOpen = false" />
            <FeedbackDialog v-if="page.props.auth.user" :open="feedbackOpen" @close="feedbackOpen = false" />
            <SessionTimeoutDialog v-if="page.props.auth.user" />
        </template>

        <!-- Above the links, e.g. AdminLayout's area switcher. -->
        <template v-if="$slots['sidebar-header']" #sidebar-header>
            <slot name="sidebar-header" />
        </template>

        <template #notice>
            <!-- Plain text, rendered escaped. role="status" rather than
                 "alert" so it isn't re-announced on every page visit. -->
            <div
                v-if="portalNotice"
                role="status"
                class="flex items-start gap-2 border-b border-warning-border bg-warning-bg px-4 py-2 text-sm text-text sm:px-6 print:hidden"
            >
                <Icon name="info" size="sm" class="mt-0.5 shrink-0 text-warning-fg" />
                <p class="whitespace-pre-line">{{ portalNotice }}</p>
            </div>
        </template>

        <template #command-palette="{ open, close }">
            <CommandPalette :open="open" :items="paletteItems" :search-href="(q) => `/search?q=${encodeURIComponent(q)}`" search-label="Search the portal for" @close="close" />
        </template>

        <template #footer>
            <AppFooter class="print:hidden" @about="aboutOpen = true" @feedback="feedbackOpen = true" />
        </template>

        <slot />
    </KitAppShell>
</template>
