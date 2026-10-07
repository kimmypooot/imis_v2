<script setup>
/**
 * The password rules in force (System Settings → Security), as the server
 * describes them. Shown under a "new password" field.
 */
defineProps({
    id: { type: String, default: undefined },
    requirements: { type: Array, default: () => [] },
});
</script>

<template>
    <div v-if="requirements.length" :id="id" class="mt-1 text-xs text-text-muted">
        <p>Your password needs:</p>
        <ul class="ml-4 list-disc">
            <li v-for="requirement in requirements" :key="requirement">{{ requirement }}</li>
        </ul>
    </div>
</template>
