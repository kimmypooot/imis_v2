<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import Icon from '../UI/Icon.vue';

/**
 * "About IMIS" — the legacy About pop-up, rewritten for the rebuilt
 * platform: what IMIS is, its mission, what it does today, and the stack
 * it now runs on. Static copy apart from the version (config) so it never
 * claims a number the system can't back up.
 */
defineProps({
    open: { type: Boolean, required: true },
});

defineEmits(['close']);

const page = usePage();
const version = computed(() => page.props.app?.version);
const year = new Date().getFullYear();

const features = [
    { icon: 'lock', title: 'One secure sign-in', text: 'A single IMIS account opens the door to every office system you are assigned to.' },
    { icon: 'key', title: 'Role-based access', text: 'Platform roles plus a role inside each system decide what you can see and launch.' },
    { icon: 'search', title: 'Find anything fast', text: 'One search across systems, services, offices, and announcements. Press / to start.' },
    { icon: 'bell', title: 'Always informed', text: 'Announcements, maintenance notices, and release notes reach you on the dashboard.' },
    { icon: 'shield', title: 'Accountable by design', text: 'Administrative changes are recorded in an audit trail that cannot be edited from within IMIS.' },
    { icon: 'users', title: 'Built for everyone', text: 'Works on any device and is built with WCAG accessibility guidelines in mind.' },
];

const stack = ['Laravel', 'Vue 3', 'Inertia.js', 'Tailwind CSS', 'MySQL'];
</script>

<template>
    <Modal :open="open" title="About IMIS" size="xl" @close="$emit('close')">
        <div class="space-y-8">
            <!-- Identity -->
            <div class="flex flex-col items-center gap-4 rounded-lg bg-primary px-6 py-8 text-center text-white sm:flex-row sm:text-left">
                <span class="flex h-16 w-16 shrink-0 items-center justify-center rounded-full bg-white" aria-hidden="true">
                    <img src="/images/brand/csc-logo.png" alt="" class="h-11 w-auto" />
                </span>
                <div>
                    <p class="text-lg leading-snug font-semibold">CSC RO VIII Integrated Management Information System</p>
                    <p class="mt-1 text-sm text-white/75">Civil Service Commission Regional Office VIII · Eastern Visayas</p>
                    <span v-if="version" class="mt-3 inline-block rounded-full bg-white/15 px-3 py-0.5 text-xs font-medium">
                        Version {{ version }}
                    </span>
                </div>
            </div>

            <div class="grid gap-6 md:grid-cols-2">
                <section>
                    <h3 class="text-sm font-semibold text-text">What IMIS is</h3>
                    <p class="mt-2 text-sm leading-relaxed text-text-muted">
                        IMIS is the official digital platform of CSC Regional Office VIII. It gives every staff member one
                        secure place to reach the office's information systems, services, and announcements — so the
                        right tools are a click away instead of scattered across separate sites and logins.
                    </p>
                </section>
                <section>
                    <h3 class="text-sm font-semibold text-text">Our mission</h3>
                    <p class="mt-2 text-sm leading-relaxed text-text-muted">
                        To support CSC RO VIII in delivering responsive, data-driven public service by giving personnel
                        reliable digital tools that simplify their work, protect the integrity of the data entrusted to
                        the office, and put timely information within reach.
                    </p>
                </section>
            </div>

            <section>
                <h3 class="text-sm font-semibold text-text">What you can do</h3>
                <ul class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <li v-for="feature in features" :key="feature.title" class="flex gap-3 rounded-lg border border-border p-3">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                            <Icon :name="feature.icon" size="sm" />
                        </span>
                        <span>
                            <span class="block text-sm font-medium text-text">{{ feature.title }}</span>
                            <span class="mt-0.5 block text-xs leading-relaxed text-text-muted">{{ feature.text }}</span>
                        </span>
                    </li>
                </ul>
            </section>

            <section class="rounded-lg bg-surface-muted p-4">
                <h3 class="text-sm font-semibold text-text">Rebuilt on a modern stack</h3>
                <p class="mt-1 text-sm text-text-muted">
                    IMIS 2 replaces the original PHP site with a single-page application: pages switch instantly
                    without full reloads, and security, access rules, and data handling are enforced on the server.
                </p>
                <ul class="mt-3 flex flex-wrap gap-2" aria-label="Technologies">
                    <li
                        v-for="item in stack"
                        :key="item"
                        class="rounded-full border border-border bg-surface px-3 py-1 text-xs font-medium text-text"
                    >
                        {{ item }}
                    </li>
                </ul>
            </section>

            <dl class="grid gap-4 border-t border-border pt-5 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div>
                    <dt class="text-xs text-text-muted">Developed by</dt>
                    <dd class="font-medium text-text">Information Technology Group (ITG)</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Agency</dt>
                    <dd class="font-medium text-text">Civil Service Commission – Regional Office VIII</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Location</dt>
                    <dd class="font-medium text-text">Government Center, Palo, Leyte</dd>
                </div>
                <div>
                    <dt class="text-xs text-text-muted">Release</dt>
                    <dd class="font-medium text-text">IMIS v{{ version }} · {{ year }}</dd>
                </div>
            </dl>
        </div>

        <template #footer>
            <Button @click="$emit('close')">Got it</Button>
        </template>
    </Modal>
</template>
