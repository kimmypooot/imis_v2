<script setup>
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import ProgressBar from '@cscro8/ui/Components/UI/ProgressBar.vue';

/**
 * The dashboard's "Get set up" checklist (GettingStarted on the server):
 * what is left of the one-time setup, each with a link to where it's done.
 * The server drops it once everything is done; "Hide for now" puts it away
 * on this device for 30 days, after which it returns if anything is left.
 */
const props = defineProps({
    checklist: { type: Object, required: true /** { done, total, items[] } */ },
});

const HIDE_DAYS = 30;
const storageKey = `imis-getting-started-hidden:${usePage().props.auth.user?.id}`;

const hiddenUntil = Number(typeof localStorage !== 'undefined' ? localStorage.getItem(storageKey) : 0) || 0;
const hidden = ref(hiddenUntil > Date.now());

const hide = () => {
    hidden.value = true;
    localStorage.setItem(storageKey, String(Date.now() + HIDE_DAYS * 24 * 60 * 60 * 1000));
};

const remaining = computed(() => props.checklist.total - props.checklist.done);
</script>

<template>
    <Card
        v-if="!hidden"
        title="Get set up in IMIS"
        icon="checkCircle"
        :description="`${remaining} ${remaining === 1 ? 'thing' : 'things'} left to do once. Each takes a minute or two.`"
        role="region"
        aria-label="Get set up in IMIS"
    >
        <template #actions>
            <Button variant="ghost" size="sm" @click="hide">Hide for now</Button>
        </template>

        <ProgressBar :value="checklist.done" :max="checklist.total" tone="success" size="sm" :label="`${checklist.done} of ${checklist.total} done`" show-label />

        <ul class="mt-4 divide-y divide-border">
            <li v-for="item in checklist.items" :key="item.key" class="flex flex-col gap-3 py-3 first:pt-0 last:pb-0 sm:flex-row sm:items-center">
                <span
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full"
                    :class="item.done ? 'bg-success-bg text-success-fg' : 'border-2 border-dashed border-border-strong text-text-muted'"
                    aria-hidden="true"
                >
                    <Icon v-if="item.done" name="check" size="sm" />
                </span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium" :class="item.done ? 'text-text-muted line-through' : 'text-text'">
                        {{ item.label }}
                        <span class="sr-only">{{ item.done ? '(done)' : '(to do)' }}</span>
                    </p>
                    <p v-if="!item.done" class="mt-0.5 text-xs text-text-muted">{{ item.description }}</p>
                </div>
                <Button v-if="!item.done" :href="item.href" variant="outline" size="sm" icon-right="arrowRight" class="shrink-0 self-start sm:self-auto">{{ item.action }}</Button>
            </li>
        </ul>
    </Card>
</template>
