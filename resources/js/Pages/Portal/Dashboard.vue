<script setup>
import { computed, ref } from 'vue';
import { Deferred, Head, Link, router, usePage } from '@inertiajs/vue3';
import Icon from '../../Components/UI/Icon.vue';
import Skeleton from '../../Components/UI/Skeleton.vue';
import Modal from '../../Components/Feedback/Modal.vue';
import Button from '../../Components/UI/Button.vue';
import ReleaseNotes from '../../Components/Portal/ReleaseNotes.vue';
import Alert from '../../Components/Feedback/Alert.vue';
import { formatDateTime } from '../../Utils/datetime';
import { firstName } from '../../Composables/useSplash';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import WelcomeBanner from '../../Components/Portal/WelcomeBanner.vue';
import PortalSection from '../../Components/Portal/PortalSection.vue';
import PortalCard from '../../Components/Portal/PortalCard.vue';
import YourSystems from '../../Components/Portal/YourSystems.vue';
import SystemDirectory from '../../Components/Portal/SystemDirectory.vue';
import AnnouncementCard from '../../Components/Announcements/AnnouncementCard.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    whatsNew: { type: Object, default: null },
    // The personal "At a glance" tiles (a deferred prop).
    glance: { type: Array, default: null },
    directory: { type: Object, required: true },
    division: { type: String, default: null },
    announcements: { type: Array, required: true },
    maintenanceNotices: { type: Array, required: true },
    services: { type: Array, required: true },
    // From System Settings.
    welcomeMessage: { type: String, default: null },
    // The user's division, shown as a badge beside the greeting.
    userDivision: { type: String, default: null },
});

// "What's New": pops up once for the latest unseen release. Closing it
// in any way (button, Escape, backdrop) marks it seen server-side.
const whatsNewOpen = ref(Boolean(props.whatsNew));
const dismissWhatsNew = () => {
    whatsNewOpen.value = false;
    router.post('/whats-new/seen', {}, { preserveScroll: true, preserveState: true });
};

const tileTones = {
    info: 'bg-info-bg text-primary',
    success: 'bg-success-bg text-success',
    warning: 'bg-warning-bg text-warning',
    danger: 'bg-danger-bg text-danger',
};

const page = usePage();
const user = computed(() => page.props.auth.user);

// The legacy greeting ("Good morning, Juan! 👋"), from the visiting
// browser's own clock — server time would be wrong for the greeting's
// purpose whenever it differs from the user's local timezone.
const greeting = computed(() => {
    const hour = new Date().getHours();
    const part = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
    const name = firstName(user.value?.name);

    return name ? `${part}, ${name}! 👋` : `${part}!`;
});
</script>

<template>
    <Head title="Portal" />

    <div class="space-y-8">
        <WelcomeBanner
            :greeting="greeting"
            :division="userDivision"
            :version="page.props.app?.version"
            :message="welcomeMessage"
        />

        <!-- At a glance: your own figures, loaded just after the page appears. -->
        <Deferred data="glance">
            <template #fallback>
                <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" role="status" aria-label="Loading your summary">
                    <div v-for="n in 4" :key="n" class="flex items-center gap-3.5 rounded-lg border border-border bg-surface p-4">
                        <Skeleton class="h-10 w-10 shrink-0" />
                        <div class="min-w-0 flex-1 space-y-2">
                            <Skeleton class="h-5 w-12" />
                            <Skeleton class="h-3 w-24 max-w-full" />
                        </div>
                    </div>
                    <span class="sr-only">Loading your summary…</span>
                </div>
            </template>

            <section v-if="glance?.length" aria-label="At a glance" class="grid grid-cols-2 gap-4 lg:grid-cols-4">
                <Link
                    v-for="tile in glance"
                    :key="tile.key"
                    :href="tile.href"
                    class="group flex items-center gap-3.5 rounded-lg border border-border bg-surface p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                >
                    <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md transition-transform duration-200 group-hover:scale-105" :class="tileTones[tile.tone]" aria-hidden="true">
                        <Icon :name="tile.icon" />
                    </span>
                    <span class="min-w-0">
                        <span class="block text-xl leading-none font-semibold text-text tabular-nums">{{ tile.value }}</span>
                        <span class="mt-1 block truncate text-xs text-text-muted">{{ tile.label }} · {{ tile.hint }}</span>
                    </span>
                </Link>
            </section>
        </Deferred>

        <Alert v-if="maintenanceNotices.length" tone="warning" title="Systems under maintenance">
            <ul class="space-y-1">
                <li v-for="notice in maintenanceNotices" :key="notice.id">
                    <Link :href="`/applications/${notice.id}`" class="font-medium underline">{{ notice.name }}</Link>
                    <span v-if="notice.message"> — {{ notice.message }}</span>
                    <span v-if="notice.since" class="text-text-muted">
                        (since <time :datetime="notice.since">{{ formatDateTime(notice.since) }}</time>)
                    </span>
                </li>
            </ul>
        </Alert>

        <!-- Only shown when something is live — no placeholder announcements. -->
        <PortalSection v-if="announcements.length" title="Announcements" view-all-href="/announcements">
            <div class="grid gap-3 lg:grid-cols-3">
                <AnnouncementCard
                    v-for="announcement in announcements"
                    :key="announcement.id"
                    :announcement="announcement"
                />
            </div>
        </PortalSection>

        <YourSystems :directory="directory" />

        <SystemDirectory :directory="directory" :division="division" />

        <!-- Like Announcements: only once a service has been added (the legacy home has no such section). -->
        <PortalSection v-if="services.length" title="Services" view-all-href="/services">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
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
    </div>

    <Modal v-if="whatsNew" :open="whatsNewOpen" title="What's New in IMIS" size="lg" @close="dismissWhatsNew">
        <ReleaseNotes :release="whatsNew" />

        <template #footer>
            <Link href="/whats-new" class="mr-auto self-center text-sm font-medium text-primary hover:text-primary-hover">
                All release notes
            </Link>
            <Button data-autofocus @click="dismissWhatsNew">Got it</Button>
        </template>
    </Modal>
</template>
