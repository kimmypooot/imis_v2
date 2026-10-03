/**
 * How each PDS state (PdsSubmissions::status) is presented. `badge` is a
 * StatusBadge status value; `canSign` is whether there's something new
 * to e-sign.
 */
export const pdsStates = {
    draft: { label: 'Not yet submitted', badge: 'draft', canSign: true, text: 'Fill in your PDS, then sign and submit it for HR to verify.' },
    submitted: { label: 'Awaiting verification', badge: 'info', canSign: false, text: 'Signed and submitted. HR will verify it.' },
    changed_since_submitted: {
        label: 'Changed since submitted',
        badge: 'warning',
        canSign: true,
        text: 'You changed your PDS after submitting it. Sign and submit again so HR verifies the latest.',
    },
    returned: { label: 'Returned by HR', badge: 'inactive', canSign: true, text: 'HR returned your PDS. Make the changes they asked for, then sign and submit again.' },
    verified: { label: 'Verified', badge: 'active', canSign: false, text: 'Your PDS is verified and filed in your 201 file.' },
    changed_since_verified: {
        label: 'Changed since last verified',
        badge: 'warning',
        canSign: true,
        text: 'You changed your PDS after HR verified it. Sign and submit again so your 201 file stays current.',
    },
};
