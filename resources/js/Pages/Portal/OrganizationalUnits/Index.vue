<script setup>
import { computed, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '../../../Layouts/PortalLayout.vue';
import PageHeader from '../../../Components/UI/PageHeader.vue';
import EmptyState from '../../../Components/UI/EmptyState.vue';
import Icon from '../../../Components/UI/Icon.vue';
import UserAvatar from '../../../Components/UI/UserAvatar.vue';
import TextInput from '../../../Components/Forms/TextInput.vue';

defineOptions({ layout: PortalLayout });

const props = defineProps({
    /** [{ key, label, units: [{ id, name, short_name, description, children_count, employee_count, employees }] }] */
    groups: { type: Array, required: true },
});

// Everything starts open: the type sections and every office.
const closedGroups = ref(new Set());
const openUnits = ref(new Set(props.groups.flatMap((group) => group.units.map((unit) => unit.id))));
const search = ref('');

const flip = (set, key) => {
    const next = new Set(set);
    next.has(key) ? next.delete(key) : next.add(key);

    return next;
};
const toggleGroup = (key) => (closedGroups.value = flip(closedGroups.value, key));
const toggleUnit = (id) => (openUnits.value = flip(openUnits.value, id));

const term = computed(() => search.value.trim().toLowerCase());
const matches = (text) => (text ?? '').toLowerCase().includes(term.value);

// While searching, an office matches by its own name (all its people show)
// or by any person in it (only those people show).
const visibleGroups = computed(() =>
    props.groups
        .map((group) => ({
            ...group,
            units: group.units
                .map((unit) => {
                    if (!term.value) return unit;
                    if (matches(unit.name) || matches(unit.short_name)) return unit;

                    const employees = unit.employees.filter((person) => matches(person.name) || matches(person.position));

                    return employees.length ? { ...unit, employees } : null;
                })
                .filter(Boolean),
        }))
        .filter((group) => group.units.length),
);

const isGroupOpen = (key) => !!term.value || !closedGroups.value.has(key);
const isUnitOpen = (id) => !!term.value || openUnits.value.has(id);

const allUnitIds = computed(() => props.groups.flatMap((group) => group.units.map((unit) => unit.id)));
const allOpen = computed(() => allUnitIds.value.length > 0 && allUnitIds.value.every((id) => openUnits.value.has(id)));

const toggleAll = () => {
    closedGroups.value = new Set();
    openUnits.value = allOpen.value ? new Set() : new Set(allUnitIds.value);
};

const total = (group) => group.units.reduce((sum, unit) => sum + unit.employee_count, 0);
const plural = (count, word) => `${count} ${word}${count === 1 ? '' : 's'}`;
</script>

<template>
    <Head title="Organizational Units" />

    <PageHeader
        title="Organizational Units"
        description="Regional Support Units and Field Offices of CSC RO VIII, and the people serving in each."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'Organizational Units' }]"
    />

    <div v-if="groups.length" class="space-y-5">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div class="w-full sm:max-w-sm">
                <TextInput id="unit-search" v-model="search" label="Find an office or a person" icon="search" />
            </div>
            <button
                v-if="!term"
                type="button"
                class="inline-flex items-center gap-2 self-start rounded-full border border-border bg-surface px-4 py-2 text-sm font-medium text-primary shadow-xs transition-colors hover:border-primary hover:bg-info-bg sm:self-auto"
                @click="toggleAll"
            >
                <Icon :name="allOpen ? 'chevronUp' : 'chevronDown'" size="sm" />
                {{ allOpen ? 'Collapse all offices' : 'Expand all offices' }}
            </button>
        </div>

        <section
            v-for="group in visibleGroups"
            :key="group.key"
            class="overflow-hidden rounded-lg border border-border bg-surface shadow-xs"
        >
            <button
                type="button"
                class="flex w-full items-center gap-3 bg-info-bg/60 px-4 py-3 text-left transition-colors hover:bg-info-bg"
                :aria-expanded="isGroupOpen(group.key)"
                :aria-controls="`group-${group.key}`"
                @click="toggleGroup(group.key)"
            >
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-primary text-white">
                    <Icon name="building" />
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block text-base font-semibold text-primary">{{ group.label }}</span>
                    <span class="block text-xs text-text-muted">
                        {{ plural(group.units.length, 'office') }} · {{ plural(total(group), 'employee') }}
                    </span>
                </span>
                <Icon
                    name="chevronDown"
                    class="shrink-0 text-text-muted transition-transform"
                    :class="{ 'rotate-180': isGroupOpen(group.key) }"
                />
            </button>

            <ul v-show="isGroupOpen(group.key)" :id="`group-${group.key}`" class="divide-y divide-border">
                <li v-for="unit in group.units" :key="unit.id">
                    <button
                        type="button"
                        class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-surface-muted"
                        :aria-expanded="isUnitOpen(unit.id)"
                        :aria-controls="`unit-${unit.id}`"
                        @click="toggleUnit(unit.id)"
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm font-semibold text-text">{{ unit.name }}</span>
                            <span v-if="unit.short_name" class="block truncate text-xs text-text-muted">{{ unit.short_name }}</span>
                        </span>
                        <span class="shrink-0 rounded-full bg-info-bg px-2.5 py-1 text-xs font-medium text-primary">
                            {{ plural(unit.employee_count, 'employee') }}
                        </span>
                        <Icon
                            name="chevronDown"
                            size="sm"
                            class="shrink-0 text-text-muted transition-transform"
                            :class="{ 'rotate-180': isUnitOpen(unit.id) }"
                        />
                    </button>

                    <div v-show="isUnitOpen(unit.id)" :id="`unit-${unit.id}`" class="border-t border-border bg-surface-muted/50 px-4 py-4">
                        <p v-if="unit.description" class="mb-3 text-sm text-text-muted">{{ unit.description }}</p>

                        <ul v-if="unit.employees.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <li
                                v-for="person in unit.employees"
                                :key="person.id"
                                class="group flex items-center gap-3 rounded-lg border border-border bg-surface p-3"
                            >
                                <div
                                    v-if="person.vacant"
                                    class="flex size-12 shrink-0 items-center justify-center rounded-full border border-dashed border-border text-text-muted"
                                >
                                    <Icon name="user" />
                                </div>
                                <UserAvatar v-else :name="person.name" :photo-url="person.profile_photo_url" size="lg" highlight />
                                <div class="min-w-0">
                                    <p class="truncate text-sm font-medium" :class="person.vacant ? 'italic text-text-muted' : 'text-text'">{{ person.name }}</p>
                                    <p v-if="person.position" class="truncate text-xs text-text-muted">{{ person.position }}</p>
                                    <span
                                        v-if="person.role"
                                        class="mt-1 inline-block rounded-full bg-primary px-2 py-px text-2xs font-semibold uppercase tracking-wide text-white"
                                    >
                                        {{ person.role }}
                                    </span>
                                </div>
                            </li>
                        </ul>
                        <p v-else class="text-sm text-text-muted">No employees are assigned to this office yet.</p>

                        <Link
                            :href="`/organizational-units/${unit.id}`"
                            class="mt-4 inline-flex items-center gap-1 text-sm font-medium text-primary hover:text-primary-hover hover:underline"
                        >
                            View systems &amp; services
                            <Icon name="chevronRight" size="sm" />
                        </Link>
                    </div>
                </li>
            </ul>
        </section>

        <EmptyState
            v-if="!visibleGroups.length"
            icon="search"
            title="No match"
            description="No office or person matches what you typed."
        />
    </div>
    <EmptyState
        v-else
        icon="building"
        title="No organizational units available"
        description="Organizational units registered in the directory will appear here."
    />
</template>
