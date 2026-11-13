<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Icon from './Icon.vue';
import Spinner from './Spinner.vue';

/**
 * The one button component for the app. Presentation/behavior only — it
 * must never know about users, roles, services, or any other future
 * business module. Pass `href` for navigation that should look like a
 * button: it renders an Inertia <Link>, or with `external` a plain <a>
 * opening in a new tab (system launches, printable forms). A disabled one
 * falls back to a disabled <button>, since links can't be disabled.
 */
const props = defineProps({
    variant: {
        type: String,
        default: 'primary',
        validator: (value) => ['primary', 'secondary', 'outline', 'danger', 'success', 'ghost', 'link'].includes(value),
    },
    size: {
        type: String,
        default: 'md',
        validator: (value) => ['sm', 'md', 'lg'].includes(value),
    },
    type: {
        type: String,
        default: 'button',
    },
    href: {
        type: String,
        default: null,
    },
    /** With `href`: a full-page link in a new tab instead of an Inertia visit. */
    external: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    /** An icon name (see Icon) shown before the label. Hidden while loading, where the spinner takes its place. */
    icon: {
        type: String,
        default: null,
    },
    /** An icon name shown after the label (a menu chevron, a forward arrow). */
    iconRight: {
        type: String,
        default: null,
    },
    /** Icon-only button: pass an accessible label since there is no visible text. */
    iconOnly: {
        type: Boolean,
        default: false,
    },
});

const isDisabled = computed(() => props.disabled || props.loading);
const asLink = computed(() => props.href && !isDisabled.value);

const variantClasses = {
    primary: 'ripple bg-primary text-white shadow-sheen hover:bg-primary-hover',
    secondary: 'border border-border bg-surface text-text shadow-xs hover:border-border-strong/60 hover:bg-surface-muted',
    // Toolbar actions (Refresh, Export): quieter than primary, still branded.
    outline: 'border border-primary/40 bg-surface text-link hover:bg-primary hover:text-white',
    danger: 'ripple bg-danger text-white shadow-sheen hover:bg-danger-hover',
    // Legacy "Add …" / "Save" actions were green.
    success: 'ripple bg-success text-white shadow-sheen hover:bg-success-hover',
    ghost: 'text-text hover:bg-surface-muted',
    link: 'text-link hover:text-primary-hover underline-offset-2 hover:underline',
};

const sizeClasses = computed(() => {
    const touch = 'max-sm:min-h-11';

    if (props.variant === 'link') return { sm: 'gap-1.5 text-xs', md: 'gap-2 text-sm', lg: 'gap-2 text-base' }[props.size];
    if (props.iconOnly) return { sm: `p-1.5 text-xs ${touch} max-sm:min-w-11`, md: `p-2 text-sm ${touch} max-sm:min-w-11`, lg: `p-2.5 text-base ${touch} max-sm:min-w-11` }[props.size];

    return { sm: `gap-1.5 px-3 py-1.5 text-xs ${touch}`, md: `gap-2 px-4 py-2 text-sm ${touch}`, lg: `gap-2 px-5 py-2.5 text-base ${touch}` }[props.size];
});

/** Starts the press ripple at the pointer (filled variants only; a CSS animation, so it needs no cleanup). */
const startRipple = (event) => {
    const el = event.currentTarget;

    if (!el.classList.contains('ripple') || isDisabled.value) return;

    const box = el.getBoundingClientRect();
    el.style.setProperty('--ripple-x', `${event.clientX - box.left}px`);
    el.style.setProperty('--ripple-y', `${event.clientY - box.top}px`);
    el.removeAttribute('data-rippling');
    void el.offsetWidth;
    el.setAttribute('data-rippling', '');
};

const iconSize = computed(() => (props.size === 'lg' ? 'md' : 'sm'));

const classes = computed(() => [
    'inline-flex items-center justify-center rounded-md font-medium transition duration-150 ease-standard active:scale-[0.98] focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 disabled:shadow-none disabled:active:scale-100',
    variantClasses[props.variant],
    sizeClasses.value,
]);
</script>

<template>
    <a v-if="asLink && external" :href="href" target="_blank" rel="noopener noreferrer" :class="classes" @pointerdown="startRipple">
        <Icon v-if="icon" :name="icon" :size="iconSize" />
        <slot />
        <Icon v-if="iconRight" :name="iconRight" :size="iconSize" />
    </a>
    <Link v-else-if="asLink" :href="href" :class="classes" @pointerdown="startRipple">
        <Icon v-if="icon" :name="icon" :size="iconSize" />
        <slot />
        <Icon v-if="iconRight" :name="iconRight" :size="iconSize" />
    </Link>
    <button v-else :type="type" :disabled="isDisabled" :aria-busy="loading" :class="classes" @pointerdown="startRipple">
        <Spinner v-if="loading" size="sm" label="" />
        <Icon v-else-if="icon" :name="icon" :size="iconSize" />
        <slot />
        <Icon v-if="iconRight" :name="iconRight" :size="iconSize" />
    </button>
</template>
