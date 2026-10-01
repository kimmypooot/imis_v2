<script setup>
import { computed } from 'vue';

/**
 * The one place announcement bodies are rendered (portal detail page and
 * admin preview). Bodies are plain text: each blank-line-separated block
 * becomes a paragraph, rendered with `{{ }}` interpolation so Vue escapes
 * it. Never switch this to `v-html` — that would turn any stored markup
 * into stored XSS for every portal user.
 */
const props = defineProps({
    body: {
        type: String,
        required: true,
    },
});

const paragraphs = computed(() =>
    props.body
        .split(/\n\s*\n/)
        .map((paragraph) => paragraph.trim())
        .filter(Boolean),
);
</script>

<template>
    <div class="space-y-4 text-sm leading-relaxed text-text">
        <p v-for="(paragraph, index) in paragraphs" :key="index" class="whitespace-pre-line break-words">
            {{ paragraph }}
        </p>
    </div>
</template>
