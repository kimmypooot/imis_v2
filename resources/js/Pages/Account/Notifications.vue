<script setup>
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import AccountLayout from '../../Layouts/AccountLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Button from '../../Components/UI/Button.vue';
import Badge from '../../Components/UI/Badge.vue';
import Icon from '../../Components/UI/Icon.vue';

defineOptions({ layout: [PortalLayout, AccountLayout] });

/**
 * Admin-3: which notifications also come by email. Everything always
 * shows under the bell.
 */
const props = defineProps({
    kinds: { type: Array, required: true },
    email: { type: String, default: null },
});

const form = useForm({ email: Object.fromEntries(props.kinds.map((kind) => [kind.kind, kind.email])) });
const save = () => form.put('/account/notifications', { preserveScroll: true, onSuccess: () => form.defaults() });

const enabledCount = computed(() => props.kinds.filter((kind) => form.email[kind.kind]).length);
const setAll = (value) => props.kinds.forEach((kind) => (form.email[kind.kind] = value));
const reset = () => form.reset();
</script>

<template>
    <Head title="Notification Settings" />

    <PageHeader
        title="Notification Settings"
        description="Everything IMIS tells you shows under the bell at the top of the page. Choose what you'd also like by email."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Notification Settings' }]"
    />

    <div class="max-w-3xl space-y-6">
        <!-- Where emails go. -->
        <div
            class="flex items-center gap-4 rounded-lg border p-5"
            :class="email ? 'border-info-border bg-info-bg' : 'border-warning-border bg-warning-bg'"
        >
            <span
                class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-surface"
                :class="email ? 'text-primary' : 'text-warning'"
                aria-hidden="true"
            >
                <Icon :name="email ? 'envelope' : 'exclamation'" />
            </span>
            <div class="min-w-0">
                <p class="text-xs font-medium tracking-wide text-text-muted uppercase">{{ email ? 'Emails are sent to' : 'No email address' }}</p>
                <p class="truncate text-sm font-semibold text-text">
                    {{ email || 'Your account has no email address, so nothing can be emailed.' }}
                </p>
            </div>
            <Badge class="ml-auto hidden shrink-0 sm:inline-flex" :tone="enabledCount && email ? 'success' : 'neutral'">
                {{ email ? `${enabledCount} of ${kinds.length} on` : 'Bell only' }}
            </Badge>
        </div>

        <Card title="Also send by email" icon="bell" description="The bell always shows everything. Switch on the ones you want in your inbox too.">
            <template v-if="email" #actions>
                <button type="button" class="rounded-sm text-xs font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="setAll(true)">Turn all on</button>
                <span class="text-border" aria-hidden="true">|</span>
                <button type="button" class="rounded-sm text-xs font-medium text-primary hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring" @click="setAll(false)">Turn all off</button>
            </template>

            <form @submit.prevent="save">
                <ul class="divide-y divide-border overflow-hidden rounded-lg border border-border">
                    <li v-for="kind in kinds" :key="kind.kind">
                        <label
                            :for="`email-${kind.kind}`"
                            class="flex cursor-pointer items-start gap-4 p-5 transition-colors has-[:checked]:bg-info-bg/40 has-[:disabled]:cursor-not-allowed has-[:disabled]:opacity-60 hover:bg-surface-muted"
                        >
                            <span
                                class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md transition-colors"
                                :class="form.email[kind.kind] && email ? 'bg-info-bg text-primary' : 'bg-surface-muted text-text-muted'"
                                aria-hidden="true"
                            >
                                <Icon :name="form.email[kind.kind] && email ? 'envelope' : 'bell'" size="sm" />
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-medium text-text">{{ kind.label }}</span>
                                <span class="mt-1 block text-sm leading-relaxed text-text-muted">{{ kind.description }}</span>
                            </span>

                            <!-- A real checkbox styled as a switch: keyboard, label and screen reader support come for free. -->
                            <span class="relative mt-1 inline-flex shrink-0 items-center">
                                <input
                                    :id="`email-${kind.kind}`"
                                    v-model="form.email[kind.kind]"
                                    type="checkbox"
                                    role="switch"
                                    :disabled="!email"
                                    class="peer sr-only"
                                />
                                <span class="h-6 w-11 rounded-full bg-border-strong transition-colors duration-200 peer-checked:bg-primary peer-focus-visible:ring-2 peer-focus-visible:ring-focus-ring peer-focus-visible:ring-offset-2" />
                                <span class="pointer-events-none absolute left-0.5 flex h-5 w-5 items-center justify-center rounded-full bg-white text-primary shadow-sm transition-transform duration-200 peer-checked:translate-x-5">
                                    <Icon v-if="form.email[kind.kind]" name="check" size="xs" />
                                </span>
                                <span class="sr-only">{{ form.email[kind.kind] ? 'On' : 'Off' }}</span>
                            </span>
                        </label>
                    </li>
                </ul>

                <p class="mt-5 flex items-start gap-2.5 rounded-md bg-surface-muted px-4 py-3 text-xs text-text-muted">
                    <Icon name="lock" size="xs" class="mt-0.5 shrink-0" />
                    Password reset and set-password emails are always sent, whatever you choose here.
                </p>

                <div class="mt-6 flex flex-wrap items-center gap-4 border-t border-border pt-5">
                    <Button type="submit" :loading="form.processing" :disabled="!form.isDirty || !email">Save changes</Button>
                    <Button v-if="form.isDirty" type="button" variant="secondary" @click="reset">Discard</Button>
                    <p v-if="form.isDirty" class="flex items-center gap-1.5 text-xs text-warning" role="status">
                        <span class="h-1.5 w-1.5 rounded-full bg-warning" aria-hidden="true" />
                        Unsaved changes
                    </p>
                    <p v-else-if="form.recentlySuccessful" class="flex items-center gap-1.5 text-xs text-success" role="status">
                        <Icon name="check" size="xs" />
                        Saved
                    </p>
                </div>
            </form>
        </Card>
    </div>
</template>
