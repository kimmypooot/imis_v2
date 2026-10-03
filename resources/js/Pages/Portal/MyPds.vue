<script setup>
import { ref } from 'vue';
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import PdsEditor from '../../Components/HR/Pds/PdsEditor.vue';
import PdsStatusCard from '../../Components/HR/Pds/PdsStatusCard.vue';
import PdsPhotoCard from '../../Components/HR/Pds/PdsPhotoCard.vue';
import PdsHistory from '../../Components/HR/Pds/PdsHistory.vue';
import PdsSignDialog from '../../Components/HR/Pds/PdsSignDialog.vue';

defineOptions({ layout: PortalLayout });

/**
 * HR-2 self-service: your own Personal Data Sheet (CS Form No. 212,
 * Revised 2026) — fill it in, e-sign and submit it, and follow HR's
 * verification. Needs an employee record from HR first.
 */
defineProps({
    schema: { type: Object, default: null },
    pds: { type: Object, default: null },
    status: { type: Object, default: null },
    history: { type: Array, default: () => [] },
    photoUrl: { type: String, default: null },
});

const signing = ref(false);
</script>

<template>
    <Head title="My Personal Data Sheet" />

    <PageHeader
        title="My Personal Data Sheet"
        description="CS Form No. 212 (Revised 2026). Keep it complete and current. Indicate N/A if not applicable, and don't abbreviate. Misrepresentation may lead to administrative or criminal cases."
        :breadcrumbs="[{ label: 'My Personal Data Sheet' }]"
    />

    <div v-if="pds" class="space-y-6">
        <div class="grid gap-6 lg:grid-cols-3">
            <PdsStatusCard :status="status" class="lg:col-span-2">
                <template #default="{ canSign }">
                    <a
                        href="/my/pds/print"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-2 rounded-md border border-border bg-surface px-4 py-2 text-sm font-medium text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    >
                        <Icon name="printer" size="sm" />Print<span class="sr-only"> (opens in a new tab)</span>
                    </a>
                    <Button v-if="canSign" @click="signing = true"><Icon name="pencil" size="sm" />Sign and submit</Button>
                </template>
            </PdsStatusCard>
            <PdsPhotoCard :photo-url="photoUrl" url="/my/pds/photo" />
        </div>

        <PdsEditor :schema="schema" :pds="pds" url="/my/pds" manage-links />

        <PdsHistory :history="history" :view-url="(item) => `/my/pds/submissions/${item.id}`" />
    </div>
    <EmptyState
        v-else
        icon="clipboard"
        title="Your employee record isn't set up yet"
        description="HR needs to set up your employee record before you can fill in your Personal Data Sheet. Contact the HR office."
    />

    <PdsSignDialog :open="signing" @close="signing = false" />
</template>
