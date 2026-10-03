<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AdminCard from '../../UI/AdminCard.vue';
import Tabs from '../../UI/Tabs.vue';
import PdsSectionForm from './PdsSectionForm.vue';
import PdsRowsForm from './PdsRowsForm.vue';
import PdsManagedList from './PdsManagedList.vue';
import { formatCalendarDate } from '../../../Utils/datetime';

/**
 * The PDS editor (CS Form No. 212, Revised 2026), driven entirely by the
 * server's PdsSchema: one tab per part of the form, each section saved on
 * its own. Items 1–3, 5 and 15 come from the account and HR-1 record and
 * are shown read-only. Warns before leaving with unsaved changes.
 */
const props = defineProps({
    schema: { type: Object, required: true /** { tabs, sections } */ },
    pds: { type: Object, required: true /** { identity, sections } */ },
    url: { type: String, default: null /** e.g. /my/pds; not needed when readonly */ },
    readonly: { type: Boolean, default: false },
    // Section keys to flag as changed (HR's review of a submission).
    highlight: { type: Array, default: () => [] },
    // Show "Manage in …" links on sections kept elsewhere (own PDS only).
    manageLinks: { type: Boolean, default: false },
});

const tab = ref(props.schema.tabs[0].key);
const dirty = reactive({});

const markDirty = (section, isDirty) => (dirty[section] = isDirty);
const anyDirty = computed(() => Object.values(dirty).some(Boolean));

const tabs = computed(() =>
    props.schema.tabs.map((item) => ({
        ...item,
        // A dot on tabs with unsaved changes (the word is in each section),
        // or "changed" on tabs HR should look at.
        badge: item.sections.some((section) => dirty[section]) ? '•' : item.sections.some((section) => props.highlight.includes(section)) ? 'changed' : null,
    })),
);

const identity = computed(() => [
    ['1. Surname', props.pds.identity.surname],
    ['2. First name', props.pds.identity.first_name],
    ['Middle name', props.pds.identity.middle_name],
    ['Name extension', props.pds.identity.name_extension],
    ['3. Date of birth', props.pds.identity.date_of_birth ? formatCalendarDate(props.pds.identity.date_of_birth) : null],
    ['5. Sex at birth', props.pds.identity.sex_at_birth ? props.pds.identity.sex_at_birth[0].toUpperCase() + props.pds.identity.sex_at_birth.slice(1) : null],
    ['15. Agency employee no.', props.pds.identity.agency_employee_no],
]);

// Don't lose unsaved work: warn on leaving the page or navigating away.
const onBeforeUnload = (event) => {
    if (!anyDirty.value) return;
    event.preventDefault();
    event.returnValue = '';
};

let removeBeforeListener;

onMounted(() => {
    window.addEventListener('beforeunload', onBeforeUnload);
    removeBeforeListener = router.on('before', (event) => {
        if (event.detail.visit.method === 'get' && anyDirty.value && !window.confirm('You have unsaved PDS changes. Leave without saving?')) {
            event.preventDefault();
        }
    });
});

onBeforeUnmount(() => {
    window.removeEventListener('beforeunload', onBeforeUnload);
    removeBeforeListener?.();
});
</script>

<template>
    <div class="space-y-6">
        <AdminCard
            title="Name and identity"
            icon="user"
            :subtitle="highlight.includes('identity') ? 'Changed since the last verified PDS.' : 'From the IMIS account and employee record. Ask HR to correct these.'"
        >
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="[label, value] in identity" :key="label">
                    <dt class="text-text-muted">{{ label }}</dt>
                    <dd class="mt-0.5 font-medium text-text">{{ value || '—' }}</dd>
                </div>
            </dl>
        </AdminCard>

        <AdminCard>
            <Tabs v-model="tab" :tabs="tabs">
                <template v-for="item in schema.tabs" :key="item.key" #[item.key]>
                    <div class="space-y-4">
                        <template v-for="sectionKey in item.sections" :key="sectionKey">
                            <PdsManagedList
                                v-if="schema.sections[sectionKey].managed_by"
                                :section="schema.sections[sectionKey]"
                                :rows="pds.sections[sectionKey]"
                                :manage-link="manageLinks"
                            />
                            <PdsRowsForm
                                v-else-if="schema.sections[sectionKey].kind === 'list'"
                                :section="schema.sections[sectionKey]"
                                :rows="pds.sections[sectionKey]"
                                :url="url"
                                :readonly="readonly"
                                @dirty="markDirty"
                            />
                            <PdsSectionForm
                                v-else
                                :section="schema.sections[sectionKey]"
                                :values="pds.sections[sectionKey]"
                                :url="url"
                                :readonly="readonly"
                                @dirty="markDirty"
                            />
                        </template>
                    </div>
                </template>
            </Tabs>
        </AdminCard>
    </div>
</template>
