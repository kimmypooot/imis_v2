<script setup>
import { computed, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from './Icon.vue';
import Button from './Button.vue';
import Dropdown from './Dropdown.vue';
import EmptyState from './EmptyState.vue';
import Pagination from './Pagination.vue';
import Skeleton from './Skeleton.vue';
import { toast } from '../../Composables/useDialog';

/**
 * The legacy IMIS admin table (jQuery DataTables) rebuilt for Vue:
 * Search, "Show N entries", an Export menu (Copy / CSV / Print), sortable
 * headers, a "#" column, "Showing x to y of z entries", and pagination.
 * Looks like every other list table (the `data-table` styles in app.css).
 *
 * Two modes:
 * - **Client** (`rows`): everything is loaded; search/sort/paging happen in
 *   the browser. For ordinary admin lists (users, systems, offices…).
 * - **Server** (`paginator`): a Laravel paginator; search/sort/paging are
 *   query parameters on `url`, fetched with an Inertia partial reload of
 *   the `only` props. For large logs. CSV then comes from `exportUrl`
 *   (all matching rows), and Copy/Print use the current page.
 *
 * Columns: [{ key, label, sortable = true, align = 'left', width?,
 *             value?: (row) => string  // for search/sort/export
 *             export = true }]
 *
 * Extras: `density` ("compact" | "default" | "comfortable"), `sticky`
 * (pinned header inside a scrolling area), `loading` (skeleton rows; server
 * mode also shows them while a reload is in flight), and an `empty` slot
 * or `emptyIcon` / `emptyDescription` for the no-rows state. Below the
 * `sm` breakpoint rows stack as labelled cards (`:stack="false"` keeps the
 * horizontally scrolling table). `selectable` adds a checkbox column:
 * `v-model:selected` holds the chosen `rowKey` values, and the `bulk` slot
 * ({ selected, clear }) shows while any row is chosen.
 * Cells: slot `cell-<key>` with { row, value, index }; default text otherwise.
 */
const props = defineProps({
    columns: { type: Array, required: true },
    rows: { type: Array, default: null },
    paginator: { type: Object, default: null },
    url: { type: String, default: null },
    only: { type: Array, default: () => [] },
    query: { type: Object, default: () => ({}) },
    exportUrl: { type: String, default: null },
    caption: { type: String, required: true },
    exportName: { type: String, default: 'export' },
    rowKey: { type: String, default: 'id' },
    numbered: { type: Boolean, default: true },
    searchable: { type: Boolean, default: true },
    exportable: { type: Boolean, default: true },
    pageSizes: { type: Array, default: () => [10, 25, 50, 100] },
    defaultPageSize: { type: Number, default: 25 },
    defaultSort: { type: Object, default: null /** { key, direction } */ },
    emptyText: { type: String, default: 'No data available in table' },
    rowClass: { type: Function, default: null },
    density: { type: String, default: 'default', validator: (value) => ['compact', 'default', 'comfortable'].includes(value) },
    sticky: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
    stack: { type: Boolean, default: true },
    selectable: { type: Boolean, default: false },
    selected: { type: Array, default: () => [] },
    emptyIcon: { type: String, default: null },
    emptyDescription: { type: String, default: null },
});

const emit = defineEmits(['update:selected']);

const id = useId();
const serverMode = computed(() => props.paginator !== null);

const search = ref(props.query.search ?? '');
const perPage = ref(Number(props.query.per_page) || props.paginator?.per_page || props.defaultPageSize);
const sortKey = ref(props.query.sort ?? props.defaultSort?.key ?? null);
const sortDirection = ref(props.query.direction ?? props.defaultSort?.direction ?? 'asc');
const page = ref(1);
const reloading = ref(false);
const showSkeleton = computed(() => props.loading || reloading.value);

const valueOf = (column, row) => {
    const raw = column.value ? column.value(row) : row[column.key];

    return raw === null || raw === undefined ? '' : String(raw);
};

// ── Client mode ──────────────────────────────────────────────────────
const filtered = computed(() => {
    if (serverMode.value) return props.paginator.data;

    const term = search.value.trim().toLowerCase();
    let rows = props.rows ?? [];

    if (term) {
        rows = rows.filter((row) => props.columns.some((column) => valueOf(column, row).toLowerCase().includes(term)));
    }

    const column = props.columns.find((candidate) => candidate.key === sortKey.value);
    if (column) {
        const direction = sortDirection.value === 'desc' ? -1 : 1;
        rows = [...rows].sort(
            (a, b) => valueOf(column, a).localeCompare(valueOf(column, b), undefined, { numeric: true, sensitivity: 'base' }) * direction,
        );
    }

    return rows;
});

const total = computed(() => (serverMode.value ? props.paginator.total : filtered.value.length));
const lastPage = computed(() => (serverMode.value ? props.paginator.last_page : Math.max(1, Math.ceil(total.value / perPage.value))));
const currentPage = computed(() => (serverMode.value ? props.paginator.current_page : Math.min(page.value, lastPage.value)));

const visibleRows = computed(() => {
    if (serverMode.value) return props.paginator.data;
    const start = (currentPage.value - 1) * perPage.value;

    return filtered.value.slice(start, start + perPage.value);
});

const firstIndex = computed(() => (total.value === 0 ? 0 : (currentPage.value - 1) * perPage.value + 1));
const lastIndex = computed(() => Math.min(currentPage.value * perPage.value, total.value));

// ── Server mode ──────────────────────────────────────────────────────
let searchTimer = null;

const fetchServer = (overrides = {}) => {
    router.get(
        props.url,
        {
            ...props.query,
            search: search.value || undefined,
            sort: sortKey.value || undefined,
            direction: sortKey.value ? sortDirection.value : undefined,
            per_page: perPage.value,
            page: 1,
            ...overrides,
        },
        {
            only: props.only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (reloading.value = true),
            onFinish: () => (reloading.value = false),
        },
    );
};

watch(search, () => {
    page.value = 1;
    if (!serverMode.value) return;
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => fetchServer(), 350);
});

watch(perPage, () => {
    page.value = 1;
    if (serverMode.value) fetchServer();
});

const goTo = (target) => {
    if (target < 1 || target > lastPage.value || target === currentPage.value) return;
    if (serverMode.value) fetchServer({ page: target });
    else page.value = target;
};

const toggleSort = (column) => {
    if (column.sortable === false) return;
    if (sortKey.value === column.key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc';
    } else {
        sortKey.value = column.key;
        sortDirection.value = 'asc';
    }
    page.value = 1;
    if (serverMode.value) fetchServer();
};

const ariaSort = (column) => {
    if (sortKey.value !== column.key) return column.sortable === false ? undefined : 'none';

    return sortDirection.value === 'asc' ? 'ascending' : 'descending';
};

// ── Export ───────────────────────────────────────────────────────────
const exportColumns = computed(() => props.columns.filter((column) => column.export !== false && column.label));
const exportRows = () => (serverMode.value ? props.paginator.data : filtered.value);

const csvCell = (value) => `"${String(value).replace(/"/g, '""')}"`;

const copyRows = async () => {
    const lines = [exportColumns.value.map((column) => column.label), ...exportRows().map((row) => exportColumns.value.map((column) => valueOf(column, row)))];
    try {
        await navigator.clipboard.writeText(lines.map((line) => line.join('\t')).join('\n'));
        toast.success(`Copied ${exportRows().length} ${exportRows().length === 1 ? 'row' : 'rows'} to the clipboard.`);
    } catch {
        toast.error('Copying was blocked by the browser.');
    }
};

const downloadCsv = () => {
    if (serverMode.value && props.exportUrl) {
        const params = new URLSearchParams({ ...props.query, ...(search.value ? { search: search.value } : {}) });
        window.location.href = `${props.exportUrl}?${params}`;

        return;
    }

    const lines = [exportColumns.value.map((column) => csvCell(column.label)), ...exportRows().map((row) => exportColumns.value.map((column) => csvCell(valueOf(column, row))))];
    // BOM so Excel opens UTF-8 (ñ, é) correctly.
    const blob = new Blob(['﻿' + lines.map((line) => line.join(',')).join('\r\n')], { type: 'text/csv;charset=utf-8' });
    const link = Object.assign(document.createElement('a'), {
        href: URL.createObjectURL(blob),
        download: `${props.exportName}-${new Date().toISOString().slice(0, 10)}.csv`,
    });
    link.click();
    URL.revokeObjectURL(link.href);
};

const escapeHtml = (value) => String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[char]);

const printRows = () => {
    const head = exportColumns.value.map((column) => `<th>${escapeHtml(column.label)}</th>`).join('');
    const body = exportRows()
        .map((row) => `<tr>${exportColumns.value.map((column) => `<td>${escapeHtml(valueOf(column, row))}</td>`).join('')}</tr>`)
        .join('');
    const win = window.open('', '_blank', 'width=1000,height=700');
    if (!win) {
        toast.error('Allow pop-ups to print this table.');

        return;
    }
    win.document.write(
        `<!doctype html><title>${escapeHtml(props.caption)}</title><style>body{font:12px system-ui,sans-serif;margin:24px}h1{font-size:16px}table{border-collapse:collapse;width:100%}th,td{border:1px solid #ccc;padding:4px 6px;text-align:left}th{background:#f3f4f6}</style><h1>${escapeHtml(props.caption)}</h1><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`,
    );
    win.document.close();
    win.focus();
    win.print();
};

// ── Selection ────────────────────────────────────────────────────────
const keyOf = (row, index) => row[props.rowKey] ?? index;
const isSelected = (row, index) => props.selected.includes(keyOf(row, index));
const pageKeys = computed(() => visibleRows.value.map((row, index) => keyOf(row, index)));
const allSelected = computed(() => pageKeys.value.length > 0 && pageKeys.value.every((key) => props.selected.includes(key)));
const someSelected = computed(() => !allSelected.value && pageKeys.value.some((key) => props.selected.includes(key)));

const toggleRow = (row, index) => {
    const key = keyOf(row, index);
    emit('update:selected', props.selected.includes(key) ? props.selected.filter((value) => value !== key) : [...props.selected, key]);
};

const toggleAll = () => {
    emit('update:selected', allSelected.value ? props.selected.filter((key) => !pageKeys.value.includes(key)) : [...new Set([...props.selected, ...pageKeys.value])]);
};

const clearSelection = () => emit('update:selected', []);

const alignClass = (column) => ({ center: 'text-center', right: 'text-right' })[column.align] ?? 'text-left';
</script>

<template>
    <div class="space-y-3">
        <!-- Toolbar: search first, then page size; export tucked into one menu -->
        <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            <div v-if="searchable" class="relative w-full sm:w-80">
                <label :for="`${id}-search`" class="sr-only">Search {{ caption }}</label>
                <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-text-muted" />
                <input
                    :id="`${id}-search`"
                    v-model="search"
                    type="text"
                    inputmode="search"
                    autocomplete="off"
                    placeholder="Search…"
                    class="form-control block w-full py-1.5 pr-8 pl-8"
                />
                <button
                    v-if="search"
                    type="button"
                    class="absolute top-1/2 right-1.5 -translate-y-1/2 rounded p-1 text-text-muted hover:bg-surface-muted hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                    aria-label="Clear search"
                    @click="search = ''"
                >
                    <Icon name="close" size="sm" />
                </button>
            </div>
            <span v-else />

            <div class="flex flex-wrap items-center gap-3">
                <slot name="toolbar" />

                <label :for="`${id}-length`" class="flex items-center gap-2 text-sm text-text-muted">
                    Show
                    <select :id="`${id}-length`" v-model.number="perPage" class="form-control px-2 py-1">
                        <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
                    </select>
                </label>

                <Dropdown v-if="exportable" panel-class="w-44 py-1">
                    <template #trigger="{ open, toggle, setTrigger, panelId }">
                        <Button :ref="(component) => setTrigger(component?.$el ?? null)" variant="secondary" size="sm" aria-haspopup="true" :aria-expanded="open" :aria-controls="panelId" @click="toggle">
                            <Icon name="download" size="sm" />
                            Export
                            <Icon name="chevronDown" size="sm" />
                        </Button>
                    </template>
                    <template #default="{ close }">
                        <button type="button" class="export-item" @click="copyRows(); close()"><Icon name="copy" size="sm" />Copy to clipboard</button>
                        <button type="button" class="export-item" @click="downloadCsv(); close()"><Icon name="download" size="sm" />Download CSV</button>
                        <button type="button" class="export-item" @click="printRows(); close()"><Icon name="printer" size="sm" />Print</button>
                    </template>
                </Dropdown>
            </div>
        </div>

        <!-- Table -->
        <div class="table-card" :class="{ 'max-h-[70vh] overflow-y-auto': sticky }" :aria-busy="showSkeleton">
            <table class="data-table" :data-density="density" :data-sticky="sticky ? '' : undefined" :data-stack="stack ? '' : undefined">
                <caption class="sr-only">{{ caption }}</caption>
                <thead>
                    <tr>
                        <th v-if="selectable" scope="col" class="w-10">
                            <input
                                type="checkbox"
                                class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring"
                                :checked="allSelected"
                                :indeterminate="someSelected"
                                aria-label="Select all rows on this page"
                                @change="toggleAll"
                            />
                        </th>
                        <th v-if="numbered" scope="col" class="w-12 text-center">#</th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="whitespace-nowrap"
                            :class="alignClass(column)"
                            :style="column.width ? { width: column.width } : undefined"
                            :aria-sort="ariaSort(column)"
                        >
                            <button
                                v-if="column.sortable !== false && column.label"
                                type="button"
                                class="inline-flex items-center gap-1 uppercase hover:text-text focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                @click="toggleSort(column)"
                            >
                                {{ column.label }}
                                <Icon
                                    :name="sortKey === column.key ? (sortDirection === 'asc' ? 'chevronUp' : 'chevronDown') : 'sort'"
                                    size="sm"
                                    :class="sortKey === column.key ? 'text-link' : 'opacity-40'"
                                />
                            </button>
                            <span v-else-if="column.label">{{ column.label }}</span>
                            <span v-else class="sr-only">{{ column.srLabel ?? 'Actions' }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <template v-if="showSkeleton">
                        <tr v-for="row in Math.min(perPage, 5)" :key="`skeleton-${row}`" aria-hidden="true">
                            <td v-for="cell in columns.length + (numbered ? 1 : 0) + (selectable ? 1 : 0)" :key="cell"><Skeleton class="h-4 w-full max-w-40" /></td>
                        </tr>
                    </template>
                    <tr v-else-if="visibleRows.length === 0" class="hover:!bg-transparent">
                        <td :colspan="columns.length + (numbered ? 1 : 0) + (selectable ? 1 : 0)" class="!p-0">
                            <slot name="empty" :search="search">
                                <EmptyState
                                    v-if="search"
                                    icon="search"
                                    :title="`No records match “${search}”`"
                                    description="Check the spelling or try fewer words."
                                >
                                    <template #action>
                                        <Button variant="secondary" size="sm" @click="search = ''">Clear search</Button>
                                    </template>
                                </EmptyState>
                                <EmptyState v-else :icon="emptyIcon" :title="emptyText" :description="emptyDescription" />
                            </slot>
                        </td>
                    </tr>
                    <template v-else>
                        <tr v-for="(row, index) in visibleRows" :key="row[rowKey] ?? index" :class="rowClass ? rowClass(row) : undefined">
                            <td v-if="selectable">
                                <input
                                    type="checkbox"
                                    class="h-4 w-4 rounded-sm border-border-strong text-link focus:ring-2 focus:ring-focus-ring"
                                    :checked="isSelected(row, index)"
                                    :aria-label="`Select row ${firstIndex + index}`"
                                    @change="toggleRow(row, index)"
                                />
                            </td>
                            <td v-if="numbered" class="text-center text-text-muted tabular-nums max-sm:hidden">{{ firstIndex + index }}</td>
                            <td v-for="column in columns" :key="column.key" :class="alignClass(column)" :data-label="column.label || undefined">
                                <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]" :index="index">
                                    {{ valueOf(column, row) || '—' }}
                                </slot>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Bulk actions: pinned to the bottom of the screen so ticking rows never shifts them -->
        <div v-if="selectable && selected.length" class="sticky bottom-4 z-20 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-primary/30 bg-surface px-4 py-2.5 text-sm shadow-lg" role="status">
            <span class="font-medium text-link">{{ selected.length }} selected</span>
            <div class="flex flex-wrap items-center gap-2">
                <slot name="bulk" :selected="selected" :clear="clearSelection" />
                <Button variant="secondary" size="sm" @click="clearSelection">Clear</Button>
            </div>
        </div>

        <!-- Footer -->
        <div class="flex flex-col gap-3 text-sm text-text-muted sm:flex-row sm:items-center sm:justify-between">
            <p role="status" aria-live="polite">
                Showing {{ firstIndex }} to {{ lastIndex }} of {{ total }} {{ total === 1 ? 'entry' : 'entries' }}
                <span v-if="!serverMode && search">(filtered from {{ rows?.length ?? 0 }} total)</span>
            </p>

            <Pagination numbered :current-page="currentPage" :last-page="lastPage" :label="`${caption} pages`" @change="goTo" />
        </div>
    </div>
</template>

<style scoped>
@reference '../../../css/app.css';

.export-item {
    @apply flex w-full items-center gap-2 px-3 py-2 text-left text-sm text-text hover:bg-surface-muted focus:bg-surface-muted focus:outline-none;
}
</style>
