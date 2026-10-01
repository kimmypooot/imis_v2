<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Badge from '../../Components/UI/Badge.vue';
import Button from '../../Components/UI/Button.vue';
import Table from '../../Components/UI/Table.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Icon from '../../Components/UI/Icon.vue';
import TextInput from '../../Components/Forms/TextInput.vue';
import PasswordInput from '../../Components/Forms/PasswordInput.vue';
import Checkbox from '../../Components/Forms/Checkbox.vue';
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

                <FormGroup id="demo-file" label="File input" helper-text="PDF up to 5MB">
                    <FileInput id="demo-file" accept=".pdf" @update:model-value="(file) => (demoFile = file)" />
                </FormGroup>
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
