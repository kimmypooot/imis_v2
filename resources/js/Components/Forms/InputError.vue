<script setup>
import Icon from '../UI/Icon.vue';

defineProps({
    message: {
        type: String,
        default: '',
    },
});
</script>

<template>
    <p v-if="message" class="mt-1 flex items-center gap-1 text-sm text-danger-fg" role="alert">
        <Icon name="exclamation" size="sm" class="shrink-0" aria-hidden="true" />
        <span>{{ message }}</span>
    </p>
</template>
