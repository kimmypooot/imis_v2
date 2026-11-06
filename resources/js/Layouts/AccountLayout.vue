<script setup>
// Nested inside PortalLayout (layout: [PortalLayout, AccountLayout]): the
// shared navigation for the self-service account pages.
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '../Components/UI/Icon.vue';

const sections = [
    { label: 'Password', hint: 'Keep your account secure', href: '/account/password', icon: 'lock' },
    { label: 'Notifications', hint: 'Choose what is emailed', href: '/account/notifications', icon: 'bell' },
    { label: 'Record access', hint: 'Who opened your HR record', href: '/my/record-access', icon: 'eye' },
    { label: 'My feedback', hint: 'Your messages and replies', href: '/my/feedback', icon: 'envelope' },
];

const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const isCurrent = (href) => path.value === href || path.value.startsWith(`${href}/`);
</script>

<template>
    <div class="lg:flex lg:items-start lg:gap-10">
        <aside class="mb-8 shrink-0 lg:sticky lg:top-20 lg:mb-0 lg:w-72">
            <nav aria-label="Account settings" class="lg:rounded-lg lg:border lg:border-border lg:bg-surface lg:p-2.5">
                <p class="mb-2 hidden px-3 pt-2 text-2xs font-semibold tracking-wide text-text-muted uppercase lg:block">
                    Account settings
                </p>
                <ul class="-mx-1 flex gap-2 overflow-x-auto px-1 pb-2 lg:mx-0 lg:flex-col lg:gap-1 lg:overflow-visible lg:px-0 lg:pb-0">
                    <li v-for="section in sections" :key="section.href" class="shrink-0">
                        <Link
                            :href="section.href"
                            class="group relative flex items-center gap-3 rounded-md px-3.5 py-2.5 text-compact transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring max-lg:border max-lg:whitespace-nowrap"
                            :class="
                                isCurrent(section.href)
                                    ? 'bg-info-bg font-semibold text-primary max-lg:border-info-border'
                                    : 'text-text-muted hover:bg-surface-muted hover:text-text max-lg:border-border max-lg:bg-surface'
                            "
                            :aria-current="isCurrent(section.href) ? 'page' : undefined"
                        >
                            <span
                                class="absolute inset-y-1.5 left-0 hidden w-0.5 rounded-full bg-accent transition-opacity lg:block"
                                :class="isCurrent(section.href) ? 'opacity-100' : 'opacity-0'"
                                aria-hidden="true"
                            />
                            <span
                                class="flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors max-lg:hidden"
                                :class="isCurrent(section.href) ? 'bg-surface text-primary' : 'bg-surface-muted text-text-muted group-hover:bg-surface group-hover:text-primary'"
                                aria-hidden="true"
                            >
                                <Icon :name="section.icon" size="sm" />
                            </span>
                            <Icon :name="section.icon" size="sm" class="lg:hidden" />
                            <span class="min-w-0">
                                <span class="block leading-tight">{{ section.label }}</span>
                                <span class="hidden text-2xs leading-tight font-normal text-text-muted lg:block">{{ section.hint }}</span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </nav>
        </aside>

        <div class="min-w-0 flex-1">
            <slot />
        </div>
    </div>
</template>
