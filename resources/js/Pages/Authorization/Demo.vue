<script setup>
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import StatusBadge from '../../Components/UI/StatusBadge.vue';
import { useAuthorization } from '../../Composables/useAuthorization';

defineOptions({ layout: AdminLayout });

defineProps({
    roles: { type: Array, required: true },
    permissions: { type: Array, required: true },
    exampleRoleChecks: { type: Object, required: true },
    examplePermissionChecks: { type: Object, required: true },
});

// Client-side check, purely for the "frontend behavior" demonstration
// below — every row on this page is rendered from the server-computed
// props above, since the frontend never decides what a user can do.
const { can, hasRole } = useAuthorization();
</script>

<template>
    <Head title="Authorization demo" />

    <PageHeader
        title="Authorization demo"
        description="Proves the roles/permissions architecture end to end. This is not the future admin dashboard — just enough UI to verify the server-side checks work."
        :breadcrumbs="[{ label: 'Authorization demo' }]"
    />

    <div class="grid gap-6 md:grid-cols-2">
        <Card title="Your roles">
            <ul class="space-y-2">
                <li v-for="role in roles" :key="role.id" class="flex items-center justify-between text-sm">
                    <span class="text-text">
                        {{ role.display_name }}
                        <span class="text-text-muted">({{ role.name }})</span>
                    </span>
                    <StatusBadge :status="role.status === 'active' ? 'active' : 'draft'" :label="role.status" />
                </li>
                <li v-if="roles.length === 0" class="text-sm text-text-muted">No roles assigned.</li>
            </ul>
        </Card>

        <Card title="Your permissions" :description="`${permissions.length} granted`">
            <ul class="max-h-80 space-y-2 overflow-y-auto pr-1">
                <li v-for="permission in permissions" :key="permission.id" class="text-sm text-text">
                    <span class="font-medium">{{ permission.display_name }}</span>
                    <span class="text-text-muted"> ({{ permission.name }})</span>
                </li>
                <li v-if="permissions.length === 0" class="text-sm text-text-muted">
                    No permissions granted.
                </li>
            </ul>
        </Card>

        <Card title="Example role checks (server-side)">
            <ul class="space-y-2 text-sm">
                <li v-for="(allowed, role) in exampleRoleChecks" :key="role" class="flex items-center justify-between">
                    <code class="text-text">hasRole('{{ role }}')</code>
                    <StatusBadge :status="allowed ? 'active' : 'inactive'" :label="allowed ? 'true' : 'false'" />
                </li>
            </ul>
        </Card>

        <Card title="Example permission checks (server-side)" :description="`${Object.values(examplePermissionChecks).filter(Boolean).length} of ${Object.keys(examplePermissionChecks).length} allowed`">
            <ul class="max-h-80 space-y-2 overflow-y-auto pr-1 text-sm">
                <li
                    v-for="(allowed, permission) in examplePermissionChecks"
                    :key="permission"
                    class="flex items-center justify-between"
                >
                    <code class="text-text">can('{{ permission }}')</code>
                    <StatusBadge :status="allowed ? 'active' : 'inactive'" :label="allowed ? 'true' : 'false'" />
                </li>
            </ul>
        </Card>

        <Card title="Frontend permission helper (UX only — never the security boundary)" class="md:col-span-2">
            <p class="text-sm text-text">
                <code>can('roles.view')</code> in this component currently evaluates to
                <strong>{{ can('roles.view') }}</strong>, and
                <code>hasRole('super-admin')</code> evaluates to
                <strong>{{ hasRole('super-admin') }}</strong>. These come from the
                <code>useAuthorization()</code> composable reading Inertia shared props — they only
                hide/show UI. The routes for Roles/Permissions are independently protected by
                server-side policies and middleware, so manually visiting them without permission
                still returns a 403.
            </p>
        </Card>
    </div>
</template>
