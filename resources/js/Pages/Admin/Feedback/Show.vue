<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Button from '../../../Components/UI/Button.vue';
import Select from '../../../Components/Forms/Select.vue';
import Textarea from '../../../Components/Forms/Textarea.vue';
import Checkbox from '../../../Components/Forms/Checkbox.vue';
import FormGroup from '../../../Components/Forms/FormGroup.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * One submission: what was sent, the conversation (replies the sender
 * sees, private notes only administrators see), and the status.
 */
const props = defineProps({
    feedback: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const statusTones = { new: 'info', in_review: 'warning', applied: 'success', resolved: 'success', closed: 'neutral' };

// Only same-site paths become links.
const isInternalPath = (path) => path.startsWith('/') && !path.startsWith('//');

const form = useForm({ body: '', is_internal: false });

const send = () =>
    form.post(`/admin/feedback/${props.feedback.id}/replies`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });

const changeStatus = (status) =>
    router.patch(`/admin/feedback/${props.feedback.id}/status`, { status }, { preserveScroll: true });
</script>

<template>
    <Head :title="feedback.subject" />

    <PageHeader
        :title="feedback.subject"
        :description="`${feedback.type_label} from ${feedback.sender?.name ?? 'a former user'}`"
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'User Feedback', href: '/admin/feedback' }, { label: 'Details' }]"
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <Card title="Message" icon="chat">
                <p class="text-sm whitespace-pre-line text-text">{{ feedback.message }}</p>
            </Card>

            <Card title="Conversation" icon="envelope">
                <ul v-if="feedback.replies.length" class="mb-5 space-y-3">
                    <li
                        v-for="reply in feedback.replies"
                        :key="reply.id"
                        class="rounded-md p-3"
                        :class="reply.is_internal ? 'border border-dashed border-warning-border bg-warning-bg' : 'bg-info-bg'"
                    >
                        <p class="text-xs font-medium text-text-muted">
                            {{ reply.author }} · <time :datetime="reply.created_at">{{ formatDateTime(reply.created_at) }}</time>
                            <Badge v-if="reply.is_internal" tone="warning" class="ml-1">Private note</Badge>
                        </p>
                        <p class="mt-1 text-sm whitespace-pre-line text-text">{{ reply.body }}</p>
                    </li>
                </ul>
                <p v-else class="mb-5 text-sm text-text-muted">No replies yet.</p>

                <form v-if="can('feedback.manage')" class="space-y-3" @submit.prevent="send">
                    <FormGroup
                        id="reply-body"
                        :label="form.is_internal ? 'Private note' : 'Reply to sender'"
                        :error="form.errors.body"
                        :helper-text="form.is_internal ? 'Only administrators can see this.' : 'The sender is notified and sees this under My Feedback.'"
                    >
                        <Textarea id="reply-body" v-model="form.body" :rows="4" :invalid="!!form.errors.body" />
                    </FormGroup>
                    <Checkbox id="reply-internal" v-model="form.is_internal" label="Keep as a private note (don't send to the sender)" />
                    <Button type="submit" :loading="form.processing" :disabled="!form.body.trim()">
                        {{ form.is_internal ? 'Save note' : 'Send reply' }}
                    </Button>
                </form>
            </Card>
        </div>

        <div class="space-y-6">
            <Card title="Status" icon="activity">
                <Badge :tone="statusTones[feedback.status]">{{ feedback.status_label }}</Badge>
                <div v-if="can('feedback.manage') && feedback.status !== 'applied'" class="mt-3">
                    <Button variant="secondary" class="w-full" @click="changeStatus('applied')">Mark as applied</Button>
                    <p class="mt-1 text-xs text-text-muted">Use this when the idea is now in the system. The sender is notified.</p>
                </div>
                <div v-if="can('feedback.manage')" class="mt-3">
                    <label for="feedback-status" class="mb-1 block text-xs font-medium text-text-muted">Change status</label>
                    <Select id="feedback-status" :model-value="feedback.status" :options="statusOptions" @update:model-value="changeStatus" />
                </div>
                <p v-if="feedback.handled_by" class="mt-3 text-xs text-text-muted">Last handled by {{ feedback.handled_by }}</p>
            </Card>

            <Card title="Details" icon="info">
                <dl class="space-y-2 text-sm">
                    <div>
                        <dt class="text-xs text-text-muted">Sent by</dt>
                        <dd class="text-text">
                            <template v-if="feedback.sender">{{ feedback.sender.name }} <span class="text-text-muted">(@{{ feedback.sender.username }})</span></template>
                            <template v-else>Former user</template>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-text-muted">Sent</dt>
                        <dd class="text-text">{{ formatDateTime(feedback.created_at) }}</dd>
                    </div>
                    <div v-if="feedback.page_path">
                        <dt class="text-xs text-text-muted">Page they were on</dt>
                        <dd class="break-all text-text">
                            <a v-if="isInternalPath(feedback.page_path)" :href="feedback.page_path" class="text-primary hover:underline">{{ feedback.page_path }}</a>
                            <template v-else>{{ feedback.page_path }}</template>
                        </dd>
                    </div>
                </dl>
            </Card>
        </div>
    </div>
</template>
