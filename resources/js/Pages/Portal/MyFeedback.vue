<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import AccountLayout from '../../Layouts/AccountLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import Icon from '../../Components/UI/Icon.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: [PortalLayout, AccountLayout] });

/**
 * What you have sent through "Send feedback", with its status and any
 * replies from the administrators. Only your own, never private notes.
 */
const props = defineProps({
    feedback: { type: Array, required: true },
});

const statusTones = { new: 'info', in_review: 'warning', applied: 'success', resolved: 'success', closed: 'neutral' };

const filter = ref('all');

const count = (predicate) => props.feedback.filter(predicate).length;
const stats = computed(() => [
    { key: 'all', label: 'Sent', value: props.feedback.length, icon: 'chat', tile: 'bg-info-bg text-link' },
    { key: 'open', label: 'Open', value: count((item) => ['new', 'in_review'].includes(item.status)), icon: 'clock', tile: 'bg-warning-bg text-warning-fg' },
    { key: 'replied', label: 'Replied', value: count((item) => item.replies.length > 0), icon: 'envelope', tile: 'bg-info-bg text-link' },
    { key: 'done', label: 'Resolved', value: count((item) => ['applied', 'resolved', 'closed'].includes(item.status)), icon: 'check', tile: 'bg-success-bg text-success-fg' },
]);

const matches = {
    all: () => true,
    open: (item) => ['new', 'in_review'].includes(item.status),
    replied: (item) => item.replies.length > 0,
    done: (item) => ['applied', 'resolved', 'closed'].includes(item.status),
};
const visible = computed(() => props.feedback.filter(matches[filter.value]));

const initial = (name) => (name || '?').trim().charAt(0).toUpperCase();
</script>

<template>
    <Head title="My Feedback" />

    <PageHeader
        title="My Feedback"
        description="Feedback, suggestions and recommendations you sent, and the replies to them."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'My Feedback' }]"
    />

    <div v-if="feedback.length" class="space-y-6">
        <!-- Summary tiles double as filters. -->
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4" role="group" aria-label="Filter feedback">
            <button
                v-for="stat in stats"
                :key="stat.key"
                type="button"
                class="group flex items-center gap-3.5 rounded-lg border bg-surface p-4 text-left transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="filter === stat.key ? 'border-primary ring-1 ring-primary' : 'border-border'"
                :aria-pressed="filter === stat.key"
                @click="filter = stat.key"
            >
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md transition-transform duration-200 group-hover:scale-105" :class="stat.tile" aria-hidden="true">
                    <Icon :name="stat.icon" />
                </span>
                <span class="min-w-0">
                    <span class="block text-xl leading-none font-semibold text-text tabular-nums">{{ stat.value }}</span>
                    <span class="mt-1 block text-xs text-text-muted">{{ stat.label }}</span>
                </span>
            </button>
        </div>

        <TransitionGroup v-if="visible.length" tag="div" class="space-y-6" enter-active-class="transition duration-200" enter-from-class="translate-y-1 opacity-0">
            <Card
                v-for="item in visible"
                :id="`feedback-${item.id}`"
                :key="item.id"
                class="scroll-mt-20"
            >
                <template #header>
                    <div class="min-w-0">
                        <h2 class="text-base font-semibold text-text">{{ item.subject }}</h2>
                        <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-xs text-text-muted">
                            <Badge tone="neutral">{{ item.type_label }}</Badge>
                            <span class="inline-flex items-center gap-1">
                                <Icon name="clock" size="xs" />
                                Sent <time :datetime="item.created_at">{{ formatDateTime(item.created_at) }}</time>
                            </span>
                        </p>
                    </div>
                </template>
                <template #actions>
                    <Badge :tone="statusTones[item.status]">{{ item.status_label }}</Badge>
                </template>

                <p class="rounded-md bg-surface-muted p-4 text-sm leading-relaxed whitespace-pre-line text-text">{{ item.message }}</p>

                <div class="mt-6 border-t border-border pt-5">
                    <ul v-if="item.replies.length" class="space-y-4" :aria-label="`Replies to ${item.subject}`">
                        <li v-for="reply in item.replies" :key="reply.id" class="flex gap-3.5">
                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-white" aria-hidden="true">
                                {{ initial(reply.author) }}
                            </span>
                            <div class="min-w-0 flex-1 rounded-lg rounded-tl-none border border-info-border bg-info-bg p-4">
                                <p class="flex flex-wrap items-baseline gap-x-2 text-xs text-text-muted">
                                    <span class="font-semibold text-text">{{ reply.author }}</span>
                                    <time :datetime="reply.created_at">{{ formatDateTime(reply.created_at) }}</time>
                                </p>
                                <p class="mt-1.5 text-sm leading-relaxed whitespace-pre-line text-text">{{ reply.body }}</p>
                            </div>
                        </li>
                    </ul>
                    <p v-else class="flex items-center gap-2 text-xs text-text-muted">
                        <Icon name="clock" size="xs" />
                        No reply yet. We'll notify you under the bell when there is one.
                    </p>
                </div>
            </Card>
        </TransitionGroup>

        <Card v-else>
            <EmptyState icon="funnel" title="Nothing matches this filter" description="Pick another tile above to see the rest of your feedback.">
                <template #action>
                    <button type="button" class="text-sm font-medium text-link hover:underline" @click="filter = 'all'">Show everything</button>
                </template>
            </EmptyState>
        </Card>
    </div>

    <Card v-else>
        <EmptyState
            icon="chat"
            title="Nothing sent yet"
            description="Open your account menu and choose Send feedback to share what works, what doesn't, and what you'd like to see."
        />
    </Card>
</template>
