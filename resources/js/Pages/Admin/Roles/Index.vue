<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Table from '../../../Components/UI/Table.vue';
import Badge from '../../../Components/UI/Badge.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Button from '../../../Components/UI/Button.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    roles: { type: Array, required: true },
});

const { can } = useAuthorization();
</script>

<template>
    <Head title="Roles" />

    <PageHeader title="Roles" :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Roles' }]">
        <template v-if="can('roles.create')" #actions>
            <Link href="/roles/create">
                <Button>New role</Button>
            </Link>
        </template>
    </PageHeader>

    <Table :empty="roles.length === 0">
        <template #empty>
            <EmptyState title="No roles yet" description="Create the first role to get started." />
        </template>

        <thead>
            <tr>
                <th scope="col">Name</th>
                <th scope="col">Status</th>
                <th scope="col">Permissions</th>
                <th scope="col">Users</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="role in props.roles" :key="role.id">
                <td>
                    <div class="font-medium text-text">{{ role.display_name }}</div>
                    <div class="text-text-muted">{{ role.name }}</div>
                </td>
                <td>
                    <Badge :tone="role.status === 'active' ? 'success' : 'neutral'">{{ role.status }}</Badge>
                </td>
                <td>{{ role.permissions_count }}</td>
                <td>{{ role.users_count }}</td>
                <td class="text-right">
                    <Link
                        v-if="can('roles.update')"
                        :href="`/roles/${role.id}/edit`"
                        class="font-medium text-primary hover:text-primary-hover"
                    >
                        Edit
                    </Link>
                </td>
            </tr>
        </tbody>
    </Table>
</template>
