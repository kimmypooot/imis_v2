<script setup>
// Nested inside PortalLayout (layout: [PortalLayout, AccountLayout]): the
// shared navigation for the self-service account pages.
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import Icon from '../Components/UI/Icon.vue';

const sections = [
    { label: 'Password', href: '/account/password', icon: 'lock' },
    { label: 'Notifications', href: '/account/notifications', icon: 'bell' },
    { label: 'Record access', href: '/my/record-access', icon: 'eye' },
    { label: 'My feedback', href: '/my/feedback', icon: 'envelope' },
];

const page = usePage();
const path = computed(() => page.url.split('?')[0]);
const isCurrent = (href) => path.value === href || path.value.startsWith(`${href}/`);
</script>

<template>
    <div class="lg:flex lg:items-start lg:gap-6">
        <nav aria-label="Account settings" class="mb-4 shrink-0 lg:mb-0 lg:w-52">
            <p class="mb-2 hidden px-3 text-[11px] font-semibold uppercase tracking-wide text-text-muted lg:block">
                Account settings
            </p>
            <ul class="flex gap-1 overflow-x-auto lg:flex-col lg:overflow-visible">
                <li v-for="section in sections" :key="section.href" class="shrink-0">
                    <Link
                        :href="section.href"
                        class="flex items-center gap-2 whitespace-nowrap rounded-md px-3 py-2 text-[13px] hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                        :class="isCurrent(section.href) ? 'bg-surface-muted font-semibold text-text' : 'text-text-muted'"
                        :aria-current="isCurrent(section.href) ? 'page' : undefined"
                    >
                        <Icon :name="section.icon" size="sm" />
                        {{ section.label }}
                    </Link>
                </li>
            </ul>
        </nav>

        <div class="min-w-0 flex-1">
            <slot />
        </div>
    </div>
</template>
