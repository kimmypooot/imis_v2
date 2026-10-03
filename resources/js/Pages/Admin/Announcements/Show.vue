<script setup>
import { computed, ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Button from '../../../Components/UI/Button.vue';
import Alert from '../../../Components/Feedback/Alert.vue';
import ConfirmDialog from '../../../Components/Feedback/ConfirmDialog.vue';
import AnnouncementBody from '../../../Components/Announcements/AnnouncementBody.vue';
import VisibilityBadge from '../../../Components/Announcements/VisibilityBadge.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { formatDate, formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    announcement: { type: Object, required: true },
});

const { can } = useAuthorization();

const visibilityExplanations = {
    live: 'This announcement is currently visible to portal users.',
    scheduled: 'Published, but its publish date has not been reached. It will appear on the portal automatically.',
    expired: 'Published, but its expiration has passed. It is no longer shown on the portal.',
    draft: 'Draft — not visible on the portal until it is published.',
    archived: 'Archived — not visible on the portal. Restore it to a draft to work on it again.',
};

/**
 * Status actions offered for the current status — mirrors
 * AnnouncementStatus::canTransitionTo(); the server re-validates every
 * transition and its permission regardless of what is shown here.
 */
const actions = computed(() => {
    const { status } = props.announcement;
    const list = [];

    if (status === 'draft' && can('announcements.publish')) {
        list.push({
            target: 'published',
            label: 'Publish',
            variant: 'primary',
            danger: false,
            message: props.announcement.publish_at
                ? `It will appear on the portal from ${formatDateTime(props.announcement.publish_at)}.`
                : 'It will appear on the portal immediately.',
        });
    }

    if (status === 'published' && can('announcements.publish')) {
        list.push({
            target: 'draft',
            label: 'Unpublish',
            variant: 'secondary',
            danger: true,
            message: 'It will be removed from the portal and returned to draft.',
        });
    }

    if (status !== 'archived' && can('announcements.archive')) {
        list.push({
            target: 'archived',
            label: 'Archive',
            variant: 'danger',
            danger: true,
            message: 'It will be removed from the portal and moved to the archive.',
        });
    }

    if (status === 'archived' && can('announcements.archive')) {
        list.push({
            target: 'draft',
            label: 'Restore to draft',
            variant: 'secondary',
            danger: false,
            message: 'It will become an editable draft again. It will not be published.',
        });
    }

    return list;
});

const pendingAction = ref(null);
const statusForm = useForm({ status: '' });

const confirmAction = () => {
    statusForm.status = pendingAction.value.target;
    statusForm.patch(`/admin/announcements/${props.announcement.id}/status`, {
        preserveScroll: true,
        onFinish: () => (pendingAction.value = null),
    });
};

const featuredForm = useForm({ featured: false });

const toggleFeatured = () => {
    featuredForm.featured = !props.announcement.featured;
    featuredForm.patch(`/admin/announcements/${props.announcement.id}/featured`, { preserveScroll: true });
};
</script>

<template>
    <Head :title="`Preview — ${announcement.title}`" />

    <PageHeader
        :title="announcement.title"
        :breadcrumbs="[
            { label: 'Home', href: '/admin' },
            { label: 'Announcements', href: '/admin/announcements' },
            { label: announcement.title },
        ]"
    >
        <template #actions>
            <div class="flex flex-wrap gap-2">
                <Link v-if="can('announcements.update')" :href="`/admin/announcements?edit=${announcement.id}`">
                    <Button variant="secondary">Edit</Button>
                </Link>
                <Button
                    v-if="can('announcements.update')"
                    variant="secondary"
                    :loading="featuredForm.processing"
                    @click="toggleFeatured"
                >
                    {{ announcement.featured ? 'Unfeature' : 'Feature' }}
                </Button>
                <Button
                    v-for="action in actions"
                    :key="action.label"
                    :variant="action.variant"
                    @click="pendingAction = action"
                >
                    {{ action.label }}
                </Button>
            </div>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <Alert v-if="statusForm.errors.status" tone="danger">{{ statusForm.errors.status }}</Alert>

        <Alert :tone="announcement.visibility_state === 'live' ? 'success' : 'info'">
            {{ visibilityExplanations[announcement.visibility_state] }}
        </Alert>

        <div class="grid gap-6 lg:grid-cols-3">
            <Card class="lg:col-span-2" title="Portal preview">
                <article>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge v-if="announcement.featured" tone="info">Featured</Badge>
                        <span class="text-sm text-text-muted">
                            {{ announcement.publish_at ? formatDate(announcement.publish_at) : 'Not yet published' }}
                        </span>
                    </div>
                    <h2 class="mt-2 text-xl font-semibold text-text">{{ announcement.title }}</h2>
                    <p v-if="announcement.summary" class="mt-2 text-base text-text-muted">{{ announcement.summary }}</p>
                    <div class="mt-5">
                        <AnnouncementBody :body="announcement.body" />
                    </div>
                </article>
            </Card>

            <Card title="Publication">
                <dl class="space-y-4 text-sm">
                    <div>
                        <dt class="font-medium text-text">On portal</dt>
                        <dd class="mt-1"><VisibilityBadge :state="announcement.visibility_state" /></dd>
                    </div>
                    <div>
                        <dt class="font-medium text-text">Status</dt>
                        <dd class="mt-0.5 capitalize text-text-muted">{{ announcement.status }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-text">Publish date</dt>
                        <dd class="mt-0.5 text-text-muted">
                            {{ formatDateTime(announcement.publish_at) || 'When published' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="font-medium text-text">Expiration</dt>
                        <dd class="mt-0.5 text-text-muted">{{ formatDateTime(announcement.expires_at) || 'None' }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium text-text">Author</dt>
                        <dd class="mt-0.5 text-text-muted">{{ announcement.author ?? '—' }}</dd>
                    </div>
                    <div v-if="announcement.visibility_state === 'live'">
                        <dt class="font-medium text-text">Portal link</dt>
                        <dd class="mt-0.5">
                            <Link
                                :href="`/announcements/${announcement.slug}`"
                                class="font-medium text-primary hover:text-primary-hover"
                            >
                                /announcements/{{ announcement.slug }}
                            </Link>
                        </dd>
                    </div>
                </dl>
            </Card>
        </div>
    </div>

    <ConfirmDialog
        :open="pendingAction !== null"
        :title="pendingAction ? `${pendingAction.label} this announcement?` : ''"
        :danger="pendingAction?.danger ?? false"
        :confirm-label="pendingAction?.label ?? 'Confirm'"
        :processing="statusForm.processing"
        @confirm="confirmAction"
        @cancel="pendingAction = null"
    >
        {{ pendingAction?.message }}
    </ConfirmDialog>
</template>
