<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import { timeAgo } from '../../Utils/datetime';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import Button from '../../Components/UI/Button.vue';
import Table from '../../Components/UI/Table.vue';
import DataTable from '../../Components/UI/DataTable.vue';
import Tabs from '../../Components/UI/Tabs.vue';
import UserAvatar from '../../Components/UI/UserAvatar.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import Skeleton from '../../Components/UI/Skeleton.vue';
import Spinner from '../../Components/UI/Spinner.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Icon from '../../Components/UI/Icon.vue';
import Dropdown from '../../Components/UI/Dropdown.vue';
import Tooltip from '../../Components/UI/Tooltip.vue';
import StatTile from '../../Components/UI/StatTile.vue';
import ActionButton from '../../Components/UI/ActionButton.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import PasswordInput from '../../Components/Forms/PasswordInput.vue';
import Checkbox from '../../Components/Forms/Checkbox.vue';
import Switch from '../../Components/Forms/Switch.vue';
import Radio from '../../Components/Forms/Radio.vue';
import FormGroup from '../../Components/Forms/FormGroup.vue';
import Select from '../../Components/Forms/Select.vue';
import FileInput from '../../Components/Forms/FileInput.vue';
import Alert from '../../Components/Feedback/Alert.vue';
import Modal from '../../Components/Feedback/Modal.vue';
import ConfirmDialog from '../../Components/Feedback/ConfirmDialog.vue';

defineOptions({ layout: AdminLayout });

// Local-only demo state — nothing here reaches the server.
const demoText = ref('');
const demoPassword = ref('');
const demoChecked = ref(true);
const demoSwitch = ref(true);
const demoRadio = ref('a');
const demoSelect = ref('');
const demoFile = ref(null);
const buttonLoading = ref(false);
const modalOpen = ref(false);
const confirmOpen = ref(false);
const confirmProcessing = ref(false);

const toggleButtonLoading = () => {
    buttonLoading.value = true;
    setTimeout(() => (buttonLoading.value = false), 1200);
};

const runConfirm = () => {
    confirmProcessing.value = true;
    setTimeout(() => {
        confirmProcessing.value = false;
        confirmOpen.value = false;
    }, 800);
};

const demoTab = ref('one');
const demoTabs = [
    { key: 'one', label: 'Profile', icon: 'user' },
    { key: 'two', label: 'Activity', icon: 'clock', badge: 3 },
];

// A recent time, so the two-line cell shows a real "x ago".
const minutesAgo = (minutes) => new Date(Date.now() - minutes * 60000).toISOString();
const people = [
    { id: 1, name: 'Maria Santos', username: 'msantos', role: 'Administrator', active: true, at: minutesAgo(3) },
    { id: 2, name: 'Juan Dela Cruz', username: 'jdelacruz', role: 'HR Officer', active: false, at: minutesAgo(190) },
];
const personColumns = [
    { key: 'user', label: 'User', value: (row) => `${row.name} ${row.username}` },
    { key: 'role', label: 'Role' },
    { key: 'at', label: 'Last activity', value: (row) => row.at },
];

const demoRows = [
    { id: 1, name: 'Example record A', status: 'active' },
    { id: 2, name: 'Example record B', status: 'pending' },
];
</script>

<template>
    <Head title="Design system" />

    <PageHeader
        title="Design system"
        description="Development reference only — not connected to real business data. Demonstrates every reusable component future modules should build with."
        :breadcrumbs="[{ label: 'Design system' }]"
    />

    <Alert tone="warning" title="Development reference" class="mb-6">
        This page exists to verify the component library, not as an application feature.
    </Alert>

    <div class="space-y-8">
        <Card title="Typography">
            <div class="space-y-2">
                <p class="text-xl font-semibold text-text">Page title — text-xl font-semibold</p>
                <p class="text-lg font-semibold text-text">Section heading — text-lg font-semibold</p>
                <p class="text-sm font-semibold uppercase tracking-wide text-text-muted">
                    Subheading — text-sm font-semibold uppercase
                </p>
                <p class="text-sm text-text">Body text — text-sm text-text</p>
                <p class="text-sm text-text-muted">Supporting text — text-sm text-text-muted</p>
                <p class="text-xs text-text-muted">Helper/label text — text-xs text-text-muted</p>
                <p class="text-sm text-danger">Error text — text-sm text-danger</p>
            </div>
        </Card>

        <Card title="Colors">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <div class="h-12 rounded-md bg-primary"></div>
                    <p class="mt-1 text-xs text-text-muted">primary</p>
                </div>
                <div>
                    <div class="h-12 rounded-md bg-accent"></div>
                    <p class="mt-1 text-xs text-text-muted">accent</p>
                </div>
                <div>
                    <div class="h-12 rounded-md bg-success"></div>
                    <p class="mt-1 text-xs text-text-muted">success</p>
                </div>
                <div>
                    <div class="h-12 rounded-md bg-warning"></div>
                    <p class="mt-1 text-xs text-text-muted">warning</p>
                </div>
                <div>
                    <div class="h-12 rounded-md bg-danger"></div>
                    <p class="mt-1 text-xs text-text-muted">danger</p>
                </div>
                <div>
                    <div class="h-12 rounded-md bg-info"></div>
                    <p class="mt-1 text-xs text-text-muted">info</p>
                </div>
            </div>
        </Card>

        <Card title="Buttons">
            <div class="flex flex-wrap items-center gap-3">
                <Button variant="primary">Primary</Button>
                <Button variant="secondary">Secondary</Button>
                <Button variant="danger">Danger</Button>
                <Button variant="ghost">Ghost</Button>
                <Button variant="link">Link</Button>
                <Button disabled>Disabled</Button>
                <Button :loading="buttonLoading" @click="toggleButtonLoading">
                    {{ buttonLoading ? 'Loading…' : 'Click to load' }}
                </Button>
                <Button variant="ghost" icon-only aria-label="Notifications">
                    <Icon name="info" />
                </Button>
            </div>
        </Card>

        <Card title="Form controls">
            <div class="grid gap-5 sm:grid-cols-2">
                <TextInput id="demo-text" v-model="demoText" label="Text input" />
                <PasswordInput id="demo-password" v-model="demoPassword" label="Password input" />

                <FormGroup id="demo-select" label="Select">
                    <Select
                        id="demo-select"
                        v-model="demoSelect"
                        placeholder="Choose one"
                        :options="[
                            { value: 'one', label: 'Option one' },
                            { value: 'two', label: 'Option two' },
                        ]"
                    />
                </FormGroup>

                <div class="space-y-2">
                    <Checkbox id="demo-checkbox" v-model="demoChecked" label="Checkbox" />
                    <Radio id="demo-radio-a" v-model="demoRadio" name="demo-radio" value="a" label="Radio A" />
                    <Radio id="demo-radio-b" v-model="demoRadio" name="demo-radio" value="b" label="Radio B" />
                </div>

                <FormGroup id="demo-file" label="File input">
                    <FileInput id="demo-file" accept=".pdf" :max-size-mb="5" @update:model-value="(file) => (demoFile = file)" />
                </FormGroup>
            </div>
        </Card>

        <Card title="Shape &amp; elevation" description="Radius and shadow come from tokens. Cards are flat and bordered; only floating layers are elevated.">
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div v-for="r in ['sm', 'md', 'lg', 'xl']" :key="r" class="border border-border-strong bg-surface-muted p-4 text-center text-xs text-text-muted" :class="`rounded-${r}`">rounded-{{ r }}</div>
            </div>
            <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-3">
                <div class="rounded-lg border border-border bg-surface p-4 text-xs text-text-muted">Card: border only</div>
                <div class="rounded-lg border border-border bg-surface p-4 text-xs text-text-muted shadow-menu">Menu: shadow-menu</div>
                <div class="rounded-lg border border-border bg-surface p-4 text-xs text-text-muted shadow-modal">Dialog: shadow-modal</div>
            </div>
        </Card>

        <Card title="Type scale" description="Use these instead of arbitrary text-[…] values.">
            <p class="text-stat font-bold text-text">text-stat · big figures</p>
            <p class="text-base text-text">text-base · body in forms and modals</p>
            <p class="text-sm text-text">text-sm · default body</p>
            <p class="text-compact text-text">text-compact · dense tables and menus</p>
            <p class="text-xs text-text-muted">text-xs · captions and helper text</p>
            <p class="text-2xs text-text-muted">text-2xs · smallest labels and badges</p>
        </Card>

        <Card title="Menus, tooltips &amp; toggles">
            <div class="flex flex-wrap items-start gap-6">
                <Dropdown align="left" panel-class="w-56 py-1">
                    <template #trigger="{ open, toggle, setTrigger, panelId }">
                        <Button :ref="(c) => setTrigger(c?.$el ?? null)" variant="secondary" :aria-expanded="open" :aria-controls="panelId" @click="toggle">
                            Dropdown
                            <Icon name="chevronDown" size="sm" />
                        </Button>
                    </template>
                    <template #default="{ close }">
                        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-compact text-text hover:bg-surface-muted" @click="close">First action</button>
                        <button type="button" class="flex w-full items-center gap-2 px-3 py-2 text-left text-compact text-text hover:bg-surface-muted" @click="close">Second action</button>
                    </template>
                </Dropdown>

                <Tooltip label="Hover or focus me">
                    <Button variant="outline" size="sm">Tooltip</Button>
                </Tooltip>

                <div class="flex gap-2">
                    <ActionButton icon="pencil" label="Edit example" />
                    <ActionButton icon="trash" label="Delete example" tone="danger" />
                </div>

                <Switch id="demo-switch" v-model="demoSwitch" label="Email me about approvals" description="Takes effect immediately." />
            </div>
        </Card>

        <Card title="Stat tiles">
            <div class="grid gap-4 sm:grid-cols-3">
                <StatTile label="Employees" :value="412" icon="users" :progress="82">of 500 plantilla items</StatTile>
                <StatTile label="Vacancies" :value="18" icon="clipboard" tone="warning" />
                <StatTile label="On leave today" :value="9" icon="calendar" tone="success" />
            </div>
        </Card>

        <Card title="Alerts">
            <div class="space-y-3">
                <Alert tone="success" title="Success">Action completed successfully.</Alert>
                <Alert tone="info" title="Information">Here's something worth knowing.</Alert>
                <Alert tone="warning" title="Warning">Double-check this before continuing.</Alert>
                <Alert tone="danger" title="Error" dismissible>Something went wrong.</Alert>
            </div>
        </Card>

        <Card title="Badges">
            <div class="flex flex-wrap gap-2">
                <Badge tone="success">Active</Badge>
                <Badge tone="neutral">Inactive</Badge>
                <Badge tone="warning">Pending</Badge>
                <Badge tone="success">Approved</Badge>
                <Badge tone="danger">Rejected</Badge>
                <Badge tone="neutral">Draft</Badge>
            </div>
        </Card>

        <Card title="Table">
            <Table :empty="false">
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in demoRows" :key="row.id">
                        <td>{{ row.name }}</td>
                        <td><Badge :tone="row.status === 'active' ? 'success' : 'warning'">{{ row.status }}</Badge></td>
                    </tr>
                </tbody>
            </Table>
        </Card>

        <Card title="People &amp; status" description="Avatars fall back to initials and show a spinner while a photo loads.">
            <div class="flex flex-wrap items-center gap-4">
                <UserAvatar name="Maria Santos" size="sm" />
                <UserAvatar name="Maria Santos" size="md" />
                <UserAvatar name="Maria Santos" size="lg" />
                <UserAvatar name="Maria Santos" size="xl" />
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <StatusBadge status="active" />
                <StatusBadge status="expired" />
                <StatusBadge status="inactive" label="Ended by Admin" />
                <StatusBadge status="draft" />
                <span class="rounded-full bg-success-bg px-2 py-0.5 text-xs font-medium text-success">Active</span>
                <span class="rounded-full bg-surface-muted px-2 py-0.5 text-xs font-medium text-text-muted">Idle</span>
                <span class="rounded-full bg-info-bg px-2 py-0.5 text-xs font-medium text-primary">New</span>
            </div>
        </Card>

        <Card title="Data table" description="Search, sort, export and paging; people get an avatar cell and times read as &quot;x ago&quot;.">
            <DataTable :columns="personColumns" :rows="people" row-key="id" caption="Example people" export-name="design-system-example">
                <template #cell-user="{ row }">
                    <div class="flex items-center gap-3">
                        <UserAvatar :name="row.name" size="sm" />
                        <div class="min-w-0">
                            <div class="truncate font-medium text-text">{{ row.name }}</div>
                            <div class="truncate text-xs text-text-muted">@{{ row.username }}</div>
                        </div>
                    </div>
                </template>
                <template #cell-at="{ row }">
                    <div>{{ timeAgo(row.at) }}</div>
                    <span
                        class="mt-1 inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="row.active ? 'bg-success-bg text-success' : 'bg-surface-muted text-text-muted'"
                    >{{ row.active ? 'Active' : 'Idle' }}</span>
                </template>
            </DataTable>
        </Card>

        <Card title="Tabs">
            <Tabs v-model="demoTab" :tabs="demoTabs">
                <template #one><p class="text-sm text-text-muted">Arrow keys, Home and End move between tabs.</p></template>
                <template #two><p class="text-sm text-text-muted">A tab can carry a count badge.</p></template>
            </Tabs>
        </Card>

        <Card title="Loading placeholders">
            <div class="flex items-center gap-4">
                <Spinner size="sm" />
                <Spinner size="md" />
                <Spinner size="lg" />
            </div>
            <div class="mt-4 space-y-2" role="status" aria-label="Loading example">
                <Skeleton class="h-4 w-48" />
                <Skeleton class="h-4 w-72" />
                <Skeleton class="h-4 w-40" />
            </div>
        </Card>

        <Card title="Sidebar palette" description="Navigation sits on the CSC blue; the same tokens apply in every area.">
            <div class="w-60 space-y-1 rounded-lg bg-csc-blue p-3 text-sm text-white">
                <div class="rounded-md bg-white/15 px-3 py-2 font-medium">Active page</div>
                <div class="rounded-md px-3 py-2 text-white/80">Other page</div>
                <div class="flex items-center justify-between rounded-md px-3 py-2 text-white/80">
                    Page with a count
                    <span class="rounded-full bg-accent px-1.5 text-xs font-semibold text-white ring-2 ring-white/40">4</span>
                </div>
            </div>
        </Card>

        <Card title="Empty state">
            <EmptyState title="No records found" description="This is what an empty list looks like." icon="info" />
        </Card>

        <Card title="Loading state">
            <Table loading />
        </Card>

        <Card title="Modal & confirmation">
            <div class="flex flex-wrap gap-3">
                <Button @click="modalOpen = true">Open modal</Button>
                <Button variant="danger" @click="confirmOpen = true">Open confirmation</Button>
            </div>

            <Modal :open="modalOpen" title="Example modal" @close="modalOpen = false">
                <p class="text-sm text-text-muted">
                    This is a demo modal — informational content, or any form, can go here.
                </p>
                <template #footer>
                    <Button variant="secondary" @click="modalOpen = false">Close</Button>
                </template>
            </Modal>

            <ConfirmDialog
                :open="confirmOpen"
                title="Delete example record?"
                confirm-label="Delete"
                :processing="confirmProcessing"
                @confirm="runConfirm"
                @cancel="confirmOpen = false"
            >
                This is a demonstration only — nothing is actually deleted.
            </ConfirmDialog>
        </Card>
    </div>
</template>
