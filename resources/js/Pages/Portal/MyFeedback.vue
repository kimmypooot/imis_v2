<script setup>
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * What you have sent through "Send feedback", with its status and any
 * replies from the administrators. Only your own, never private notes.
 */
defineProps({
    feedback: { type: Array, required: true },
});

const statusTones = { new: 'info', in_review: 'warning', resolved: 'success', closed: 'neutral' };
</script>

<template>
    <Head title="My Feedback" />

    <PageHeader
        title="My Feedback"
        description="Feedback, suggestions and recommendations you sent, and the replies to them."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Feedback' }]"
    />

    <div v-if="feedback.length" class="space-y-4">
        <Card v-for="item in feedback" :id="`feedback-${item.id}`" :key="item.id" class="scroll-mt-20">
            <template #header>
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-text">{{ item.subject }}</h2>
                    <p class="mt-0.5 text-xs text-text-muted">
                        {{ item.type_label }} · sent <time :datetime="item.created_at">{{ formatDateTime(item.created_at) }}</time>
                    </p>
                </div>
            </template>
            <template #actions>
                <Badge :tone="statusTones[item.status]">{{ item.status_label }}</Badge>
            </template>

            <p class="text-sm whitespace-pre-line text-text">{{ item.message }}</p>

            <ul v-if="item.replies.length" class="mt-4 space-y-3 border-t border-border pt-4" :aria-label="`Replies to ${item.subject}`">
                <li v-for="reply in item.replies" :key="reply.id" class="rounded-md bg-info-bg p-3">
                    <p class="text-xs font-medium text-text-muted">
                        {{ reply.author }} · <time :datetime="reply.created_at">{{ formatDateTime(reply.created_at) }}</time>
                    </p>
                    <p class="mt-1 text-sm whitespace-pre-line text-text">{{ reply.body }}</p>
                </li>
            </ul>
            <p v-else class="mt-4 border-t border-border pt-3 text-xs text-text-muted">No reply yet.</p>
        </Card>
    </div>
    <EmptyState
        v-else
        icon="chat"
        title="Nothing sent yet"
        description="Open your account menu and choose Send feedback to share what works, what doesn't, and what you'd like to see."
    />
</template>
