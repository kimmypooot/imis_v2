<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Table from '../../Components/UI/Table.vue';
import Badge from '../../Components/UI/Badge.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import Select from '../../Components/Forms/Select.vue';
import FormGroup from '../../Components/Forms/FormGroup.vue';
import InputError from '../../Components/Forms/InputError.vue';
import { confirm } from '../../Composables/useDialog';
import { formatAge, formatTarget, formatValue, targetStatuses } from '../../Components/Executive/format';
import { formatDateTime } from '../../Utils/datetime';

defineOptions({ layout: PortalLayout });

/**
 * Executive Dashboard → Settings (`executive.manage`): connect each
 * system's summary feed (Exec-2), set the target of each figure (Exec-3),
 * and collect the figures now. Alerts and the weekly summary are switched
 * on in System Settings.
 */
const props = defineProps({
    feeds: { type: Array, required: true },
    figures: { type: Array, required: true },
    orphanTargets: { type: Array, required: true },
    newKey: { type: Object, default: null },
    lastCollectedAt: { type: String, default: null },
});

// Summary feeds: each row edits its own address.
const urls = reactive(Object.fromEntries(props.feeds.map((feed) => [feed.id, feed.feed_url ?? ''])));
const feedErrors = ref({});
const saveFeed = (feed) =>
    router.put(`/executive/feeds/${feed.id}`, { feed_url: urls[feed.id] || null }, {
        preserveScroll: true,
        onError: (errors) => (feedErrors.value = { [feed.id]: errors.feed_url }),
        onSuccess: () => (feedErrors.value = {}),
    });
const generateKey = async (feed) => {
    const replacing = feed.has_key;
    if (
        await confirm({
            title: replacing ? `Replace the key for ${feed.name}?` : `Generate a key for ${feed.name}?`,
            text: replacing
                ? 'The old key stops working at once. Until the new key is in the system’s settings, its figures stop updating.'
                : 'The key is shown once. Put it in the system’s settings so it can answer IMIS.',
            confirmText: replacing ? 'Replace key' : 'Generate key',
            tone: replacing ? 'danger' : 'primary',
        })
    ) {
        router.post(`/executive/feeds/${feed.id}/key`, {}, { preserveScroll: true });
    }
};

const copied = ref(false);
const copyKey = async () => {
    await navigator.clipboard.writeText(props.newKey.key);
    copied.value = true;
};

const collecting = ref(false);
const collect = () => {
    collecting.value = true;
    router.post('/executive/collect', {}, { preserveScroll: true, onFinish: () => (collecting.value = false) });
};

// Targets: one figure is edited at a time.
const editing = ref(null);
const form = useForm({ metric: '', target: '', direction: 'higher', tolerance: '10', note: '' });
const edit = (figure) => {
    editing.value = figure.id;
    form.clearErrors();
    form.metric = figure.id;
    form.target = figure.target ? String(figure.target.value) : '';
    form.direction = figure.target?.direction ?? (figure.unit === 'days' ? 'lower' : 'higher');
    form.tolerance = String(figure.target?.tolerance ?? 10);
    form.note = figure.target?.note ?? '';
};
const saveTarget = () => form.put('/executive/targets', { preserveScroll: true, onSuccess: () => (editing.value = null) });
const removeTarget = async (target, label) => {
    if (await confirm({ title: `Remove the target for ${label}?`, text: 'The figure will show without a status.', confirmText: 'Remove', tone: 'danger' })) {
        router.delete(`/executive/targets/${target.id}`, { preserveScroll: true });
    }
};

const directions = [
    { value: 'higher', label: 'Higher is better (at least)' },
    { value: 'lower', label: 'Lower is better (at most)' },
];
const feedTones = { ok: 'success', failed: 'danger' };
</script>

<template>
    <Head title="Executive Dashboard Settings" />

    <PageHeader
        title="Executive Dashboard Settings"
        description="Connect each system's summary feed and set the target for each figure. Alerts and the weekly summary are turned on in System Settings → Executive Dashboard."
        :breadcrumbs="[{ label: 'Executive Dashboard', href: '/executive' }, { label: 'Settings' }]"
    >
        <template #actions>
            <Button variant="outline" size="sm" :loading="collecting" @click="collect">
                <Icon name="refresh" size="sm" />
                Collect now
            </Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <p class="text-xs text-text-muted">
            Last collection:
            <template v-if="lastCollectedAt">
                <time :datetime="lastCollectedAt">{{ formatDateTime(lastCollectedAt) }}</time> ({{ formatAge(lastCollectedAt) }}).
            </template>
            <template v-else>none yet.</template>
            Figures are collected every 30 minutes.
        </p>

        <section v-if="newKey" class="rounded-lg border border-warning-border bg-warning-bg p-4" aria-live="polite">
            <h2 class="text-sm font-semibold text-warning">New key for {{ newKey.name }} — copy it now</h2>
            <p class="mt-1 text-sm text-text">
                It won't be shown again. Put it in {{ newKey.name }}'s settings (<code>IMIS_FEED_KEY</code> in its <code>.env</code>), as
                described in <code>docs/executive-feed.md</code>.
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <code class="rounded-md border border-border bg-surface px-3 py-2 font-mono text-sm break-all text-text">{{ newKey.key }}</code>
                <Button size="sm" variant="secondary" @click="copyKey">
                    <Icon :name="copied ? 'check' : 'copy'" size="sm" />
                    {{ copied ? 'Copied' : 'Copy' }}
                </Button>
            </div>
        </section>

        <Card title="Connected systems" icon="grid" description="Where IMIS reads each system's figures. A system answers with totals only, in the shared summary format; IMIS never connects to its database.">
            <Table :empty="!feeds.length">
                <thead>
                    <tr>
                        <th>System</th>
                        <th class="min-w-72">Summary feed address</th>
                        <th>Key</th>
                        <th>Last collection</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="feed in feeds" :key="feed.id">
                        <td class="font-medium">{{ feed.name }}</td>
                        <td>
                            <form class="flex items-start gap-2" @submit.prevent="saveFeed(feed)">
                                <div class="flex-1">
                                    <label :for="`feed-${feed.id}`" class="sr-only">Summary feed address for {{ feed.name }}</label>
                                    <input
                                        :id="`feed-${feed.id}`"
                                        v-model="urls[feed.id]"
                                        type="url"
                                        placeholder="https://system.example/api/imis/summary"
                                        class="form-control block w-full px-3 py-1.5"
                                        :aria-invalid="!!feedErrors[feed.id]"
                                    />
                                    <InputError :message="feedErrors[feed.id]" />
                                </div>
                                <Button type="submit" size="sm" variant="secondary" :disabled="(urls[feed.id] || '') === (feed.feed_url || '')">Save</Button>
                            </form>
                        </td>
                        <td>
                            <Button v-if="feed.feed_url" size="sm" :variant="feed.has_key ? 'ghost' : 'primary'" @click="generateKey(feed)">
                                <Icon name="key" size="sm" />
                                {{ feed.has_key ? 'Replace key' : 'Generate key' }}
                            </Button>
                            <span v-else class="text-xs text-text-muted">Set the address first</span>
                        </td>
                        <td>
                            <template v-if="feed.last">
                                <Badge :tone="feedTones[feed.last.status] ?? 'neutral'">{{ feed.last.status === 'ok' ? 'Received' : 'Failed' }}</Badge>
                                <p class="mt-1 text-xs text-text-muted">{{ formatAge(feed.last.at) }}</p>
                                <p v-if="feed.last.error" class="mt-1 max-w-xs text-xs text-danger">{{ feed.last.error }}</p>
                            </template>
                            <span v-else class="text-xs text-text-muted">{{ feed.feed_url ? 'Not collected yet' : 'Not connected' }}</span>
                        </td>
                    </tr>
                </tbody>
                <template #empty>
                    <p class="px-4 py-8 text-center text-sm text-text-muted">No active systems. Add them under Administration → Manage Systems.</p>
                </template>
            </Table>
        </Card>

        <Card
            title="Targets"
            icon="chartBar"
            description="The target each figure is measured against. Short of the target by no more than the tolerance is “at risk”; further is “behind”. Figures without a target show no status."
        >
            <Table :empty="!figures.length">
                <thead>
                    <tr>
                        <th>Figure</th>
                        <th class="text-right">Now</th>
                        <th>Target</th>
                        <th>Status</th>
                        <th><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="figure in figures" :key="figure.id">
                        <tr>
                            <td>
                                <span class="font-medium">{{ figure.label }}</span>
                                <span class="block text-xs text-text-muted">{{ figure.source }}</span>
                            </td>
                            <td class="text-right whitespace-nowrap">{{ formatValue(figure.value, figure.unit) }}</td>
                            <td>
                                <template v-if="figure.target">
                                    {{ formatTarget(figure.target, figure.unit).replace('Target: ', '') }}
                                    <span class="block text-xs text-text-muted">
                                        At risk within {{ figure.target.tolerance }}%<template v-if="figure.target.updatedBy">
                                            · set by {{ figure.target.updatedBy }}</template>
                                    </span>
                                </template>
                                <span v-else class="text-text-muted">—</span>
                            </td>
                            <td>
                                <Badge v-if="targetStatuses[figure.status]" :tone="targetStatuses[figure.status].tone">{{ targetStatuses[figure.status].label }}</Badge>
                            </td>
                            <td class="text-right whitespace-nowrap">
                                <Button size="sm" variant="ghost" @click="edit(figure)">
                                    <Icon name="pencil" size="sm" />
                                    {{ figure.target ? 'Edit' : 'Set target' }}
                                </Button>
                                <Button v-if="figure.target" size="sm" variant="ghost" @click="removeTarget(figure.target, figure.label)">
                                    <Icon name="trash" size="sm" />
                                    <span class="sr-only">Remove target for {{ figure.label }}</span>
                                </Button>
                            </td>
                        </tr>
                        <tr v-if="editing === figure.id">
                            <td colspan="5" class="bg-surface-muted">
                                <form class="grid gap-3 py-2 sm:grid-cols-2 lg:grid-cols-5 lg:items-end" @submit.prevent="saveTarget">
                                    <div>
                                        <TextInput :id="`target-${figure.id}`" v-model="form.target" type="number" :label="`Target (${figure.unit})`" required :error="form.errors.target" />
                                    </div>
                                    <FormGroup :id="`direction-${figure.id}`" label="Better when" :error="form.errors.direction">
                                        <Select :id="`direction-${figure.id}`" v-model="form.direction" :options="directions" />
                                    </FormGroup>
                                    <div>
                                        <TextInput :id="`tolerance-${figure.id}`" v-model="form.tolerance" type="number" label="At-risk tolerance (%)" required :error="form.errors.tolerance" />
                                    </div>
                                    <div>
                                        <TextInput :id="`note-${figure.id}`" v-model="form.note" label="Basis (optional)" :invalid="!!form.errors.note" />
                                        <InputError :message="form.errors.note || form.errors.metric" />
                                    </div>
                                    <div class="flex gap-2">
                                        <Button type="submit" size="sm" :loading="form.processing">Save</Button>
                                        <Button size="sm" variant="secondary" @click="editing = null">Cancel</Button>
                                    </div>
                                </form>
                            </td>
                        </tr>
                    </template>
                </tbody>
                <template #empty>
                    <p class="px-4 py-8 text-center text-sm text-text-muted">No figures yet — collect them first.</p>
                </template>
            </Table>

            <div v-if="orphanTargets.length" class="mt-4">
                <h3 class="text-sm font-semibold text-text">Targets for figures no longer sent</h3>
                <ul class="mt-2 space-y-1 text-sm">
                    <li v-for="target in orphanTargets" :key="target.id" class="flex items-center justify-between gap-2">
                        <code class="text-xs">{{ target.metric }}</code>
                        <Button size="sm" variant="ghost" @click="removeTarget(target, target.metric)">Remove</Button>
                    </li>
                </ul>
            </div>
        </Card>

        <p class="text-sm">
            <Link href="/executive" class="text-primary hover:underline">Back to the Executive Dashboard</Link>
        </p>
    </div>
</template>
