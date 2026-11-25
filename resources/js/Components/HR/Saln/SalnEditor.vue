<script setup>
import { computed } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import Switch from '@cscro8/ui/Components/Forms/Switch.vue';
import Radio from '@cscro8/ui/Components/Forms/Radio.vue';
import { formatPeso } from '../../../Utils/money';

/**
 * The SALN editor (2025 SALN Form), shared by My SALN and HRD encoding on
 * someone's behalf. Tables follow the form's columns; each property,
 * liability and business row says whose it is, because the spouse's
 * exclusive property and the children's print on AS-2. Totals here are a
 * preview: the server computes the ones that are saved and printed.
 */
const props = defineProps({
    saln: { type: Object, required: true },
    options: { type: Object, required: true },
    updateUrl: { type: String, required: true },
    generateUrl: { type: String, required: true },
});

const blank = {
    declarant: { family_name: '', first_name: '', middle_initial: '', position: '', agency: '', office_address: '' },
    spouse: null,
    other_spouses: [],
    children: [],
    real_properties: [],
    personal_properties: [],
    liabilities: [],
    no_business_interests: false,
    business_interests: [],
    no_relatives: false,
    relatives: [],
    government_id: { type: '', number: '', issued_on: '' },
};
const start = JSON.parse(JSON.stringify({ ...blank, ...(props.saln.statement ?? {}) }));
delete start.totals;

const form = useForm({
    statement: start,
    oath_mode: props.saln.oath_mode,
    officer_name: props.saln.officer_name ?? '',
    officer_title: props.saln.officer_title ?? '',
});
const s = form.statement;
const locked = computed(() => !props.saln.editable);
const err = (path) => form.errors[`statement.${path}`];
const errorList = computed(() => Object.values(form.errors));

// ── Tables, following the form's columns ───────────────────────────
const owner = { key: 'owner', label: 'Whose', type: 'owner', width: 'w-44' };
const TABLES = {
    real_properties: [
        owner,
        { key: 'description', label: 'Description (lot, house and lot, condominium, improvements)' },
        { key: 'kind', label: 'Kind (residential, commercial, agricultural…)' },
        { key: 'location', label: 'Exact location' },
        { key: 'assessed_value', label: 'Assessed value (or N/A)', type: 'value', width: 'w-32' },
        { key: 'market_value', label: 'Current fair market value (or N/A)', type: 'value', width: 'w-32' },
        { key: 'acquisition_year', label: 'Year acquired', width: 'w-24' },
        { key: 'acquisition_mode', label: 'Mode of acquisition', width: 'w-32' },
        { key: 'acquisition_cost', label: 'Acquisition cost', type: 'money', width: 'w-32' },
    ],
    personal_properties: [
        owner,
        { key: 'description', label: 'Description' },
        { key: 'acquisition_year', label: 'Year acquired', width: 'w-28' },
        { key: 'acquisition_cost', label: 'Acquisition cost / amount', type: 'money', width: 'w-36' },
    ],
    liabilities: [
        owner,
        { key: 'nature', label: 'Nature' },
        { key: 'creditor', label: 'Name of creditor' },
        { key: 'outstanding_balance', label: 'Outstanding balance', type: 'money', width: 'w-36' },
    ],
    business_interests: [
        owner,
        { key: 'name', label: 'Name of entity / business enterprise' },
        { key: 'address', label: 'Business address' },
        { key: 'nature', label: 'Nature of business interest / financial connection' },
        { key: 'acquired_on', label: 'Date of acquisition', width: 'w-32' },
    ],
    relatives: [
        { key: 'name', label: 'Name of relative' },
        { key: 'relationship', label: 'Relationship' },
        { key: 'position', label: 'Position' },
        { key: 'agency_and_address', label: 'Agency/office and address' },
    ],
};
const SECTIONS = [
    { table: 'real_properties', title: 'a. Real Properties', icon: 'building', hint: 'Values as found in the tax declaration. Write N/A where there is none (e.g. a memorial lot). Inherited property: acquisition cost 0.' },
    { table: 'personal_properties', title: 'b. Personal Properties', icon: 'database', hint: 'Cash on hand and in bank, vehicles, jewelry, appliances, insurance paid… Small items of one kind may be grouped, e.g. "Books, various years".' },
    { table: 'liabilities', title: '2. Liabilities', icon: 'clipboard', hint: 'Outstanding balances as of the reckoning date: loans, credit cards, unpaid bills. If you have none, leave it empty: the form prints N/A.' },
];

const addRow = (table) => {
    const row = Object.fromEntries(TABLES[table].map((column) => [column.key, column.key === 'owner' ? 'declarant' : '']));
    s[table].push(row);
};
const removeRow = (table, i) => s[table].splice(i, 1);

const addChild = () => s.children.push({ name: '', birth_date: '', age: '' });
const ageAsOf = (birthDate) => {
    if (!birthDate) return '';
    const [by, bm, bd] = birthDate.split('-').map(Number);
    const [ry, rm, rd] = props.saln.reckoning_date.split('-').map(Number);
    return ry - by - (rm < bm || (rm === bm && rd < bd) ? 1 : 0);
};
const setBirthDate = (child, value) => {
    child.birth_date = value;
    const age = ageAsOf(value);
    if (age !== '') child.age = age;
};

const spouseJobFields = [
    { key: 'position', label: 'Position', placeholder: 'e.g. Teacher III' },
    { key: 'agency', label: 'Agency/office', placeholder: 'e.g. DepEd Leyte Division' },
    { key: 'office_address', label: 'Office address', placeholder: 'e.g. Palo, Leyte' },
];

const hasSpouse = computed({
    get: () => s.spouse !== null,
    set: (value) => {
        s.spouse = value ? { family_name: '', first_name: '', middle_initial: '', in_government: false, position: '', agency: '', office_address: '' } : null;
    },
});

// ── Totals preview (centavos, like the server) ─────────────────────
const centavos = (value) => {
    const match = String(value ?? '').trim().replace(/,/g, '').match(/^(\d+)(?:\.(\d{1,2}))?$/);
    return match ? Number(match[1]) * 100 + Number((match[2] ?? '0').padEnd(2, '0')) : 0;
};
const sum = (rows, key) => rows.reduce((total, row) => total + centavos(row[key]), 0);
const totals = computed(() => {
    const real = sum(s.real_properties, 'acquisition_cost');
    const personal = sum(s.personal_properties, 'acquisition_cost');
    const liabilities = sum(s.liabilities, 'outstanding_balance');
    return { real, personal, assets: real + personal, liabilities, netWorth: real + personal - liabilities };
});
const peso = (cents) => formatPeso(cents / 100);

const swearingOptions = computed(() => props.options.oathModes.filter((mode) => !(mode.value === 'regional_director' && props.saln.swears_before_self)));

const save = (then) =>
    form.transform((data) => ({
        ...data,
        statement: { ...data.statement, children: data.statement.children.map((child) => ({ ...child, birth_date: child.birth_date || null, age: child.age === '' ? null : Number(child.age) })) },
    })).put(props.updateUrl, { preserveScroll: true, onSuccess: () => then?.() });

const printNew = () => save(() => form.post(props.generateUrl, { preserveScroll: true }));
</script>

<template>
    <form class="space-y-6" @submit.prevent="save()">
        <div v-if="locked" class="flex items-start gap-2 rounded-md border border-info-border bg-info-bg px-4 py-3 text-sm text-text">
            <Icon name="lock" class="mt-0.5 h-4 w-4 shrink-0" />
            <p>This SALN is with HRD ({{ saln.status_label.toLowerCase() }}), so it can't be changed. If HRD returns it, you can correct it and print it again.</p>
        </div>
        <div v-if="errorList.length" class="rounded-md border border-danger-border bg-danger-bg px-4 py-3 text-sm text-danger-fg" role="alert">
            <p class="font-medium">Please check these before saving:</p>
            <ul class="mt-1 list-disc pl-5">
                <li v-for="(message, i) in errorList.slice(0, 8)" :key="i">{{ message }}</li>
                <li v-if="errorList.length > 8">…and {{ errorList.length - 8 }} more.</li>
            </ul>
        </div>

        <fieldset :disabled="locked" class="space-y-6">
            <Card title="Declarant" icon="user" description="As it should print on the form.">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div><InputLabel for="d-family">Family name</InputLabel><input id="d-family" v-model="s.declarant.family_name" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err('declarant.family_name')" /></div>
                    <div><InputLabel for="d-first">First name</InputLabel><input id="d-first" v-model="s.declarant.first_name" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err('declarant.first_name')" /></div>
                    <div><InputLabel for="d-mi">M.I.</InputLabel><input id="d-mi" v-model="s.declarant.middle_initial" class="form-control mt-1 block w-full px-3 py-2" /></div>
                    <div><InputLabel for="d-position">Position</InputLabel><input id="d-position" v-model="s.declarant.position" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err('declarant.position')" /></div>
                    <div><InputLabel for="d-agency">Agency/office</InputLabel><input id="d-agency" v-model="s.declarant.agency" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err('declarant.agency')" /></div>
                    <div><InputLabel for="d-address">Office address</InputLabel><input id="d-address" v-model="s.declarant.office_address" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err('declarant.office_address')" /></div>
                </div>
            </Card>

            <Card title="Spouse" icon="users" description="Spouses file separately. Position, agency and address are only for a spouse in government service.">
                <Switch id="has-spouse" v-model="hasSpouse" label="I have a spouse to declare" />
                <div v-if="s.spouse" class="mt-4 grid gap-4 sm:grid-cols-3">
                    <div><InputLabel for="s-family">Family name</InputLabel><input id="s-family" v-model="s.spouse.family_name" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err('spouse.family_name')" /></div>
                    <div><InputLabel for="s-first">First name</InputLabel><input id="s-first" v-model="s.spouse.first_name" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err('spouse.first_name')" /></div>
                    <div><InputLabel for="s-mi">M.I.</InputLabel><input id="s-mi" v-model="s.spouse.middle_initial" class="form-control mt-1 block w-full px-3 py-2" /></div>
                    <fieldset class="sm:col-span-3">
                        <legend class="text-sm font-medium text-text">Is your spouse also a government official or employee?</legend>
                        <div class="mt-2 flex flex-wrap gap-6">
                            <Radio id="s-gov-yes" v-model="s.spouse.in_government" name="spouse_in_government" :value="true" label="Yes (the form shows “Separate Filing”)" />
                            <Radio id="s-gov-no" v-model="s.spouse.in_government" name="spouse_in_government" :value="false" label="No" />
                        </div>
                    </fieldset>
                    <div v-for="field in spouseJobFields" :key="field.key">
                        <InputLabel :for="`s-${field.key}`">{{ field.label }}</InputLabel>
                        <input
                            v-if="s.spouse.in_government"
                            :id="`s-${field.key}`"
                            v-model="s.spouse[field.key]"
                            :placeholder="field.placeholder"
                            class="form-control mt-1 block w-full px-3 py-2"
                            :aria-invalid="!!err(`spouse.${field.key}`)"
                        />
                        <input v-else :id="`s-${field.key}`" value="N/A" disabled class="form-control mt-1 block w-full px-3 py-2 text-text-muted" />
                        <InputError :message="err(`spouse.${field.key}`)" />
                    </div>
                    <p class="text-xs text-text-muted sm:col-span-3">
                        Position, agency and office address are only declared for a spouse in government service; otherwise the form shows N/A.
                    </p>
                </div>
                <!-- Shown only when HRD turns the question on (or entries already exist). -->
                <div v-if="options.multipleMarriages || s.other_spouses.length" class="mt-4">
                    <p class="text-sm font-medium text-text">Multiple marriages</p>
                    <p class="text-xs text-text-muted">Only where allowed by law. Leave empty to print “Not Applicable”.</p>
                    <div v-for="(name, i) in s.other_spouses" :key="i" class="mt-2 flex gap-2">
                        <input v-model="s.other_spouses[i]" class="form-control block w-full px-2 py-1.5" :aria-label="`Other spouse ${i + 1}`" />
                        <Button variant="ghost" size="sm" :aria-label="`Remove other spouse ${i + 1}`" @click="s.other_spouses.splice(i, 1)"><Icon name="trash" class="h-4 w-4" /></Button>
                    </div>
                    <Button variant="outline" size="sm" class="mt-2" @click="s.other_spouses.push('')">Add a spouse's name</Button>
                </div>
            </Card>

            <Card title="Unmarried children below 18 living in your household" icon="users" :description="`Age as of ${saln.reckoning_date}. Include dependent children living apart while studying. The form has 3 lines; more continue on a separate sheet.`">
                <div v-for="(child, i) in s.children" :key="i" class="mb-2 grid items-end gap-2 sm:grid-cols-[1fr_11rem_6rem_auto]">
                    <div><InputLabel :for="`c-name-${i}`">Name</InputLabel><input :id="`c-name-${i}`" v-model="child.name" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err(`children.${i}.name`)" /></div>
                    <div><InputLabel :for="`c-born-${i}`">Date of birth</InputLabel><input :id="`c-born-${i}`" :value="child.birth_date" type="date" class="form-control mt-1 block w-full px-3 py-2" @input="setBirthDate(child, $event.target.value)" /></div>
                    <div><InputLabel :for="`c-age-${i}`">Age</InputLabel><input :id="`c-age-${i}`" v-model="child.age" type="number" min="0" max="17" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err(`children.${i}.age`)" /></div>
                    <Button variant="ghost" size="sm" :aria-label="`Remove ${child.name || 'this child'}`" @click="s.children.splice(i, 1)"><Icon name="trash" class="h-4 w-4" /></Button>
                </div>
                <Button variant="outline" size="sm" @click="addChild">Add a child</Button>
            </Card>

            <Card v-for="section in SECTIONS" :key="section.table" :title="section.title" :icon="section.icon" :description="section.hint">
                <!-- Real properties: one block per property, laid out like the form's columns. -->
                <div v-if="section.table === 'real_properties'" class="space-y-4">
                    <p v-if="!s.real_properties.length" class="rounded-md border border-dashed border-border px-4 py-6 text-center text-sm text-text-muted">
                        No real property declared yet.
                    </p>
                    <div v-for="(row, i) in s.real_properties" :key="i" class="rounded-lg border border-border bg-surface-muted/40 p-4">
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <p class="text-sm font-semibold text-text">
                                Property {{ i + 1 }}<span v-if="row.description" class="font-normal text-text-muted"> — {{ row.description }}</span>
                            </p>
                            <Button variant="ghost" size="sm" :aria-label="`Remove property ${i + 1}`" @click="removeRow('real_properties', i)">
                                <Icon name="trash" class="h-4 w-4" /><span class="ml-1">Remove</span>
                            </Button>
                        </div>

                        <div class="grid gap-x-4 gap-y-3 sm:grid-cols-2 lg:grid-cols-12">
                            <div class="lg:col-span-3">
                                <InputLabel :for="`rp-owner-${i}`">Whose</InputLabel>
                                <select :id="`rp-owner-${i}`" v-model="row.owner" class="form-control mt-1 block w-full px-3 py-2">
                                    <option v-for="option in options.owners" :key="option.value" :value="option.value">{{ option.label }}</option>
                                </select>
                            </div>
                            <div class="sm:col-span-1 lg:col-span-9">
                                <InputLabel :for="`rp-description-${i}`">Description</InputLabel>
                                <input :id="`rp-description-${i}`" v-model="row.description" placeholder="Lot, house and lot, condominium, improvements" class="form-control mt-1 block w-full px-3 py-2" :aria-invalid="!!err(`real_properties.${i}.description`)" />
                                <InputError :message="err(`real_properties.${i}.description`)" />
                            </div>

                            <div class="lg:col-span-4">
                                <InputLabel :for="`rp-kind-${i}`">Kind</InputLabel>
                                <input :id="`rp-kind-${i}`" v-model="row.kind" list="saln-property-kinds" placeholder="Residential, commercial, agricultural…" class="form-control mt-1 block w-full px-3 py-2" :aria-invalid="!!err(`real_properties.${i}.kind`)" />
                                <InputError :message="err(`real_properties.${i}.kind`)" />
                            </div>
                            <div class="lg:col-span-8">
                                <InputLabel :for="`rp-location-${i}`">Exact location</InputLabel>
                                <input :id="`rp-location-${i}`" v-model="row.location" placeholder="Barangay, city/municipality, province" class="form-control mt-1 block w-full px-3 py-2" :aria-invalid="!!err(`real_properties.${i}.location`)" />
                                <InputError :message="err(`real_properties.${i}.location`)" />
                            </div>

                            <fieldset class="rounded-md border border-border bg-surface p-3 sm:col-span-2 lg:col-span-5">
                                <legend class="px-1 text-xs font-medium text-text-muted">As found in the tax declaration (N/A if none)</legend>
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <div>
                                        <InputLabel :for="`rp-assessed-${i}`">Assessed value</InputLabel>
                                        <input :id="`rp-assessed-${i}`" v-model="row.assessed_value" inputmode="decimal" placeholder="0.00" class="form-control mt-1 block w-full px-3 py-2 text-right tabular-nums" :aria-invalid="!!err(`real_properties.${i}.assessed_value`)" />
                                        <InputError :message="err(`real_properties.${i}.assessed_value`)" />
                                    </div>
                                    <div>
                                        <InputLabel :for="`rp-market-${i}`">Current fair market value</InputLabel>
                                        <input :id="`rp-market-${i}`" v-model="row.market_value" inputmode="decimal" placeholder="0.00" class="form-control mt-1 block w-full px-3 py-2 text-right tabular-nums" :aria-invalid="!!err(`real_properties.${i}.market_value`)" />
                                        <InputError :message="err(`real_properties.${i}.market_value`)" />
                                    </div>
                                </div>
                            </fieldset>
                            <fieldset class="rounded-md border border-border bg-surface p-3 sm:col-span-2 lg:col-span-7">
                                <legend class="px-1 text-xs font-medium text-text-muted">Acquisition</legend>
                                <div class="grid gap-3 sm:grid-cols-[6rem_1fr_1fr]">
                                    <div>
                                        <InputLabel :for="`rp-year-${i}`">Year</InputLabel>
                                        <input :id="`rp-year-${i}`" v-model="row.acquisition_year" inputmode="numeric" placeholder="2015" class="form-control mt-1 block w-full px-3 py-2" :aria-invalid="!!err(`real_properties.${i}.acquisition_year`)" />
                                        <InputError :message="err(`real_properties.${i}.acquisition_year`)" />
                                    </div>
                                    <div>
                                        <InputLabel :for="`rp-mode-${i}`">Mode</InputLabel>
                                        <input :id="`rp-mode-${i}`" v-model="row.acquisition_mode" list="saln-acquisition-modes" placeholder="Purchase, inheritance…" class="form-control mt-1 block w-full px-3 py-2" :aria-invalid="!!err(`real_properties.${i}.acquisition_mode`)" />
                                        <InputError :message="err(`real_properties.${i}.acquisition_mode`)" />
                                    </div>
                                    <div>
                                        <InputLabel :for="`rp-cost-${i}`">Acquisition cost</InputLabel>
                                        <input :id="`rp-cost-${i}`" v-model="row.acquisition_cost" inputmode="decimal" placeholder="0.00" class="form-control mt-1 block w-full px-3 py-2 text-right tabular-nums" :aria-invalid="!!err(`real_properties.${i}.acquisition_cost`)" />
                                        <InputError :message="err(`real_properties.${i}.acquisition_cost`)" />
                                    </div>
                                </div>
                            </fieldset>
                        </div>
                    </div>
                    <datalist id="saln-property-kinds">
                        <option v-for="kind in ['Residential', 'Commercial', 'Industrial', 'Agricultural', 'Mixed use']" :key="kind" :value="kind" />
                    </datalist>
                    <datalist id="saln-acquisition-modes">
                        <option v-for="mode in ['Purchase', 'Inheritance', 'Donation', 'Assignment', 'Construction']" :key="mode" :value="mode" />
                    </datalist>
                </div>

                <div v-else class="overflow-x-auto">
                    <table class="w-full min-w-[44rem] text-sm">
                        <thead>
                            <tr class="text-left text-xs text-text-muted">
                                <th v-for="column in TABLES[section.table]" :key="column.key" class="px-1 pb-1 font-medium" :class="column.width">{{ column.label }}</th>
                                <th class="w-10"><span class="sr-only">Remove</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, i) in s[section.table]" :key="i" class="align-top">
                                <td v-for="column in TABLES[section.table]" :key="column.key" class="px-1 py-1">
                                    <select v-if="column.type === 'owner'" v-model="row.owner" class="form-control block w-full px-2 py-1.5" :aria-label="`${column.label}, row ${i + 1}`">
                                        <option v-for="option in options.owners" :key="option.value" :value="option.value">{{ option.label }}</option>
                                    </select>
                                    <input
                                        v-else
                                        v-model="row[column.key]"
                                        class="form-control block w-full px-2 py-1.5"
                                        :class="{ 'text-right tabular-nums': column.type === 'money' || column.type === 'value', 'border-danger': err(`${section.table}.${i}.${column.key}`) }"
                                        :inputmode="column.type === 'money' ? 'decimal' : undefined"
                                        :aria-label="`${column.label}, row ${i + 1}`"
                                        :title="err(`${section.table}.${i}.${column.key}`)"
                                    />
                                </td>
                                <td class="py-1"><Button variant="ghost" size="sm" :aria-label="`Remove row ${i + 1}`" @click="removeRow(section.table, i)"><Icon name="trash" class="h-4 w-4" /></Button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                    <Button variant="outline" size="sm" @click="addRow(section.table)">{{ section.table === 'real_properties' ? 'Add a property' : 'Add a row' }}</Button>
                    <p class="text-sm text-text-muted">
                        <template v-if="section.table === 'real_properties'">Subtotal <strong class="tabular-nums text-text">{{ peso(totals.real) }}</strong></template>
                        <template v-else-if="section.table === 'personal_properties'">Subtotal <strong class="tabular-nums text-text">{{ peso(totals.personal) }}</strong> · Total assets <strong class="tabular-nums text-text">{{ peso(totals.assets) }}</strong></template>
                        <template v-else>Total liabilities <strong class="tabular-nums text-text">{{ peso(totals.liabilities) }}</strong></template>
                    </p>
                </div>
            </Card>

            <div class="flex items-center justify-between rounded-lg border border-border bg-surface px-5 py-4">
                <span class="text-sm text-text-muted">Net worth (total assets less total liabilities)</span>
                <strong class="text-lg tabular-nums" :class="totals.netWorth < 0 ? 'text-danger-fg' : 'text-text'">{{ peso(totals.netWorth) }}</strong>
            </div>

            <Card title="Business interests and financial connections" icon="chartBar" description="Yours, your spouse's and your unmarried children's below 18: shares, cooperatives, businesses, paid consultancies.">
                <Switch id="no-business" v-model="s.no_business_interests" label="I/We do not have any business interest or financial connection" />
                <template v-if="!s.no_business_interests">
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[60rem] text-sm">
                            <thead><tr class="text-left text-xs text-text-muted"><th v-for="column in TABLES.business_interests" :key="column.key" class="px-1 pb-1 font-medium" :class="column.width">{{ column.label }}</th><th class="w-10"><span class="sr-only">Remove</span></th></tr></thead>
                            <tbody>
                                <tr v-for="(row, i) in s.business_interests" :key="i">
                                    <td v-for="column in TABLES.business_interests" :key="column.key" class="px-1 py-1">
                                        <select v-if="column.type === 'owner'" v-model="row.owner" class="form-control block w-full px-2 py-1.5" :aria-label="`${column.label}, row ${i + 1}`">
                                            <option v-for="option in options.owners" :key="option.value" :value="option.value">{{ option.label }}</option>
                                        </select>
                                        <input v-else v-model="row[column.key]" class="form-control block w-full px-2 py-1.5" :aria-label="`${column.label}, row ${i + 1}`" :class="{ 'border-danger': err(`business_interests.${i}.${column.key}`) }" />
                                    </td>
                                    <td class="py-1"><Button variant="ghost" size="sm" :aria-label="`Remove row ${i + 1}`" @click="removeRow('business_interests', i)"><Icon name="trash" class="h-4 w-4" /></Button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <InputError :message="err('business_interests')" />
                    <Button variant="outline" size="sm" class="mt-2" @click="addRow('business_interests')">Add a row</Button>
                </template>
            </Card>

            <Card title="Relatives in the government service" icon="users" description="Within the fourth degree of consanguinity or affinity, including bilas, balae and inso, to the best of your knowledge. The form has 5 lines; more continue on a separate sheet.">
                <Switch id="no-relatives" v-model="s.no_relatives" label="I/We do not know of any relative/s in the government service" />
                <template v-if="!s.no_relatives">
                    <div class="mt-3 overflow-x-auto">
                        <table class="w-full min-w-[48rem] text-sm">
                            <thead><tr class="text-left text-xs text-text-muted"><th v-for="column in TABLES.relatives" :key="column.key" class="px-1 pb-1 font-medium">{{ column.label }}</th><th class="w-10"><span class="sr-only">Remove</span></th></tr></thead>
                            <tbody>
                                <tr v-for="(row, i) in s.relatives" :key="i">
                                    <td v-for="column in TABLES.relatives" :key="column.key" class="px-1 py-1">
                                        <input v-model="row[column.key]" class="form-control block w-full px-2 py-1.5" :aria-label="`${column.label}, row ${i + 1}`" :class="{ 'border-danger': err(`relatives.${i}.${column.key}`) }" />
                                    </td>
                                    <td class="py-1"><Button variant="ghost" size="sm" :aria-label="`Remove row ${i + 1}`" @click="removeRow('relatives', i)"><Icon name="trash" class="h-4 w-4" /></Button></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <InputError :message="err('relatives')" />
                    <Button variant="outline" size="sm" class="mt-2" @click="addRow('relatives')">Add a relative</Button>
                </template>
            </Card>

            <Card title="Government-issued ID and oath" icon="shield" description="The ID you will show when you swear to the SALN.">
                <div class="grid gap-4 sm:grid-cols-3">
                    <div><InputLabel for="id-type">Government-issued ID</InputLabel><input id="id-type" v-model="s.government_id.type" class="form-control mt-1 block w-full px-3 py-2" placeholder="e.g. UMID, passport" /><InputError :message="err('government_id.type')" /></div>
                    <div><InputLabel for="id-number">ID number</InputLabel><input id="id-number" v-model="s.government_id.number" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err('government_id.number')" /></div>
                    <div><InputLabel for="id-issued">Date issued</InputLabel><input id="id-issued" v-model="s.government_id.issued_on" type="date" class="form-control mt-1 block w-full px-3 py-2" /><InputError :message="err('government_id.issued_on')" /></div>
                </div>
                <fieldset class="mt-5">
                    <legend class="text-sm font-medium text-text">You will swear to it</legend>
                    <p v-if="saln.swears_before_self" class="text-xs text-text-muted">You head the Office of the Regional Director, so you can't administer your own oath.</p>
                    <div class="mt-2 space-y-2">
                        <Radio v-for="mode in swearingOptions" :id="`oath-${mode.value}`" :key="mode.value" v-model="form.oath_mode" name="oath_mode" :value="mode.value" :label="mode.label" />
                    </div>
                    <div v-if="form.oath_mode === 'other_officer'" class="mt-3 grid gap-4 sm:grid-cols-2">
                        <div><InputLabel for="officer-name">Administering officer's name</InputLabel><input id="officer-name" v-model="form.officer_name" class="form-control mt-1 block w-full px-3 py-2" /></div>
                        <div><InputLabel for="officer-title">Position</InputLabel><input id="officer-title" v-model="form.officer_title" class="form-control mt-1 block w-full px-3 py-2" /></div>
                    </div>
                    <InputError :message="form.errors.saln" />
                </fieldset>
            </Card>
        </fieldset>

        <div v-if="!locked" class="sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-3 border-t border-border bg-surface/95 px-4 py-3 backdrop-blur">
            <span v-if="form.isDirty" class="mr-auto text-sm text-text-muted">Unsaved changes</span>
            <Button type="submit" variant="outline" :loading="form.processing">Save</Button>
            <Button variant="primary" :loading="form.processing" @click="printNew">Save and prepare printout</Button>
        </div>
    </form>
</template>
