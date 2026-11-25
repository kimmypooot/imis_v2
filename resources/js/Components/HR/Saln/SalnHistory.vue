<script setup>
import Card from '@cscro8/ui/Components/UI/Card.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import { formatDateTime } from '../../../Utils/datetime';
import { formatPeso } from '../../../Utils/money';

/**
 * A SALN's printouts (each a numbered version, opened in a new tab to
 * print) and its timeline: printed, received, returned, cleared, filed.
 */
defineProps({
    saln: { type: Object, required: true },
    printBase: { type: String, required: true },
});

const ACTIONS = {
    generated: 'Printed',
    received: 'Hard copy received by HRD',
    returned: 'Returned for correction',
    cleared: 'Cleared for oath',
    filed: 'Signed copy filed in the 201 File',
};
</script>

<template>
    <Card title="Printouts and history" icon="clock">
        <p v-if="!saln.versions.length" class="text-sm text-text-muted">Nothing printed yet. Save your entries, then prepare the printout.</p>
        <ul v-else class="space-y-2">
            <li v-for="version in saln.versions" :key="version.version" class="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span>
                    <strong class="text-text">{{ version.printed_reference }}</strong>
                    <span class="text-text-muted"> · {{ formatDateTime(version.created_at) }}</span>
                    <span v-if="version.version !== saln.current_version" class="text-text-muted"> · replaced</span>
                    <span v-if="saln.received_version === version.version" class="text-text-muted"> · the copy HRD received</span>
                </span>
                <!-- The latest printout is the one to print; older ones stay reachable, quieter. -->
                <Button
                    :href="`${printBase}/${version.version}/print`"
                    external
                    size="sm"
                    icon="printer"
                    :variant="version.version === saln.current_version ? 'primary' : 'ghost'"
                    :aria-label="`Open ${version.printed_reference} to print (new tab)`"
                >
                    {{ version.version === saln.current_version ? 'Print' : 'Open' }}
                </Button>
            </li>
        </ul>

        <ol v-if="saln.events.length" class="mt-4 space-y-2 border-t border-border pt-4 text-sm">
            <li v-for="(event, i) in saln.events" :key="i">
                <p class="text-text">
                    {{ ACTIONS[event.action] ?? event.action }}<template v-if="event.version"> (v{{ event.version }})</template>
                    <span v-if="event.override" class="text-text-muted"> — HRD decision</span>
                </p>
                <p class="text-xs text-text-muted">{{ event.by }} · {{ formatDateTime(event.at) }}</p>
                <p v-if="event.remarks" class="mt-1 rounded-md bg-surface-muted px-2 py-1 text-xs text-text">{{ event.remarks }}</p>
            </li>
        </ol>
        <p v-if="saln.versions.length && saln.versions[0].net_worth !== null" class="mt-4 border-t border-border pt-3 text-xs text-text-muted">
            Net worth on the latest printout: <span class="tabular-nums text-text">{{ formatPeso(Number(saln.versions[0].net_worth)) }}</span>
        </p>
    </Card>
</template>
