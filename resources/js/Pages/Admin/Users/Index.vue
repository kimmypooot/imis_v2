<script setup>
import { ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Table from '../../../Components/UI/Table.vue';
import Badge from '../../../Components/UI/Badge.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Button from '../../../Components/UI/Button.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import Select from '../../../Components/Forms/Select.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    users: { type: Array, required: true },
    filters: { type: Object, required: true },
    statusOptions: { type: Array, required: true },
    roleOptions: { type: Array, required: true },
});

const { can } = useAuthorization();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
const roleId = ref(props.filters.role_id ?? '');

const applyFilters = () => {
    router.get(
        '/users',
        { search: search.value, status: status.value, role_id: roleId.value },
        { preserveState: true, replace: true },
    );
};
</script>

<template>
    <Head title="Users" />

    <PageHeader title="Users" :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Users' }]">
        <template v-if="can('users.create')" #actions>
            <Link href="/users/create">
                <Button>New user</Button>
            </Link>
        </template>
    </PageHeader>

    <form class="mb-4 flex flex-wrap items-end gap-3" @submit.prevent="applyFilters">
        <div class="w-full max-w-xs">
            <TextInput id="search" v-model="search" label="Search" placeholder="Name, username, or email" />
        </div>
        <div class="w-48">
            <label for="status-filter" class="block text-sm font-medium text-text">Status</label>
            <div class="mt-1">
                <Select id="status-filter" v-model="status" placeholder="All statuses" :options="statusOptions" />
            </div>
        </div>
        <div class="w-56">
            <label for="role-filter" class="block text-sm font-medium text-text">Role</label>
            <div class="mt-1">
                <Select
                    id="role-filter"
                    v-model="roleId"
                    placeholder="All roles"
                    :options="roleOptions.map((role) => ({ value: role.id, label: role.display_name }))"
                />
            </div>
        </div>
        <Button type="submit" variant="secondary">Filter</Button>
    </form>

    <Table :empty="users.length === 0">
        <template #empty>
            <EmptyState title="No users found" description="Try a different search or filter." />
        </template>

        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Email</th>
                <th scope="col">Roles</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="user in users" :key="user.id">
                <td>
                    <div class="font-medium text-text">{{ user.name }}</div>
                    <div class="text-text-muted">@{{ user.username }}</div>
                </td>
                <td class="text-text-muted">{{ user.email }}</td>
                <td class="text-text-muted">
                    <span v-if="user.roles.length">{{ user.roles.map((role) => role.display_name).join(', ') }}</span>
                    <span v-else>—</span>
                </td>
                <td>
                    <Badge :tone="user.status === 'active' ? 'success' : 'neutral'">{{ user.status }}</Badge>
                </td>
                <td class="text-right">
                    <Link :href="`/users/${user.id}`" class="font-medium text-primary hover:text-primary-hover">
                        View
                    </Link>
                </td>
            </tr>
        </tbody>
    </Table>
</template>
