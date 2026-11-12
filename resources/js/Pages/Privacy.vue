<script setup>
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import PortalLayout from '../Layouts/PortalLayout.vue';
import Icon from '../Components/UI/Icon.vue';
import { formatCalendarDate } from '../Utils/datetime';
import { useBranding } from '../Composables/useBranding';

/**
 * Admin-2: the privacy notice (RA 10173). Signed in, it sits in the
 * portal; signed out (linked from the sign-in page), it stands alone with
 * a way back to sign in. The content comes from PrivacyNotice.
 */
defineOptions({
    layout: (render, page) => (page.props.auth?.user ? render(PortalLayout, () => page) : page),
});

const props = defineProps({
    notice: { type: Object, required: true },
});

const signedIn = !!usePage().props.auth?.user;
const brand = useBranding();

const sectionIcons = {
    'What IMIS holds about you': 'documentText',
    Why: 'info',
    'Who can see it': 'eye',
    'How it is protected': 'lock',
    'How long it is kept': 'clock',
    'Your rights': 'shield',
};

const slugify = (title) => `section-${title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')}`;

const sections = computed(() =>
    props.notice.sections.map((section, index) => ({
        ...section,
        id: slugify(section.title),
        number: index + 1,
        icon: sectionIcons[section.title] || 'documentText',
    })),
);
</script>

<template>
    <Head title="Privacy Notice" />

    <div :class="signedIn ? '' : 'min-h-dvh bg-surface-muted px-4 py-8 sm:px-6'">
        <div class="mx-auto max-w-5xl">
            <!-- Hero -->
            <header class="overflow-hidden rounded-xl border border-border bg-surface shadow-sm">
                <div class="h-1.5 bg-csc-red" />
                <div class="bg-csc-blue px-6 py-8 text-white sm:px-10">
                    <div v-if="!signedIn" class="mb-5 flex items-center gap-3">
                        <span class="flex shrink-0 rounded-lg bg-white p-1.5 shadow-sm">
                            <img :src="brand.app_logo" alt="" class="h-10 w-auto" />
                        </span>
                        <p class="text-sm font-semibold text-white/90">Integrated Management Information System (IMIS)</p>
                    </div>
                    <div class="flex items-start gap-4">
                        <span class="hidden h-12 w-12 shrink-0 items-center justify-center rounded-xl bg-white/15 sm:flex">
                            <Icon name="shield" />
                        </span>
                        <div>
                            <span
                                class="inline-block rounded-full bg-white/15 px-3 py-0.5 text-2xs font-semibold tracking-widest uppercase"
                            >
                                Data Privacy Act of 2012 · RA 10173
                            </span>
                            <h1 class="mt-2 text-2xl font-bold tracking-tight sm:text-3xl">Privacy Notice</h1>
                            <p class="mt-1 text-sm text-white/75">
                                {{ notice.organization }} · Last updated {{ formatCalendarDate(notice.updated) }}
                            </p>
                        </div>
                    </div>
                </div>
                <p class="px-6 py-5 text-sm leading-relaxed text-text sm:px-10">
                    This notice explains what personal data the IMIS holds about you, why, who can see it, how it is
                    protected and how long it is kept, as the Data Privacy Act of 2012 (RA 10173) requires.
                </p>
            </header>

            <div class="mt-6 lg:grid lg:grid-cols-[14rem_1fr] lg:items-start lg:gap-6">
                <!-- Contents -->
                <nav
                    aria-label="Privacy notice contents"
                    class="mb-6 rounded-xl border border-border bg-surface p-4 shadow-sm lg:sticky lg:top-6 lg:mb-0"
                >
                    <p class="mb-2 text-xs font-semibold tracking-wide text-text-muted uppercase">On this page</p>
                    <ol class="space-y-0.5">
                        <li v-for="section in sections" :key="section.id">
                            <a
                                :href="`#${section.id}`"
                                class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-text transition-colors hover:bg-surface-muted hover:text-link"
                            >
                                <span
                                    class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-info-bg text-2xs font-semibold text-link"
                                >
                                    {{ section.number }}
                                </span>
                                {{ section.title }}
                            </a>
                        </li>
                    </ol>
                </nav>

                <div class="space-y-5">
                    <section
                        v-for="section in sections"
                        :id="section.id"
                        :key="section.id"
                        class="scroll-mt-6 rounded-xl border border-border bg-surface p-5 shadow-sm sm:p-6"
                    >
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-info-bg text-link">
                                <Icon :name="section.icon" size="sm" />
                            </span>
                            <h2 class="text-base font-semibold text-text">{{ section.title }}</h2>
                        </div>
                        <ul class="mt-4 space-y-2.5 text-sm leading-relaxed text-text">
                            <li v-for="item in section.items" :key="item" class="flex items-start gap-2.5">
                                <Icon name="check" size="sm" class="mt-0.5 shrink-0 text-csc-red" />
                                <span>{{ item }}</span>
                            </li>
                        </ul>
                        <p v-if="signedIn && section.title === 'How it is protected'" class="mt-4 text-sm">
                            <Link
                                href="/my/record-access"
                                class="inline-flex items-center gap-1.5 rounded-md bg-info-bg px-3 py-1.5 font-medium text-link hover:underline"
                            >
                                <Icon name="eye" size="sm" />
                                See who accessed your record
                            </Link>
                        </p>
                    </section>

                    <section
                        v-if="notice.extra"
                        class="rounded-xl border border-warning-border bg-warning-bg p-5 shadow-sm sm:p-6"
                    >
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-white/70 text-warning-fg">
                                <Icon name="info" size="sm" />
                            </span>
                            <h2 class="text-base font-semibold text-text">From the office</h2>
                        </div>
                        <p class="mt-3 text-sm leading-relaxed whitespace-pre-line text-text">{{ notice.extra }}</p>
                    </section>

                    <!-- Contact -->
                    <section class="rounded-xl border border-border bg-surface p-5 shadow-sm sm:p-6">
                        <div class="flex items-center gap-3">
                            <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-danger-bg text-csc-red">
                                <Icon name="envelope" size="sm" />
                            </span>
                            <h2 class="text-base font-semibold text-text">Questions and requests</h2>
                        </div>
                        <p class="mt-3 text-sm leading-relaxed text-text">
                            <template v-if="notice.contact.name">Contact {{ notice.contact.name }}, the Data Protection Officer</template>
                            <template v-else>Contact the Human Resource Division</template><template v-if="notice.contact.email">
                                at <a :href="`mailto:${notice.contact.email}`" class="font-medium text-link hover:underline">{{ notice.contact.email }}</a></template>.
                        </p>
                    </section>

                    <p v-if="!signedIn" class="text-sm">
                        <Link href="/login" class="inline-flex items-center gap-1.5 font-medium text-link hover:underline">
                            <Icon name="chevronLeft" size="sm" />
                            Back to sign in
                        </Link>
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
