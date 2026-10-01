<script setup>
import Icon from '../UI/Icon.vue';

/**
 * Small inline marker for a link that leaves the portal for a separate
 * application — never relied on alone: the accessible name always comes
 * from the link's own text, this only adds a visual + sr-only hint.
 */
</script>

<template>
    <span class="inline-flex items-center gap-1">
        <Icon name="externalLink" size="sm" />
        <span class="sr-only">(opens another application)</span>
    </span>
</template>
