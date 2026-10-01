<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../Layouts/AdminLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import PortalCard from '../../Components/Portal/PortalCard.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Badge from '../../Components/UI/Badge.vue';
import Icon from '../../Components/UI/Icon.vue';
import { useAuthorization } from '../../Composables/useAuthorization';
import { adminModuleGroups } from '../../Utils/navigation';

defineOptions({ layout: AdminLayout });

const props = defineProps({
    attention: { type: Array, required: true },
});

const { can, roles, permissions } = useAuthorization();

// Each group with its items filtered to what this admin can actually see —
// groups left with nothing visible are dropped rather than shown empty.
const visibleGroups = computed(() =>
    adminModuleGroups
        .map((group) => ({ ...group, items: group.items.filter((item) => can(item.permission)) }))
        .filter((group) => group.items.length),
);

const hasAnyModule = computed(() => visibleGroups.value.length > 0);

// "New …" shortcuts for every module this admin can create records in.
const shortcuts = computed(() =>
    visibleGroups.value
        .flatMap((group) => group.items)
        .filter((item) => item.create && can(item.create.permission)),
);
</script>

<template>
    <Head title="Administration" />

    <PageHeader
        title="Administration"
        description="Manage the portal, access, and system settings."
        :breadcrumbs="[{ label: 'Administration' }]"
    />

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <section v-for="group in visibleGroups" :key="group.group">
                <h2 class="mb-3 text-sm font-semibold text-text">{{ group.group }}</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <PortalCard
                        v-for="item in group.items"
                        :key="item.href"
                        :href="item.href"
                        :icon="item.icon"
                        :title="item.label"
                        :description="item.description"
                    />
                </div>
            </section>

            <EmptyState
                v-if="!hasAnyModule"
                title="Nothing to manage yet"
                description="You have administrative access, but no management sections are enabled for your account."
            />
        </div>

        <div class="space-y-6">
            <Card>
                <h2 class="mb-3 text-sm font-semibold text-text">Your access</h2>
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-text-muted">Role(s)</dt>
                        <dd class="mt-1 flex flex-wrap gap-1">
                            <Badge v-for="role in roles" :key="role" tone="info">{{ role }}</Badge>
                            <span v-if="!roles.length" class="text-text-muted">None assigned</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">Permissions granted</dt>
                        <dd class="mt-1 font-medium text-text">{{ permissions.length }}</dd>
                    </div>
                </dl>
            </Card>

            <Card v-if="shortcuts.length">
                <h2 class="mb-3 text-sm font-semibold text-text">Shortcuts</h2>
                <ul class="space-y-1">
                    <li v-for="shortcut in shortcuts" :key="shortcut.create.href">
                        <Link
                            :href="shortcut.create.href"
                            class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm font-medium text-primary hover:bg-surface-muted"
                        >
                            <Icon name="chevronRight" size="sm" />
                            {{ shortcut.create.label }}
                        </Link>
                    </li>
                </ul>
            </Card>

            <Card v-if="attention.length">
                <h2 class="mb-3 text-sm font-semibold text-text">Needs attention</h2>
                <ul class="space-y-1">
                    <li v-for="item in attention" :key="item.href">
                        <Link
                            :href="item.href"
                            class="flex items-center justify-between rounded-md px-2 py-1.5 text-sm hover:bg-surface-muted"
                        >
                            <span class="text-text">{{ item.label }}</span>
                            <Badge tone="warning">{{ item.inactiveCount }} inactive</Badge>
                        </Link>
                    </li>
                </ul>
            </Card>
        </div>
    </div>
</template>
