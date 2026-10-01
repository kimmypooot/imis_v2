<script setup>
import { computed, ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Card from '../../../Components/UI/Card.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';
import { groupPermissionsByResource } from '../../../Utils/permissions';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    permissions: { type: Array, required: true },
});

const search = ref('');

const filtered = computed(() => {
    const term = search.value.trim().toLowerCase();

    if (!term) {
        return props.permissions;
    }

    return props.permissions.filter(
        (permission) =>
            permission.name.toLowerCase().includes(term) ||
            permission.display_name.toLowerCase().includes(term) ||
            (permission.description ?? '').toLowerCase().includes(term),
    );
});

const groups = computed(() => groupPermissionsByResource(filtered.value));
</script>

<template>
    <Head title="Permissions" />

    <PageHeader
        title="Permissions"
        description="Read-only in this phase. Permission names are stable identifiers (resource.action); they're attached to roles from the role edit screen."
        :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: 'Permissions' }]"
    />

    <div class="mb-4 max-w-xs">
        <TextInput id="search" v-model="search" label="Search" placeholder="Name or description" />
    </div>

    <EmptyState v-if="permissions.length === 0" title="No permissions yet" />
    <EmptyState v-else-if="groups.length === 0" title="No permissions match your search" />

    <div v-else class="space-y-6">
        <section v-for="group in groups" :key="group.group">
            <h2 class="mb-2 text-sm font-semibold text-text">{{ group.group }}</h2>
            <Card>
                <ul class="divide-y divide-border">
                    <li v-for="permission in group.items" :key="permission.id" class="py-3 first:pt-0 last:pb-0">
                        <div class="font-medium text-text">{{ permission.display_name }}</div>
                        <div class="text-sm text-text-muted">{{ permission.name }}</div>
                        <p v-if="permission.description" class="mt-1 text-sm text-text-muted">
                            {{ permission.description }}
                        </p>
                    </li>
                </ul>
            </Card>
        </section>
    </div>
</template>
