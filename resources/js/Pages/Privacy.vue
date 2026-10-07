<script setup>
import { Head, Link, usePage } from '@inertiajs/vue3';
import PortalLayout from '../Layouts/PortalLayout.vue';
import { formatCalendarDate } from '../Utils/datetime';

/**
 * Admin-2: the privacy notice (RA 10173). Signed in, it sits in the
 * portal; signed out (linked from the sign-in page), it stands alone with
 * a way back to sign in. The content comes from PrivacyNotice.
 */
defineOptions({
    layout: (render, page) => (page.props.auth?.user ? render(PortalLayout, () => page) : page),
});

defineProps({
    notice: { type: Object, required: true },
});

const signedIn = !!usePage().props.auth?.user;
</script>

<template>
    <Head title="Privacy Notice" />

    <div :class="signedIn ? '' : 'min-h-dvh bg-surface-muted px-4 py-8 sm:px-6'">
        <article class="mx-auto max-w-3xl rounded-lg border border-border bg-surface p-6 sm:p-8">
            <header class="mb-6 border-b border-border pb-5">
                <div v-if="!signedIn" class="mb-4 flex items-center gap-3">
                    <img src="/images/brand/CSC-IMIS.png" alt="" class="h-10 w-auto" />
                    <p class="text-sm font-semibold text-primary">Integrated Management Information System (IMIS)</p>
                </div>
                <h1 class="text-xl font-semibold text-primary sm:text-2xl">Privacy Notice</h1>
                <p class="mt-1 text-sm text-text-muted">
                    {{ notice.organization }} · Last updated {{ formatCalendarDate(notice.updated) }}
                </p>
                <p class="mt-3 text-sm text-text">
                    This notice explains what personal data the IMIS holds about you, why, who can see it, how it is
                    protected and how long it is kept, as the Data Privacy Act of 2012 (RA 10173) requires.
                </p>
            </header>

            <section v-for="section in notice.sections" :key="section.title" class="mb-6">
                <h2 class="text-base font-semibold text-text">{{ section.title }}</h2>
                <ul class="mt-2 list-disc space-y-1.5 pl-5 text-sm text-text">
                    <li v-for="item in section.items" :key="item">{{ item }}</li>
                </ul>
                <p v-if="signedIn && section.title === 'How it is protected'" class="mt-2 text-sm">
                    <Link href="/my/record-access" class="font-medium text-primary hover:underline">See who accessed your record</Link>
                </p>
            </section>

            <section v-if="notice.extra" class="mb-6">
                <h2 class="text-base font-semibold text-text">From the office</h2>
                <p class="mt-2 text-sm whitespace-pre-line text-text">{{ notice.extra }}</p>
            </section>

            <section class="rounded-md bg-surface-muted p-4">
                <h2 class="text-base font-semibold text-text">Questions and requests</h2>
                <p class="mt-1 text-sm text-text">
                    <template v-if="notice.contact.name">Contact {{ notice.contact.name }}, the Data Protection Officer</template>
                    <template v-else>Contact the Human Resource Division</template><template v-if="notice.contact.email">
                        at <a :href="`mailto:${notice.contact.email}`" class="font-medium text-primary hover:underline">{{ notice.contact.email }}</a></template>.
                </p>
            </section>

            <p v-if="!signedIn" class="mt-6 text-sm">
                <Link href="/login" class="font-medium text-primary hover:underline">Back to sign in</Link>
            </p>
        </article>
    </div>
</template>
