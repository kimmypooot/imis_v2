<script setup>
import { ref, useId } from 'vue';
import Icon from './Icon.vue';

/**
 * Accessible tabs (WAI-ARIA tabs pattern: arrow keys, Home/End), styled
 * like the legacy Bootstrap nav-tabs used in the IMIS edit modals.
 * Each tab's panel is the slot named after its key.
 *
 *   <Tabs v-model="tab" :tabs="[{ key: 'profile', label: 'Profile', icon: 'user' }]">
 *       <template #profile>…</template>
 *   </Tabs>
 */
const props = defineProps({
    tabs: { type: Array, required: true /** [{ key, label, icon?, badge? }] */ },
    modelValue: { type: String, required: true },
});

const emit = defineEmits(['update:modelValue']);

const id = useId();
const tabRefs = ref([]);

const select = (key, focus = false) => {
    emit('update:modelValue', key);
    if (focus) tabRefs.value[props.tabs.findIndex((tab) => tab.key === key)]?.focus();
};

const onKeydown = (event, index) => {
    const last = props.tabs.length - 1;
    const target = { ArrowRight: index === last ? 0 : index + 1, ArrowLeft: index === 0 ? last : index - 1, Home: 0, End: last }[event.key];

    if (target === undefined) return;
    event.preventDefault();
    select(props.tabs[target].key, true);
};
</script>

<template>
    <div>
        <div role="tablist" class="flex gap-1 overflow-x-auto overflow-y-hidden border-b border-border">
            <button
                v-for="(tab, index) in tabs"
                :id="`${id}-tab-${tab.key}`"
                :key="tab.key"
                ref="tabRefs"
                type="button"
                role="tab"
                :aria-selected="tab.key === modelValue"
                :aria-controls="`${id}-panel-${tab.key}`"
                :tabindex="tab.key === modelValue ? 0 : -1"
                class="-mb-px flex shrink-0 items-center gap-1.5 rounded-t-md border-b-2 px-3 py-2 text-sm font-medium transition-colors hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="tab.key === modelValue ? 'border-primary text-primary' : 'border-transparent text-text-muted hover:border-border-strong/40 hover:text-text'"
                @click="select(tab.key)"
                @keydown="onKeydown($event, index)"
            >
                <Icon v-if="tab.icon" :name="tab.icon" size="sm" />
                {{ tab.label }}
                <span v-if="tab.badge" class="rounded-full bg-danger px-1.5 text-[0.7rem] font-semibold leading-4 text-white">{{ tab.badge }}</span>
            </button>
        </div>

        <div
            v-for="tab in tabs"
            v-show="tab.key === modelValue"
            :id="`${id}-panel-${tab.key}`"
            :key="tab.key"
            role="tabpanel"
            :aria-labelledby="`${id}-tab-${tab.key}`"
            tabindex="0"
            class="pt-4 focus:outline-none"
        >
            <slot :name="tab.key" />
        </div>
    </div>
</template>
