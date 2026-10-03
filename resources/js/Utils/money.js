/**
 * Philippine peso, two decimals ("₱35,434.00"). Null shows as a dash.
 */
export function formatPeso(value) {
    if (value === null || value === undefined || value === '') return '—';

    return `₱${Number(value).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}
