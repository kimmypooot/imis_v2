<script setup>
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '@cscro8/ui/Components/UI/PageHeader.vue';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import FilterButtons from '@cscro8/ui/Components/UI/FilterButtons.vue';
import StatTile from '@cscro8/ui/Components/UI/StatTile.vue';
import DataTable from '@cscro8/ui/Components/UI/DataTable.vue';
import Badge from '@cscro8/ui/Components/UI/Badge.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import ActionButton from '@cscro8/ui/Components/UI/ActionButton.vue';
import EmptyState from '@cscro8/ui/Components/UI/EmptyState.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import PersonCell from '@cscro8/ui/Components/UI/PersonCell.vue';
import RecordModal from '../../../Components/Admin/RecordModal.vue';
import { committeeDefaults, committeeFields, committeeNullable } from '../../../Components/Admin/committeeFields';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { confirm } from '@cscro8/ui/Composables/useDialog.js';
import { formatCalendarDate } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

/**
 * One committee: what it does, who leads it, and its members, current and
 * former. Members are added and edited in a modal; ending a membership
 * keeps it on record under "Former".
 */
const props = defineProps({
    committee: { type: Object, required: true },
    members: { type: Array, required: true },
    userOptions: { type: Array, required: true },
    statusOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

// Positions are free text ("Vice-Chairperson (Director II Representative)"):
// the chair and vice chair lead, then everyone else, then the secretariat.
const rank = (position) => {
    const title = position.toLowerCase();
    if (/vice[\s-]?chair/.test(title)) return 1;
    if (/^(chair|head)/.test(title)) return 0;
    if (title.includes('secretariat')) return 3;
    return 2;
};
const isOfficer = (member) => rank(member.position) < 2;
const ordered = computed(() => [...props.members].sort((a, b) => rank(a.position) - rank(b.position) || a.name.localeCompare(b.name)));
const current = computed(() => ordered.value.filter((member) => member.status === 'active'));
const former = computed(() => ordered.value.filter((member) => member.status !== 'active'));
const officers = computed(() => current.value.filter(isOfficer));

const view = ref('current');
const views = computed(() => [
    { key: 'current', label: `Current (${current.value.length})` },
    { key: 'former', label: `Former (${former.value.length})` },
]);
const shown = computed(() => (view.value === 'current' ? current.value : former.value));

const term = (from, until) => (from || until ? `${from ? formatCalendarDate(from) : 'No start date'} – ${until ? formatCalendarDate(until) : 'present'}` : '—');

const columns = [
    { key: 'name', label: 'Member' },
    { key: 'position', label: 'Position' },
    { key: 'term', label: 'Term', value: (member) => term(member.effective_from, member.effective_until) },
    { key: 'status', label: 'Status', align: 'center' },
    { key: 'actions', label: '', sortable: false, export: false, align: 'center', width: '6rem' },
];

// The committee itself.
const editingCommittee = ref(false);

// Add / edit a membership.
const memberOpen = ref(false);
const editingMember = ref(null);
const memberDefaults = { user_id: '', position: '', status: 'active', effective_from: '', effective_until: '' };
const memberFields = computed(() => [
    ...(editingMember.value
        ? []
        : [{ key: 'user_id', label: 'Person', type: 'select', required: true, span: 'full', options: props.userOptions.map((user) => ({ value: user.id, label: user.name })) }]),
    { key: 'position', label: 'Position', required: true, help: 'e.g. Chairperson, Vice Chairperson, Member, Secretariat' },
    { key: 'status', label: 'Status', type: 'select', required: true, options: props.statusOptions },
    { key: 'effective_from', label: 'Effective from', type: 'date' },
    { key: 'effective_until', label: 'Effective until', type: 'date' },
]);
const addMember = () => {
    editingMember.value = null;
    memberOpen.value = true;
};
const editMember = (member) => {
    editingMember.value = member;
    memberOpen.value = true;
};

const toggleMembership = async (member) => {
    const ending = member.status === 'active';
    const confirmed = await confirm({
        title: ending ? `End ${member.name}'s membership?` : `Reactivate ${member.name}'s membership?`,
        text: ending ? 'They move to Former members; the record is kept.' : 'They move back to Current members.',
        tone: ending ? 'danger' : 'primary',
        confirmText: ending ? 'End membership' : 'Reactivate',
    });

    if (confirmed) {
        router.patch(`/admin/committees/${props.committee.id}/members/${member.id}/status`, { status: ending ? 'inactive' : 'active' }, { preserveScroll: true });
    }
};
</script>

<template>
    <Head :title="committee.name" />

    <PageHeader
        :title="committee.name"
        :breadcrumbs="[
            { label: 'Home', href: '/admin' },
            { label: 'Committees', href: '/admin/committees' },
            { label: committee.short_name || committee.name },
        ]"
    >
        <template v-if="can('committees.update')" #actions>
            <Button variant="secondary" icon="pencil" @click="editingCommittee = true">Edit committee</Button>
            <Button variant="success" @click="addMember"><Icon name="plus" size="sm" />Add member</Button>
        </template>
    </PageHeader>

    <div class="space-y-6">
        <div class="grid gap-4 lg:grid-cols-3">
            <Card title="About this committee" icon="info" class="lg:col-span-2">
                <template #actions>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge v-if="committee.short_name" tone="info">{{ committee.short_name }}</Badge>
                        <StatusBadge :status="committee.status" />
                    </div>
                </template>

                <p v-if="committee.description" class="text-sm leading-relaxed whitespace-pre-line text-text">{{ committee.description }}</p>
                <p v-else class="text-sm text-text-muted">
                    No description yet.
                    <button v-if="can('committees.update')" type="button" class="font-medium text-link hover:underline" @click="editingCommittee = true">Add one</button>
                </p>

                <dl class="mt-5 grid gap-4 border-t border-border pt-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold tracking-wide text-text-muted uppercase">Effective</dt>
                        <dd class="mt-1 flex items-center gap-2 text-sm text-text">
                            <Icon name="calendar" size="sm" class="text-text-muted" />
                            {{ committee.effective_from || committee.effective_until ? term(committee.effective_from, committee.effective_until) : 'Standing committee (no set term)' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold tracking-wide text-text-muted uppercase">Led by</dt>
                        <dd class="mt-1 space-y-2">
                            <PersonCell v-for="officer in officers" :key="officer.id" :name="officer.name" :photo-url="officer.photo_url" :subtitle="officer.position" />
                            <p v-if="!officers.length" class="text-sm text-text-muted">No chairperson named yet.</p>
                        </dd>
                    </div>
                </dl>
            </Card>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                <StatTile label="Current members" :value="current.length" icon="users" tone="primary">serving now</StatTile>
                <StatTile label="Former members" :value="former.length" icon="archive" tone="info">kept on record</StatTile>
            </div>
        </div>

        <Card title="Members" icon="users">
            <template v-if="members.length" #actions>
                <FilterButtons v-model="view" label="Members to show" :options="views.map((tab) => ({ value: tab.key, label: tab.label }))" />
            </template>

            <EmptyState v-if="!members.length" icon="users" title="No members yet" description="Add the people who sit on this committee and their positions.">
                <template v-if="can('committees.update')" #action>
                    <Button variant="success" @click="addMember"><Icon name="plus" size="sm" />Add member</Button>
                </template>
            </EmptyState>

            <DataTable
                v-else
                :columns="columns"
                :rows="shown"
                :caption="`${view === 'current' ? 'Current' : 'Former'} members of ${committee.name}`"
                :export-name="`imis-committee-${committee.short_name || committee.id}-${view}`"
                :empty-text="view === 'current' ? 'No current members.' : 'No former members.'"
            >
                <template #cell-name="{ row }">
                    <PersonCell :name="row.name" :photo-url="row.photo_url" :subtitle="row.subtitle || null" />
                </template>
                <template #cell-position="{ row }">
                    <Badge :tone="isOfficer(row) ? 'info' : 'neutral'">{{ row.position }}</Badge>
                </template>
                <template #cell-term="{ row }"><span class="text-sm">{{ term(row.effective_from, row.effective_until) }}</span></template>
                <template #cell-status="{ row }"><StatusBadge :status="row.status" /></template>
                <template #cell-actions="{ row }">
                    <div v-if="can('committees.update')" class="flex justify-center gap-1">
                        <ActionButton icon="pencil" :label="`Edit ${row.name}'s membership`" @click="editMember(row)" />
                        <ActionButton
                            icon="power"
                            :tone="row.status === 'active' ? 'danger' : 'success'"
                            :label="row.status === 'active' ? `End ${row.name}'s membership` : `Reactivate ${row.name}'s membership`"
                            @click="toggleMembership(row)"
                        />
                    </div>
                </template>
            </DataTable>
        </Card>
    </div>

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

    <RecordModal
        :open="memberOpen"
        noun="Member"
        :record="editingMember"
        :fields="memberFields"
        :defaults="memberDefaults"
        :nullable="['effective_from', 'effective_until']"
        :store-url="`/admin/committees/${committee.id}/members`"
        :update-url="(member) => `/admin/committees/${committee.id}/members/${member.id}`"
        size="md"
        @close="memberOpen = false"
    />
</template>
