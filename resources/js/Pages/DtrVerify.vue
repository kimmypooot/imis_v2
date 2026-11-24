<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import PortalLayout from '../Layouts/PortalLayout.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import { formatDateTime } from '../Utils/datetime';
import { useBranding } from '../Composables/useBranding';

/**
 * DTR: what the QR code (or code) on a printed Daily Time Record opens.
 * Anyone can check it, signed in or not. It says only whether the sheet is
 * still exactly what the employee signed, with their name, the period and
 * when it was signed: no hours or punches.
 */
defineOptions({
    layout: (render, page) => (page.props.auth?.user ? render(PortalLayout, () => page) : page),
});

const props = defineProps({
    state: { type: String, required: true },
    employee: { type: String, default: null },
    period: { type: String, default: null },
    signed_at: { type: String, default: null },
});

const signedIn = !!usePage().props.auth?.user;
const brand = useBranding();

const states = {
    valid: {
        icon: 'checkCircle',
        title: 'This DTR is valid',
        text: 'It is exactly as the employee signed it.',
        tone: 'bg-success-bg text-success-fg',
    },
    changed: {
        icon: 'exclamation',
        title: 'This DTR has changed since it was signed',
        text: 'The record was adjusted after the employee signed. Do not rely on this printed copy; ask the employee or HRD for the current one.',
        tone: 'bg-warning-bg text-warning-fg',
    },
    replaced: {
        icon: 'exclamation',
        title: 'This copy was replaced',
        text: 'The employee signed this sheet again later. Ask for the newest printed copy.',
        tone: 'bg-warning-bg text-warning-fg',
    },
    not_found: {
        icon: 'close',
        title: 'No signed DTR matches this code',
        text: 'Check that the code was typed or scanned correctly. A DTR that was never signed electronically has no code.',
        tone: 'bg-danger-bg text-danger-fg',
    },
};

const view = computed(() => states[props.state] ?? states.not_found);
</script>

<template>
    <Head title="Validate a DTR" />

    <div :class="signedIn ? '' : 'min-h-dvh bg-surface-muted px-4 py-8 sm:px-6'">
        <div class="mx-auto max-w-xl">
            <div v-if="!signedIn" class="mb-5 flex items-center gap-3">
                <span class="flex shrink-0 rounded-lg bg-white p-1.5 shadow-sm">
                    <img :src="brand.app_logo" alt="" class="h-10 w-auto" />
                </span>
                <p class="text-sm font-semibold text-text">Integrated Management Information System (IMIS)</p>
            </div>

            <section class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
                <div class="flex items-start gap-3 px-6 py-5" :class="view.tone">
                    <Icon :name="view.icon" />
                    <div>
                        <h1 class="text-lg font-semibold">{{ view.title }}</h1>
                        <p class="mt-1 text-sm">{{ view.text }}</p>
                    </div>
                </div>

                <dl v-if="employee" class="divide-y divide-border px-6 text-sm">
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-text-muted">Employee</dt>
                        <dd class="font-medium text-text">{{ employee }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-text-muted">Period</dt>
                        <dd class="font-medium text-text">{{ period }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 py-3">
                        <dt class="text-text-muted">Signed</dt>
                        <dd class="font-medium text-text">{{ formatDateTime(signed_at) }}</dd>
                    </div>
                </dl>

                <p class="border-t border-border px-6 py-4 text-xs text-text-muted">
                    Civil Service Form No. 48, Daily Time Record. This page shows only whether the sheet still matches what was signed. It does not show hours worked.
                    <Link v-if="!signedIn" href="/privacy" class="text-link hover:underline">Privacy notice</Link>
                </p>
            </section>
        </div>
    </div>
</template>
