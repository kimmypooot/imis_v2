<script setup>
import { Head } from '@inertiajs/vue3';
import { formatChange, formatTarget, formatValue, sectionStates, systemStates, targetStatuses } from '../../Components/Executive/format';
import { formatDateTime } from '../../Utils/datetime';

/**
 * Exec-3: the Executive Dashboard as a report for management meetings —
 * systems, then every figure with its target and status, on A4 or short
 * bond paper. Print or "Save as PDF" from the browser. No app layout.
 */
defineProps({
    dashboard: { type: Object, required: true },
    organization: { type: String, default: null },
    printedBy: { type: String, required: true },
});

const printed = new Date().toLocaleString('en-PH', { year: 'numeric', month: 'long', day: 'numeric', hour: 'numeric', minute: '2-digit' });
const print = () => window.print();
</script>

<template>
    <Head title="Executive Dashboard — Report" />

    <div class="ex-toolbar">
        <p><strong>Executive Dashboard report.</strong> Use your browser's print dialog; choose “Save as PDF” to keep a copy.</p>
        <button type="button" @click="print">Print</button>
    </div>

    <main class="ex">
        <header>
            <p>{{ organization }}</p>
            <h1>Executive Dashboard</h1>
            <p v-if="dashboard.lastCollectedAt">Figures as of {{ formatDateTime(dashboard.lastCollectedAt) }}</p>
            <p v-else>No figures collected yet.</p>
        </header>

        <section class="glance">
            <p>
                <strong>Systems:</strong>
                {{ dashboard.summary.systemsNeedingAttention ? `${dashboard.summary.systemsNeedingAttention} of ${dashboard.summary.systems} need attention` : `all ${dashboard.summary.systems} running` }}.
            </p>
            <p v-if="dashboard.summary.withTargets">
                <strong>Against targets:</strong> {{ dashboard.summary.onTrack }} on track, {{ dashboard.summary.atRisk }} at risk,
                {{ dashboard.summary.behind }} behind (of {{ dashboard.summary.withTargets }}).
            </p>
            <p v-else><strong>Targets:</strong> none set yet.</p>
        </section>

        <h2>Systems</h2>
        <table>
            <thead>
                <tr><th>System</th><th>Status</th><th>Detail</th><th>Last figures</th></tr>
            </thead>
            <tbody>
                <tr v-for="system in dashboard.systems" :key="system.key">
                    <td>{{ system.fullName }}</td>
                    <td>{{ (systemStates[system.state] ?? systemStates.waiting).label }}</td>
                    <td>{{ system.detail }}</td>
                    <td>{{ system.updatedAt ? formatDateTime(system.updatedAt) : '—' }}</td>
                </tr>
            </tbody>
        </table>

        <template v-for="section in dashboard.sections" :key="section.key">
            <h2>
                {{ section.label }}
                <small v-if="sectionStates[section.state]">({{ sectionStates[section.state].label }})</small>
            </h2>
            <table v-if="section.metrics.length">
                <thead>
                    <tr><th>Figure</th><th class="num">Value</th><th>30-day change</th><th>Target</th><th>Status</th></tr>
                </thead>
                <tbody>
                    <tr v-for="metric in section.metrics" :key="metric.id">
                        <td>
                            {{ metric.label }}
                            <span v-if="metric.period || metric.note" class="muted"><br />{{ [metric.note, metric.period].filter(Boolean).join(' · ') }}</span>
                        </td>
                        <td class="num">{{ formatValue(metric.value, metric.unit) }}</td>
                        <td>{{ formatChange(metric.change?.value, metric.unit) ?? '—' }}</td>
                        <td>{{ metric.target ? formatTarget(metric.target, metric.unit).replace('Target: ', '') : '—' }}</td>
                        <td>{{ targetStatuses[metric.status]?.label ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="muted">{{ section.error ? `Not updating: ${section.error}` : 'No figures yet.' }}</p>
        </template>

        <footer>Printed {{ printed }} by {{ printedBy }} from the IMIS. Totals only; no personal records.</footer>
    </main>
</template>

<style scoped>
.ex-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    padding: 0.75rem 1rem;
    background: var(--color-surface-muted);
    border-bottom: 1px solid var(--color-border);
    font-size: 0.875rem;
}
.ex-toolbar button {
    padding: 0.4rem 1rem;
    border-radius: 0.375rem;
    background: var(--color-primary);
    color: #fff;
    font-weight: 600;
}
.ex {
    max-width: 8in;
    margin: 0 auto;
    padding: 0.5in 0.25in;
    font-size: 10pt;
    color: #000;
    background: #fff;
}
header {
    text-align: center;
    margin-bottom: 1rem;
}
header h1 {
    font-size: 16pt;
    font-weight: 700;
    margin: 0.25rem 0;
}
.glance {
    border: 1px solid #000;
    padding: 0.5rem 0.75rem;
    margin-bottom: 1rem;
}
h2 {
    font-size: 11pt;
    font-weight: 700;
    margin: 1rem 0 0.35rem;
}
h2 small {
    font-weight: 400;
}
table {
    width: 100%;
    border-collapse: collapse;
    break-inside: auto;
}
tr {
    break-inside: avoid;
}
th,
td {
    border: 1px solid #000;
    padding: 0.2rem 0.35rem;
    text-align: left;
    vertical-align: top;
}
th {
    background: #eee;
}
.num {
    text-align: right;
    white-space: nowrap;
}
.muted {
    color: #444;
    font-size: 9pt;
}
footer {
    margin-top: 1.25rem;
    font-size: 8pt;
    color: #444;
}
@media print {
    .ex-toolbar {
        display: none;
    }
    .ex {
        padding: 0;
        max-width: none;
    }
    @page {
        margin: 0.5in;
    }
}
</style>
