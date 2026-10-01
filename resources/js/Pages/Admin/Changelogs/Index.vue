<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AdminLayout from '../../../Layouts/AdminLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import Table from '../../../Components/UI/Table.vue';
import Badge from '../../../Components/UI/Badge.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Button from '../../../Components/UI/Button.vue';
import { useAuthorization } from '../../../Composables/useAuthorization';
import { formatDate, formatDateTime } from '../../../Utils/datetime';

defineOptions({ layout: AdminLayout });

defineProps({
    changelogs: { type: Array, required: true },
});

const { can } = useAuthorization();
</script>

<template>
    <Head title="What's New" />

    <PageHeader
        title="What's New"
        description="Release notes. Publishing one pops it up once on every user's dashboard; it also appears on the What's New page."
        :breadcrumbs="[{ label: 'Administration', href: '/admin' }, { label: `What's New` }]"
    >
        <template v-if="can('changelogs.manage')" #actions>
            <Link href="/admin/changelogs/create">
                <Button>New release note</Button>
            </Link>
        </template>
    </PageHeader>

    <Table :empty="changelogs.length === 0">
        <template #empty>
            <EmptyState
                icon="megaphone"
                title="No release notes yet"
                description="Write the first one to tell everyone what changed in IMIS."
            />
        </template>

        <thead>
            <tr>
                <th scope="col">Release</th>
                <th scope="col">Release date</th>
                <th scope="col">Entries</th>
                <th scope="col">Status</th>
                <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
            <tr v-for="changelog in changelogs" :key="changelog.id">
                <td>
                    <div class="font-medium text-text">{{ changelog.title }}</div>
                    <div class="text-text-muted">v{{ changelog.version }}</div>
                </td>
                <td class="text-text-muted">{{ changelog.release_date ? formatDate(changelog.release_date) : '—' }}</td>
                <td class="text-text-muted">{{ changelog.items_count }}</td>
                <td>
                    <Badge :tone="changelog.status === 'published' ? 'success' : 'neutral'">{{ changelog.status }}</Badge>
                    <div v-if="changelog.published_at" class="mt-1 text-xs text-text-muted">
                        {{ formatDateTime(changelog.published_at) }}
                    </div>
                </td>
                <td class="text-right">
                    <Link
                        v-if="can('changelogs.manage')"
                        :href="`/admin/changelogs/${changelog.id}/edit`"
                        class="font-medium text-primary hover:text-primary-hover"
                    >
                        Edit
                    </Link>
                </td>
            </tr>
        </tbody>
    </Table>
</template>
