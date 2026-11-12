<script setup>
import { ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import { confirm } from '../../Composables/useDialog';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * Admin-3: every task the scheduler runs, in plain words, with its last
 * and next run — and, with `scheduled_tasks.manage`, pause, resume and run
 * now. Each task skips work already done, so running one early is safe.
 */
defineProps({
    tasks: { type: Array, required: true },
    canManage: { type: Boolean, default: false },
    lastRun: { type: Object, default: null },
});

const busy = ref(null);
const post = (url, data) => {
    busy.value = data.task;
    router.post(url, data, { preserveScroll: true, onFinish: () => (busy.value = null) });
};

const run = async (task) => {
    if (await confirm({ title: `Run ${task.name} now?`, text: task.description ?? '', confirmText: 'Run now' })) {
        post('/admin/scheduled-tasks/run', { task: task.name });
    }
};

const pause = async (task) => {
    if (
        await confirm({
            title: `Pause ${task.name}?`,
            text: 'It won\'t run on its schedule until you resume it. System Health will flag it as paused.',
            confirmText: 'Pause',
            tone: 'danger',
        })
    ) {
        post('/admin/scheduled-tasks/pause', { task: task.name, reason: null });
    }
};
const resume = (task) => post('/admin/scheduled-tasks/resume', { task: task.name });

const lastState = (task) => {
    if (task.last_run === null) return { label: 'Not run yet', tone: 'neutral' };

    return task.succeeded === false ? { label: 'Last run failed', tone: 'danger' } : { label: 'Last run OK', tone: 'success' };
};
</script>

<template>
    <Head title="Scheduled Tasks" />

    <PageHeader
        title="Scheduled Tasks"
        description="What the IMIS does on its own, and when. Times are office time. Running a task early is safe: each one skips work already done."
        :breadcrumbs="[{ label: 'Home', href: '/admin' }, { label: 'System' }, { label: 'Scheduled Tasks' }]"
    />

    <section v-if="lastRun" class="mb-4 rounded-lg border p-4" :class="lastRun.ok ? 'border-success-border bg-success-bg' : 'border-danger-border bg-danger-bg'" aria-live="polite">
        <h2 class="text-sm font-semibold" :class="lastRun.ok ? 'text-success-fg' : 'text-danger-fg'">
            {{ lastRun.task }} {{ lastRun.ok ? 'finished' : 'failed' }}
        </h2>
        <pre class="mt-2 max-h-48 overflow-auto text-xs whitespace-pre-wrap text-text">{{ lastRun.output }}</pre>
    </section>

    <Card>
        <ul class="divide-y divide-border">
            <li v-for="task in tasks" :key="task.name" class="flex flex-col gap-3 py-4 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0 space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-mono text-sm font-semibold text-text">{{ task.name }}</h2>
                        <Badge v-if="task.paused" tone="warning">Paused</Badge>
                        <Badge v-else :tone="lastState(task).tone">{{ lastState(task).label }}</Badge>
                    </div>
                    <p v-if="task.description" class="text-sm text-text">{{ task.description }}</p>
                    <p class="text-xs text-text-muted">
                        {{ task.when }}
                        <template v-if="!task.paused"> · next {{ formatDateTime(task.next_run) }}</template>
                        <template v-if="task.last_run"> · last ran {{ formatDateTime(task.last_run) }}</template>
                    </p>
                    <p v-if="task.paused" class="text-xs text-warning-fg">
                        Paused {{ formatDateTime(task.paused.at) }}<template v-if="task.paused.by"> by {{ task.paused.by }}</template><template v-if="task.paused.reason">: {{ task.paused.reason }}</template>
                    </p>
                    <p v-if="task.manual" class="text-xs text-text-muted">
                        Run by hand {{ formatDateTime(task.manual.at) }} by {{ task.manual.by }} — {{ task.manual.ok ? 'finished' : 'failed' }}
                    </p>
                </div>

                <div v-if="canManage" class="flex shrink-0 gap-2">
                    <Button size="sm" variant="outline" :loading="busy === task.name" :disabled="busy !== null" @click="run(task)">
                        <Icon name="refresh" size="sm" />
                        Run now
                    </Button>
                    <Button v-if="task.paused" size="sm" variant="secondary" :disabled="busy !== null" @click="resume(task)">
                        <Icon name="check" size="sm" />
                        Resume
                    </Button>
                    <Button v-else size="sm" variant="ghost" :disabled="busy !== null" @click="pause(task)">
                        <Icon name="power" size="sm" />
                        Pause
                    </Button>
                </div>
            </li>
        </ul>
    </Card>
</template>
