<script setup>
import Icon from '@cscro8/ui/Components/UI/Icon.vue';
import StatusBadge from '@cscro8/ui/Components/UI/StatusBadge.vue';
import { formatDateTime } from '../../../Utils/datetime';

/**
 * The PNPKI-signed copy of a PDS (PdsPnpki::present): a link to the PDF
 * filed in the 201 File, and each digital signature found in it, with who
 * signed, the certificate's issuer, when, and whether it is intact and
 * (when IMIS has the PNPKI CA bundle) chains to PNPKI.
 */
defineProps({
    copy: { type: Object, required: true },
});
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-text">
                <strong>{{ copy.signatures.length }}</strong> digital {{ copy.signatures.length === 1 ? 'signature' : 'signatures' }}, checked
                {{ formatDateTime(copy.checked_at) }}. Filed in the 201 File.
            </p>
            <a :href="`/hr/documents/${copy.document_id}`" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 text-sm font-medium text-link hover:underline">
                <Icon name="externalLink" size="sm" />Open the signed PDF<span class="sr-only"> (opens in a new tab)</span>
            </a>
        </div>

        <ul class="divide-y divide-border rounded-md border border-border">
            <li v-for="(signature, index) in copy.signatures" :key="index" class="flex flex-wrap items-start justify-between gap-3 px-3 py-2.5 text-sm">
                <div class="min-w-0">
                    <p class="font-medium text-text">{{ signature.signer ?? 'Unknown signer' }}</p>
                    <p class="text-xs text-text-muted">
                        Certificate issued by {{ signature.issuer ?? 'an unknown issuer' }}<template v-if="signature.signed_at"> · signed {{ formatDateTime(signature.signed_at) }}</template>
                    </p>
                </div>
                <div class="flex flex-wrap gap-1.5">
                    <StatusBadge :status="signature.intact ? 'active' : 'inactive'" :label="signature.intact ? 'Intact' : 'Not intact'" />
                    <StatusBadge
                        v-if="signature.trusted !== null"
                        :status="signature.trusted ? 'active' : 'inactive'"
                        :label="signature.trusted ? 'Chains to PNPKI' : 'Not a PNPKI certificate'"
                    />
                </div>
            </li>
        </ul>

        <p v-if="!copy.chain_checked" class="text-xs text-text-muted">
            IMIS checked that every signature is intact and that nothing changed after the last one. Whether the certificates chain to PNPKI isn't
            checked until the PNPKI certificate bundle is set up (PNPKI_CA_BUNDLE).
        </p>
    </div>
</template>
