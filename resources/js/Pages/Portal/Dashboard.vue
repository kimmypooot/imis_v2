<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import Modal from '../../Components/Feedback/Modal.vue';
import Button from '../../Components/UI/Button.vue';
import ReleaseNotes from '../../Components/Portal/ReleaseNotes.vue';
import Alert from '../../Components/Feedback/Alert.vue';
import { formatDateTime } from '../../Utils/datetime';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import WelcomeBanner from '../../Components/Portal/WelcomeBanner.vue';
import StatsBar from '../../Components/Portal/StatsBar.vue';
import PortalSection from '../../Components/Portal/PortalSection.vue';
import PortalCard from '../../Components/Portal/PortalCard.vue';
import SystemDirectory from '../../Components/Portal/SystemDirectory.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import AnnouncementCard from '../../Components/Announcements/AnnouncementCard.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    stats: { type: Object, required: true },
    whatsNew: { type: Object, default: null },
    directory: { type: Object, required: true },
    division: { type: String, default: null },
    announcements: { type: Array, required: true },
    mySystems: { type: Array, required: true },
    maintenanceNotices: { type: Array, required: true },
    services: { type: Array, required: true },
    // From System Settings.
    welcomeMessage: { type: String, default: null },
    contact: { type: Object, default: () => ({}) },
});

// "What's New": pops up once for the latest unseen release. Closing it
// in any way (button, Escape, backdrop) marks it seen server-side.
const whatsNewOpen = ref(Boolean(props.whatsNew));
const dismissWhatsNew = () => {
    whatsNewOpen.value = false;
    router.post('/whats-new/seen', {}, { preserveScroll: true, preserveState: true });
};

const hasContact = computed(() => Boolean(props.contact.email || props.contact.phone || props.contact.website));

const page = usePage();
const user = computed(() => page.props.auth.user);

// Computed from the visiting browser's own clock — server time would be
// wrong for the greeting's purpose whenever it differs from the user's
// local timezone.
const greeting = computed(() => {
    const hour = new Date().getHours();

    if (hour < 12) return 'Good morning';
    if (hour < 18) return 'Good afternoon';
    return 'Good evening';
});
</script>

<template>
    <Head title="Portal" />

    <div class="space-y-8">
        <div class="space-y-4">
            <WelcomeBanner :greeting="`${greeting}, ${user?.name ?? ''}`" :message="welcomeMessage" />
            <StatsBar :stats="stats" />
        </div>

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

        <!-- Only shown once the user holds a role in some system. -->
        <PortalSection v-if="mySystems.length" title="My Systems" description="Systems you have a role in.">
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <PortalCard
                    v-for="application in mySystems"
                    :key="application.id"
                    :href="`/applications/${application.id}`"
                    :icon="application.icon || 'grid'"
                    :title="application.name"
                    :maintenance="application.under_maintenance"
                    :subtitle="application.role"
                />
            </div>
        </PortalSection>

        <SystemDirectory :directory="directory" :division="division" />

        <PortalSection title="Services" view-all-href="/services">
            <div v-if="services.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <PortalCard
                    v-for="service in services"
                    :key="service.id"
                    :href="`/services/${service.id}`"
                    :icon="service.icon || 'clipboard'"
                    :title="service.name"
                    :description="service.description"
                />
            </div>
            <EmptyState
                v-else
                icon="clipboard"
                title="No services available yet"
                description="Services registered in the directory will appear here."
            />
        </PortalSection>

        <PortalSection v-if="hasContact" title="Need help?" description="Contact the office for questions about IMIS.">
            <ul class="space-y-1 text-sm text-text">
                <li v-if="contact.email">
                    Email: <a :href="`mailto:${contact.email}`" class="text-primary underline">{{ contact.email }}</a>
                </li>
                <li v-if="contact.phone">Phone: {{ contact.phone }}</li>
                <li v-if="contact.website">
                    Website:
                    <a :href="contact.website" target="_blank" rel="noopener noreferrer" class="text-primary underline">
                        {{ contact.website }}
                    </a>
                </li>
            </ul>
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
