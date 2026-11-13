<script setup>
/**
 * An expanding/collapsing list: the height glides open and shut (a grid row
 * going from 0fr to 1fr, so no height has to be measured) and the hidden
 * content is inert, so it cannot be tabbed to while closed. Renders its
 * slot inside a <ul>; classes and ids given to it land on the <ul>.
 */
defineOptions({ inheritAttrs: false });

defineProps({
    open: { type: Boolean, default: false },
});
</script>

<template>
    <div class="grid transition-[grid-template-rows] duration-200 ease-standard motion-reduce:transition-none" :class="open ? 'grid-rows-[1fr]' : 'grid-rows-[0fr]'">
        <!-- The small negative margin and padding keep focus rings from being clipped. -->
        <div class="-m-0.5 min-h-0 overflow-hidden p-0.5" :inert="!open">
            <ul v-bind="$attrs">
                <slot />
            </ul>
        </div>
    </div>
</template>
