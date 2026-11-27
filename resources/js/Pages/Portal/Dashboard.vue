<script setup>
import { computed, ref } from 'vue';
import { Deferred, Head, Link, router, usePage } from '@inertiajs/vue3';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import Skeleton from '@cscro8/ui/Components/UI/Skeleton.vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import ReleaseNotes from '../../Components/Portal/ReleaseNotes.vue';
import { formatDate, formatDateTime } from '../../Utils/datetime';
import { greetingName } from '../../Composables/useSplash';
import { useInstallApp } from '../../Composables/useInstallApp';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import WelcomeBanner from '../../Components/Portal/WelcomeBanner.vue';
import GettingStartedCard from '../../Components/Portal/GettingStartedCard.vue';
import PortalSection from '../../Components/Portal/PortalSection.vue';
import PortalCard from '../../Components/Portal/PortalCard.vue';
import SystemDirectory from '../../Components/Portal/SystemDirectory.vue';
import MiniCalendar from '../../Components/Portal/MiniCalendar.vue';

/**
 * The portal home, laid out as a command center: the welcome banner and the
 * At a glance stat strip on top; then "Needs your attention" and the Systems
 * Directory in a card (three across), beside a side column with what's
 * coming up (a month calendar and the next 7 days), announcements, system
 * status and services. What's New pops up once per release.
 */
defineOptions({ layout: PortalLayout });

const props = defineProps({
    whatsNew: { type: Object, default: null },
    // Needs your attention (a deferred prop): [{ key, kind, title, meta, href, action }].
    attention: { type: Array, default: null },
    glance: { type: Array, default: null },
    shortcuts: { type: Array, default: () => [] },
    activities: { type: Object, default: null },
    gettingStarted: { type: Object, default: null },
    directory: { type: Object, required: true },
    division: { type: String, default: null },
    announcements: { type: Array, required: true },
    maintenanceNotices: { type: Array, required: true },
    services: { type: Array, required: true },
    welcomeMessage: { type: String, default: null },
    userDivision: { type: String, default: null },
});

// Install IMIS as an app: offered once per device, where the browser can.
const INSTALL_DISMISSED_KEY = 'imis-install-offer-dismissed';
const { canInstall, install } = useInstallApp();
const installDismissed = ref(typeof localStorage !== 'undefined' && localStorage.getItem(INSTALL_DISMISSED_KEY) === '1');
const offerInstall = computed(() => canInstall.value && !installDismissed.value);
const dismissInstall = () => {
    installDismissed.value = true;
    localStorage.setItem(INSTALL_DISMISSED_KEY, '1');
};
const installFromCard = async () => {
    if (await install()) dismissInstall();
};

// "What's New": pops up once for the latest unseen release.
const whatsNewOpen = ref(Boolean(props.whatsNew));
const dismissWhatsNew = () => {
    whatsNewOpen.value = false;
    router.post('/whats-new/seen', {}, { preserveScroll: true, preserveState: true });
};

const tileTones = {
    info: 'bg-info-bg text-link',
    success: 'bg-success-bg text-success-fg',
    warning: 'bg-warning-bg text-warning-fg',
    danger: 'bg-danger-bg text-danger-fg',
};

// At a glance: columns follow the number of tiles so every row is full.
const glanceColumns = {
    1: 'lg:grid-cols-1',
    2: 'lg:grid-cols-2',
    3: 'lg:grid-cols-3',
    4: 'lg:grid-cols-4',
    5: 'lg:grid-cols-5',
    6: 'lg:grid-cols-3',
};
const glanceGrid = computed(() => glanceColumns[Math.min(props.glance?.length ?? 4, 6)] ?? 'lg:grid-cols-4');

// Needs your attention: the label and colour of each kind of item.
const attentionKinds = {
    approve: { label: 'Approve', class: 'bg-warning-bg text-warning-fg' },
    review: { label: 'Review', class: 'bg-warning-bg text-warning-fg' },
    sign: { label: 'Sign', class: 'bg-info-bg text-info-fg' },
    returned: { label: 'Returned', class: 'bg-danger-bg text-danger-fg' },
};

// Coming up: a date chip per item (today, holidays and the rest look different).
const parseDate = (iso) => {
    const [year, month, day] = iso.split('-').map(Number);
    return new Date(year, month - 1, day);
};
const chip = (item) => {
    const date = parseDate(item.date);
    return { top: item.today ? 'Today' : date.toLocaleDateString(undefined, { weekday: 'short' }), day: date.getDate() };
};
const chipClass = (item) => {
    if (item.type === 'holiday') return 'bg-danger-bg text-danger-fg';
    return item.today ? 'bg-primary text-white' : 'bg-info-bg text-link';
};
const details = (item) => (item.type === 'holiday' ? item.kind : [item.time, item.venue].filter(Boolean).join(' · '));
const COMING_UP_LIMIT = 5;

const page = usePage();
const user = computed(() => page.props.auth.user);

const greeting = computed(() => {
    const hour = new Date().getHours();
    const part = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    const name = greetingName(user.value);

    return name ? `${part}, ${name}! 👋` : `${part}!`;
});
</script>

<template>
    <Head title="Portal" />

    <div class="space-y-6">
        <WelcomeBanner
            :greeting="greeting"
            :division="userDivision"
            :version="page.props.app?.version"
            :message="welcomeMessage"
            :shortcuts="shortcuts"
        />

        <div
            v-if="offerInstall"
            class="flex flex-col gap-4 rounded-lg border border-border bg-surface p-4 sm:flex-row sm:items-center"
            role="region"
            aria-label="Install IMIS"
        >
            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-info-bg text-link" aria-hidden="true"><Icon name="download" /></span>
            <div class="min-w-0 flex-1">
                <p class="font-semibold text-text">Install IMIS on this computer</p>
                <p class="text-sm text-text-muted">Open IMIS from your desktop or Start menu in its own window, like any other app.</p>
            </div>
            <div class="flex gap-2">
                <Button variant="ghost" size="sm" @click="dismissInstall">Not now</Button>
                <Button variant="primary" size="sm" icon="download" @click="installFromCard">Install</Button>
            </div>
        </div>

        <Deferred data="glance">
            <template #fallback>
                <div class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border lg:grid-cols-4" role="status" aria-label="Loading your summary">
                    <div v-for="n in 4" :key="n" class="flex items-center gap-3.5 bg-surface px-5 py-4">
                        <Skeleton class="h-10 w-10 shrink-0" />
                        <div class="min-w-0 flex-1 space-y-1.5">
                            <Skeleton class="h-3 w-20 max-w-full" />
                            <Skeleton class="h-4 w-24 max-w-full" />
                        </div>
                    </div>
                    <span class="sr-only">Loading your summary…</span>
                </div>
            </template>

            <!--
                At a glance as one stat strip: a single card split by hairline
                dividers (gap-px over the border colour, so they follow any wrap),
                each tile two lines: the label, then the figure with its hint.
            -->
            <section
                v-if="glance?.length"
                aria-label="At a glance"
                class="grid grid-cols-2 gap-px overflow-hidden rounded-lg border border-border bg-border [&>*:last-child:nth-child(odd)]:col-span-2 lg:[&>*:last-child:nth-child(odd)]:col-span-1"
                :class="glanceGrid"
            >
                <Link
                    v-for="tile in glance"
                    :key="tile.key"
                    :href="tile.href"
                    class="group flex min-w-0 items-center gap-3.5 bg-surface px-5 py-4 transition-colors duration-200 hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-inset"
                >
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md transition-transform duration-200 group-hover:scale-105" :class="tileTones[tile.tone]" aria-hidden="true">
                        <Icon :name="tile.icon" size="sm" />
                    </span>
                    <span class="min-w-0">
                        <span class="block truncate text-xs text-text-muted">{{ tile.label }}</span>
                        <span class="mt-0.5 flex items-baseline gap-1.5">
                            <span class="text-xl leading-tight font-semibold text-text tabular-nums">{{ tile.value }}</span>
                            <span class="truncate text-xs text-text-muted">{{ tile.hint }}</span>
                        </span>
                    </span>
                </Link>
            </section>
        </Deferred>

        <div class="grid items-start gap-6 lg:grid-cols-3">
            <div class="min-w-0 space-y-6 lg:col-span-2">
                <!-- One-time setup still to do (signature, PDS, fingerprint); gone once done. -->
                <GettingStartedCard v-if="gettingStarted" :checklist="gettingStarted" />

                <!-- Needs your attention: what is waiting on you, each with the page that settles it. -->
                <section aria-labelledby="attention-heading" class="rounded-lg border border-border bg-surface p-5 sm:p-6">
                    <Deferred data="attention">
                        <template #fallback>
                            <h2 id="attention-heading" class="text-lg font-semibold text-text">Needs your attention</h2>
                            <div class="mt-4 space-y-4" role="status" aria-label="Loading what needs your attention">
                                <div v-for="n in 3" :key="n" class="flex items-center gap-4">
                                    <Skeleton class="h-6 w-20 shrink-0" />
                                    <div class="min-w-0 flex-1 space-y-2">
                                        <Skeleton class="h-4 w-56 max-w-full" />
                                        <Skeleton class="h-3 w-72 max-w-full" />
                                    </div>
                                </div>
                            </div>
                        </template>

                        <div class="flex items-center justify-between gap-3">
                            <h2 id="attention-heading" class="text-lg font-semibold text-text">Needs your attention</h2>
                            <span v-if="attention?.length" class="rounded-full bg-danger-bg px-2.5 py-0.5 text-xs font-semibold text-danger-fg">{{ attention.length }} open</span>
                        </div>
                        <ul v-if="attention?.length" class="mt-2 divide-y divide-border">
                            <li v-for="item in attention" :key="item.key" class="flex items-center gap-4 py-3.5">
                                <span class="w-20 shrink-0 rounded-md py-1 text-center text-xs font-semibold uppercase" :class="attentionKinds[item.kind]?.class">
                                    {{ attentionKinds[item.kind]?.label }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block text-sm font-medium text-text">{{ item.title }}</span>
                                    <span class="block truncate text-xs text-text-muted">{{ item.meta }}</span>
                                </span>
                                <Link
                                    :href="item.href"
                                    class="shrink-0 rounded-md border border-border px-3.5 py-2 text-sm font-semibold text-link transition-colors hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                >
                                    {{ item.action }}<span class="sr-only">: {{ item.title }}</span>
                                </Link>
                            </li>
                        </ul>
                        <p v-else class="mt-3 flex items-center gap-2.5 text-sm text-text-muted">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-success-bg text-success-fg" aria-hidden="true"><Icon name="checkCircle" size="sm" /></span>
                            You're all caught up. Nothing is waiting on you.
                        </p>
                    </Deferred>
                </section>

                <SystemDirectory :directory="directory" :division="division" framed />
            </div>

            <aside class="min-w-0 space-y-6" aria-label="Coming up and notices">
                <!-- Coming up: the next 7 days from the Calendar of Activities (hidden without access). -->
                <Deferred data="activities">
                    <template #fallback>
                        <div class="space-y-4 rounded-lg border border-border bg-surface p-5" role="status" aria-label="Loading what's coming up">
                            <Skeleton class="h-5 w-28" />
                            <Skeleton class="h-48 w-full" />
                            <div v-for="n in 3" :key="n" class="flex gap-3">
                                <Skeleton class="h-11 w-11 shrink-0" />
                                <div class="min-w-0 flex-1 space-y-2">
                                    <Skeleton class="h-4 w-40 max-w-full" />
                                    <Skeleton class="h-3 w-28 max-w-full" />
                                </div>
                            </div>
                        </div>
                    </template>

                    <section v-if="activities" aria-labelledby="coming-up-heading" class="rounded-lg border border-border bg-surface p-5">
                        <div class="flex items-center justify-between gap-3">
                            <h2 id="coming-up-heading" class="text-base font-semibold text-text">Coming up</h2>
                            <Link href="/activities" class="text-sm font-medium text-link hover:text-primary-hover hover:underline">Open calendar</Link>
                        </div>

                        <!-- The month at a glance, then the next 7 days in detail. -->
                        <MiniCalendar class="mt-3" :month="activities.month" :today="activities.today" />

                        <h3 class="mt-4 border-t border-border pt-4 text-xs font-semibold tracking-wide text-text-muted uppercase">Next 7 days</h3>
                        <ol v-if="activities.items.length" class="mt-3 space-y-3">
                            <li v-for="item in activities.items.slice(0, COMING_UP_LIMIT)" :key="item.key">
                                <Link :href="item.href" class="group flex gap-3 rounded-md focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring">
                                    <span class="flex w-11 shrink-0 flex-col items-center rounded-md py-1 text-2xs leading-tight font-semibold uppercase" :class="chipClass(item)" aria-hidden="true">
                                        {{ chip(item).top }}
                                        <span class="text-base">{{ chip(item).day }}</span>
                                    </span>
                                    <span class="min-w-0 text-sm">
                                        <span class="block font-medium text-text group-hover:text-link group-hover:underline">{{ item.title }}</span>
                                        <span v-if="details(item)" class="block truncate text-xs text-text-muted">{{ details(item) }}</span>
                                    </span>
                                </Link>
                            </li>
                        </ol>
                        <p v-else class="mt-3 text-sm text-text-muted">Nothing on the calendar for the next 7 days.</p>
                        <p v-if="activities.items.length > COMING_UP_LIMIT" class="mt-3 text-xs text-text-muted">
                            and {{ activities.items.length - COMING_UP_LIMIT }} more this week
                        </p>
                    </section>
                </Deferred>

                <section aria-labelledby="announcements-heading" class="rounded-lg border border-border bg-surface p-5">
                    <div class="flex items-center justify-between gap-3">
                        <h2 id="announcements-heading" class="text-base font-semibold text-text">Announcements</h2>
                        <Link href="/announcements" class="text-sm font-medium text-link hover:text-primary-hover hover:underline">View all</Link>
                    </div>
                    <ul v-if="announcements.length" class="mt-3 divide-y divide-border">
                        <li v-for="announcement in announcements" :key="announcement.id">
                            <Link :href="`/announcements/${announcement.slug}`" class="group block py-2.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring">
                                <span class="block text-sm font-medium text-text group-hover:text-link group-hover:underline">{{ announcement.title }}</span>
                                <span class="block text-xs text-text-muted">
                                    <span v-if="announcement.featured" class="font-semibold text-info-fg">Featured · </span>
                                    <time :datetime="announcement.publish_at">{{ formatDate(announcement.publish_at) }}</time>
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm text-text-muted">No announcements right now.</p>
                </section>

                <!-- System status: what's under maintenance, or that everything is running. -->
                <section aria-labelledby="status-heading" class="rounded-lg border p-5" :class="maintenanceNotices.length ? 'border-warning-border bg-warning-bg' : 'border-border bg-surface'">
                    <h2 id="status-heading" class="flex items-center gap-2 text-base font-semibold" :class="maintenanceNotices.length ? 'text-warning-fg' : 'text-text'">
                        <Icon :name="maintenanceNotices.length ? 'exclamation' : 'checkCircle'" size="sm" :class="maintenanceNotices.length ? 'text-warning-fg' : 'text-success-fg'" />
                        {{ maintenanceNotices.length ? 'Under maintenance' : 'System status' }}
                    </h2>
                    <ul v-if="maintenanceNotices.length" class="mt-2 space-y-2 text-sm text-text">
                        <li v-for="notice in maintenanceNotices" :key="notice.id">
                            <Link :href="`/applications/${notice.id}`" class="font-medium underline">{{ notice.name }}</Link>
                            <span v-if="notice.message"> — {{ notice.message }}</span>
                            <span v-if="notice.since" class="block text-xs text-text-muted">
                                Since <time :datetime="notice.since">{{ formatDateTime(notice.since) }}</time>
                            </span>
                        </li>
                    </ul>
                    <p v-else class="mt-2 text-sm text-text-muted">All systems are running normally.</p>
                </section>

                <PortalSection v-if="services.length" title="Services" view-all-href="/services">
                    <div class="space-y-3">
                        <PortalCard
                            v-for="service in services"
                            :key="service.id"
                            :href="`/services/${service.id}`"
                            :icon="service.icon || 'clipboard'"
                            :title="service.name"
                            :description="service.description"
                        />
                    </div>
                </PortalSection>
            </aside>
        </div>
    </div>

    <Modal v-if="whatsNew" :open="whatsNewOpen" title="What's New in IMIS" size="lg" @close="dismissWhatsNew">
        <ReleaseNotes :release="whatsNew" :limit="8" />

        <template #footer>
            <Link href="/whats-new" class="mr-auto self-center text-sm font-medium text-link hover:text-primary-hover">
                All release notes
            </Link>
            <Button data-autofocus @click="dismissWhatsNew">Got it</Button>
        </template>
    </Modal>
</template>
