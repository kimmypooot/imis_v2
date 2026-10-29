<script setup>
import { ref } from 'vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import Badge from '../../../Components/UI/Badge.vue';
import Button from '../../../Components/UI/Button.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import InputError from '../../../Components/Forms/InputError.vue';
import FormGroup from '../../../Components/Forms/FormGroup.vue';
import Select from '../../../Components/Forms/Select.vue';
import ConfirmDialog from '../../../Components/Feedback/ConfirmDialog.vue';
import RecordModal from '../../../Components/Admin/RecordModal.vue';
import { committeeDefaults, committeeFields, committeeNullable } from '../../../Components/Admin/committeeFields';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    committee: { type: Object, required: true },
    members: { type: Array, required: true },
    userOptions: { type: Array, required: true },
    statusOptions: { type: Array, required: true },
});

const { can } = useAuthorization();
const editingCommittee = ref(false);

const addForm = useForm({
    user_id: '',
    position: '',
    effective_from: '',
    effective_until: '',
    status: 'active',
});

const addMember = () => {
    addForm.post(`/admin/committees/${props.committee.id}/members`, {
        onSuccess: () => addForm.reset(),
    });
};

const editingMemberId = ref(null);
const editForm = useForm({ position: '', effective_from: '', effective_until: '', status: 'active' });

const startEdit = (member) => {
    editingMemberId.value = member.id;
    editForm.clearErrors();
    editForm.position = member.position;
    editForm.effective_from = member.effective_from ?? '';
    editForm.effective_until = member.effective_until ?? '';
    editForm.status = member.status;
};

const cancelEdit = () => {
    editingMemberId.value = null;
};

const saveEdit = (member) => {
    editForm.put(`/admin/committees/${props.committee.id}/members/${member.id}`, {
        onSuccess: () => (editingMemberId.value = null),
    });
};

const confirmTarget = ref(null);
const statusForm = useForm({ status: '' });

const requestEndMembership = (member) => {
    confirmTarget.value = member;
};

const confirmStatusChange = () => {
    const member = confirmTarget.value;
    statusForm.status = member.status === 'active' ? 'inactive' : 'active';
    statusForm.patch(`/admin/committees/${props.committee.id}/members/${member.id}/status`, {
        onSuccess: () => (confirmTarget.value = null),
    });
};
</script>

<template>
    <Head :title="committee.name" />

    <PageHeader
        :title="committee.name"
        :breadcrumbs="[
            { label: 'Home', href: '/admin' },
            { label: 'Committees', href: '/admin/committees' },
            { label: committee.name },
        ]"
    >
        <template v-if="can('committees.update')" #actions>
            <Button variant="secondary" @click="editingCommittee = true">Edit committee</Button>
        </template>
    </PageHeader>

    <RecordModal
        :open="editingCommittee"
        noun="Committee"
        :record="committee"
        :fields="committeeFields(statusOptions)"
        :defaults="committeeDefaults"
        :nullable="committeeNullable"
        store-url="/admin/committees"
        :update-url="(record) => `/admin/committees/${record.id}`"
        @close="editingCommittee = false"
    />

    <Card class="mb-6 max-w-2xl">
        <div class="mb-3 flex flex-wrap items-center gap-2">
            <Badge :tone="committee.status === 'active' ? 'success' : 'neutral'">{{ committee.status }}</Badge>
            <span v-if="committee.short_name" class="text-sm text-text-muted">{{ committee.short_name }}</span>
        </div>
        <p v-if="committee.description" class="text-sm text-text-muted">{{ committee.description }}</p>
        <p v-if="committee.effective_from || committee.effective_until" class="mt-2 text-sm text-text-muted">
            Effective {{ committee.effective_from ?? '—' }} to {{ committee.effective_until ?? 'present' }}
        </p>
    </Card>

    <h2 class="mb-3 text-sm font-semibold text-text">Members</h2>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-3 lg:col-span-2">
            <EmptyState v-if="members.length === 0" title="No members yet" description="Add the first member using the form." />

            <Card v-for="member in members" :key="member.id">
                <div v-if="editingMemberId === member.id" class="space-y-4">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <TextInput
                                id="edit-position"
                                v-model="editForm.position"
                                label="Position"
                                required
                                :invalid="!!editForm.errors.position"
                            />
                            <InputError :message="editForm.errors.position" />
                        </div>
                        <FormGroup id="edit-status" label="Status" required :error="editForm.errors.status">
                            <Select id="edit-status" v-model="editForm.status" :options="statusOptions" />
                        </FormGroup>
                        <div>
                            <TextInput
                                id="edit-effective_from"
                                v-model="editForm.effective_from"
                                type="date"
                                label="Effective from"
                                :invalid="!!editForm.errors.effective_from"
                            />
                            <InputError :message="editForm.errors.effective_from" />
                        </div>
                        <div>
                            <TextInput
                                id="edit-effective_until"
                                v-model="editForm.effective_until"
                                type="date"
                                label="Effective until"
                                :invalid="!!editForm.errors.effective_until"
                            />
                            <InputError :message="editForm.errors.effective_until" />
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button :loading="editForm.processing" @click="saveEdit(member)">Save</Button>
                        <Button variant="secondary" @click="cancelEdit">Cancel</Button>
                    </div>
                </div>

                <div v-else class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <div class="font-medium text-text">{{ member.user.name }}</div>
                        <div class="text-sm text-text-muted">{{ member.user.email }}</div>
                        <div class="mt-1 flex flex-wrap items-center gap-2">
                            <Badge tone="info">{{ member.position }}</Badge>
                            <Badge :tone="member.status === 'active' ? 'success' : 'neutral'">{{ member.status }}</Badge>
                        </div>
                        <div class="mt-1 text-sm text-text-muted">
                            {{ member.effective_from ?? 'No start date' }} – {{ member.effective_until ?? 'present' }}
                        </div>
                    </div>

                    <div v-if="can('committees.update')" class="flex shrink-0 gap-2">
                        <Button variant="secondary" @click="startEdit(member)">Edit</Button>
                        <Button
                            :variant="member.status === 'active' ? 'danger' : 'primary'"
                            @click="requestEndMembership(member)"
                        >
                            {{ member.status === 'active' ? 'End membership' : 'Reactivate' }}
                        </Button>
                    </div>
                </div>
            </Card>
        </div>

        <Card v-if="can('committees.update')" title="Add member">
            <form class="space-y-4" @submit.prevent="addMember">
                <FormGroup id="user_id" label="Person" required :error="addForm.errors.user_id">
                    <Select
                        id="user_id"
                        v-model="addForm.user_id"
                        placeholder="Select a person"
                        :options="userOptions.map((user) => ({ value: user.id, label: `${user.name} (${user.email})` }))"
                    />
                </FormGroup>

                <div>
                    <TextInput
                        id="position"
                        v-model="addForm.position"
                        label="Position"
                        placeholder="e.g. Chairperson"
                        required
                        :invalid="!!addForm.errors.position"
                    />
                    <InputError :message="addForm.errors.position" />
                </div>

                <div>
                    <TextInput
                        id="effective_from"
                        v-model="addForm.effective_from"
                        type="date"
                        label="Effective from"
                        :invalid="!!addForm.errors.effective_from"
                    />
                    <InputError :message="addForm.errors.effective_from" />
                </div>

                <div>
                    <TextInput
                        id="effective_until"
                        v-model="addForm.effective_until"
                        type="date"
                        label="Effective until"
                        :invalid="!!addForm.errors.effective_until"
                    />
                    <InputError :message="addForm.errors.effective_until" />
                </div>

                <Button type="submit" :loading="addForm.processing">Add member</Button>
            </form>
        </Card>
    </div>

    <ConfirmDialog
        :open="!!confirmTarget"
        :title="confirmTarget?.status === 'active' ? 'End this membership?' : 'Reactivate this membership?'"
        :danger="confirmTarget?.status === 'active'"
        :confirm-label="confirmTarget?.status === 'active' ? 'End membership' : 'Reactivate'"
        :processing="statusForm.processing"
        @confirm="confirmStatusChange"
        @cancel="confirmTarget = null"
    >
        {{
            confirmTarget?.status === 'active'
                ? `${confirmTarget?.user.name}'s membership will be marked inactive.`
                : `${confirmTarget?.user.name}'s membership will be marked active again.`
        }}
    </ConfirmDialog>
</template>
