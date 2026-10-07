<script setup>
import { computed, ref } from 'vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
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
import SystemDirectory from '../../Components/Portal/SystemDirectory.vue';
import AnnouncementCard from '../../Components/Announcements/AnnouncementCard.vue';
import PortalFooter from '../../Components/Portal/PortalFooter.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    whatsNew: { type: Object, default: null },
    directory: { type: Object, required: true },
    division: { type: String, default: null },
    announcements: { type: Array, required: true },
    maintenanceNotices: { type: Array, required: true },
    services: { type: Array, required: true },
    // From System Settings.
    welcomeMessage: { type: String, default: null },
    // The user's division, shown as a badge beside the greeting.
    userDivision: { type: String, default: null },
    contact: { type: Object, default: () => ({}) },
});

// "What's New": pops up once for the latest unseen release. Closing it
// in any way (button, Escape, backdrop) marks it seen server-side.
const whatsNewOpen = ref(Boolean(props.whatsNew));
const dismissWhatsNew = () => {
    whatsNewOpen.value = false;
    router.post('/whats-new/seen', {}, { preserveScroll: true, preserveState: true });
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

        <PortalFooter :contact="contact" />
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
