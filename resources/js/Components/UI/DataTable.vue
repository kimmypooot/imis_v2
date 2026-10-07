<script setup>
import { computed, ref, useId, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import Icon from './Icon.vue';
import Button from './Button.vue';
import { toast } from '../../Composables/useDialog';

/**
 * The legacy IMIS admin table (jQuery DataTables) rebuilt for Vue:
 * "Show N entries", search, Copy / CSV / Print, sortable headers, a "#"
 * column, "Showing x to y of z entries", and pagination.
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
});

const id = useId();
const serverMode = computed(() => props.paginator !== null);

const search = ref(props.query.search ?? '');
const perPage = ref(Number(props.query.per_page) || props.paginator?.per_page || props.defaultPageSize);
const sortKey = ref(props.query.sort ?? props.defaultSort?.key ?? null);
const sortDirection = ref(props.query.direction ?? props.defaultSort?.direction ?? 'asc');
const page = ref(1);

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
        { only: props.only, preserveState: true, preserveScroll: true, replace: true },
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

// Compact page list: 1 … 4 5 6 … 12
const pages = computed(() => {
    const count = lastPage.value;
    const current = currentPage.value;
    if (count <= 7) return Array.from({ length: count }, (_, index) => index + 1);

    const set = new Set([1, count, current - 1, current, current + 1].filter((value) => value >= 1 && value <= count));
    const sorted = [...set].sort((a, b) => a - b);

    return sorted.flatMap((value, index) => (index > 0 && value - sorted[index - 1] > 1 ? ['…', value] : [value]));
});

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

const alignClass = (column) => ({ center: 'text-center', right: 'text-right' })[column.align] ?? 'text-left';
</script>

<template>
    <div class="space-y-3">
        <!-- Toolbar -->
        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div class="flex flex-wrap items-center gap-3">
                <label :for="`${id}-length`" class="flex items-center gap-2 text-sm text-text-muted">
                    Show
                    <select
                        :id="`${id}-length`"
                        v-model.number="perPage"
                        class="rounded-md border border-border-strong bg-surface px-2 py-1 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                    >
                        <option v-for="size in pageSizes" :key="size" :value="size">{{ size }}</option>
                    </select>
                    entries
                </label>

                <div v-if="exportable" class="flex items-center gap-1" role="group" aria-label="Export">
                    <Button variant="secondary" size="sm" @click="copyRows"><Icon name="copy" size="sm" />Copy</Button>
                    <Button variant="secondary" size="sm" @click="downloadCsv"><Icon name="download" size="sm" />CSV</Button>
                    <Button variant="secondary" size="sm" @click="printRows"><Icon name="printer" size="sm" />Print</Button>
                </div>

                <slot name="toolbar" />
            </div>

            <div v-if="searchable" class="relative w-full lg:w-72">
                <label :for="`${id}-search`" class="sr-only">Search {{ caption }}</label>
                <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-2.5 -translate-y-1/2 text-text-muted" />
                <input
                    :id="`${id}-search`"
                    v-model="search"
                    type="search"
                    placeholder="Search…"
                    class="block w-full rounded-md border border-border-strong bg-surface py-1.5 pr-3 pl-8 text-sm text-text focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                />
            </div>
        </div>

        <!-- Table -->
        <div class="overflow-x-auto rounded-md border border-border">
            <table class="min-w-full text-[0.8125rem]">
                <caption class="sr-only">{{ caption }}</caption>
                <thead class="table-head">
                    <tr>
                        <th v-if="numbered" scope="col" class="w-12 px-3 py-2.5 text-center">#</th>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="px-3 py-2.5 whitespace-nowrap"
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
                                    :class="sortKey === column.key ? 'text-primary' : 'opacity-40'"
                                />
                            </button>
                            <span v-else-if="column.label">{{ column.label }}</span>
                            <span v-else class="sr-only">{{ column.srLabel ?? 'Actions' }}</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border bg-surface">
                    <tr v-if="visibleRows.length === 0">
                        <td :colspan="columns.length + (numbered ? 1 : 0)" class="px-3 py-8 text-center text-text-muted">
                            {{ search ? 'No matching records found' : emptyText }}
                        </td>
                    </tr>
                    <tr
                        v-for="(row, index) in visibleRows"
                        :key="row[rowKey] ?? index"
                        class="transition-colors hover:bg-surface-muted/70"
                        :class="rowClass ? rowClass(row) : undefined"
                    >
                        <td v-if="numbered" class="px-3 py-2 text-center text-text-muted">{{ firstIndex + index }}</td>
                        <td v-for="column in columns" :key="column.key" class="px-3 py-2 align-middle text-text" :class="alignClass(column)">
                            <slot :name="`cell-${column.key}`" :row="row" :value="row[column.key]" :index="index">
                                {{ valueOf(column, row) || '—' }}
                            </slot>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Footer -->
        <div class="flex flex-col gap-3 text-sm text-text-muted sm:flex-row sm:items-center sm:justify-between">
            <p role="status" aria-live="polite">
                Showing {{ firstIndex }} to {{ lastIndex }} of {{ total }} {{ total === 1 ? 'entry' : 'entries' }}
                <span v-if="!serverMode && search">(filtered from {{ rows?.length ?? 0 }} total)</span>
            </p>

            <nav v-if="lastPage > 1" :aria-label="`${caption} pages`">
                <ul class="flex flex-wrap items-center gap-1">
                    <li>
                        <button type="button" class="dt-page" :disabled="currentPage === 1" @click="goTo(currentPage - 1)">Previous</button>
                    </li>
                    <li v-for="(item, index) in pages" :key="`${item}-${index}`">
                        <span v-if="item === '…'" class="px-2" aria-hidden="true">…</span>
                        <button
                            v-else
                            type="button"
                            class="dt-page"
                            :class="{ 'is-current': item === currentPage }"
                            :aria-current="item === currentPage ? 'page' : undefined"
                            @click="goTo(item)"
                        >
                            {{ item }}
                        </button>
                    </li>
                    <li>
                        <button type="button" class="dt-page" :disabled="currentPage === lastPage" @click="goTo(currentPage + 1)">Next</button>
                    </li>
                </ul>
            </nav>
        </div>
    </div>
</template>

<style scoped>
@reference '../../../css/app.css';

.dt-page {
    @apply min-w-8 rounded-md border border-border bg-surface px-2.5 py-1 text-sm text-text hover:bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring disabled:cursor-not-allowed disabled:opacity-50;
}

.dt-page.is-current {
    @apply border-primary bg-primary text-white hover:bg-primary;
}
</style>
