<script setup>
import { computed } from 'vue';
import Icon from '../UI/Icon.vue';

/**
 * The dashboard footer: the help desk contacts from System Settings →
 * Contact (each hidden while empty), then the legacy footer's copyright
 * and credits (old-imis inc/footer.php).
 */
const props = defineProps({
    contact: { type: Object, default: () => ({}) },
});

const hasContact = computed(() => Boolean(props.contact.email || props.contact.phone || props.contact.website));
const year = new Date().getFullYear();
const linkClass = 'inline-flex items-center gap-1.5 text-text hover:text-primary hover:underline';
</script>

<template>
    <footer class="border-t border-border pt-6 text-center text-sm text-text-muted">
        <div v-if="hasContact" class="mb-4">
            <p class="mb-2 font-medium text-text">Need help with IMIS? Contact us:</p>
            <ul class="flex flex-wrap items-center justify-center gap-x-6 gap-y-2">
                <li v-if="contact.email">
                    <a :href="`mailto:${contact.email}`" :class="linkClass">
                        <Icon name="envelope" size="sm" />{{ contact.email }}
                    </a>
                </li>
                <li v-if="contact.phone" class="inline-flex items-center gap-1.5 text-text">
                    <Icon name="phone" size="sm" /><span><span class="sr-only">Phone: </span>{{ contact.phone }}</span>
                </li>
                <li v-if="contact.website">
                    <a :href="contact.website" target="_blank" rel="noopener noreferrer" :class="linkClass">
                        <Icon name="globe" size="sm" />{{ contact.website }}<span class="sr-only"> (opens in a new tab)</span>
                    </a>
                </li>
            </ul>
        </div>
        <p class="text-xs">
            <strong class="font-semibold">Copyright © {{ year }} • Civil Service Commission Regional Office VIII</strong>
        </p>
        <p class="text-xs">Developed by Information Technology Group (ITG) • CSC RO VIII</p>
    </footer>
</template>
