/**
 * Timestamp helpers for `<input type="datetime-local">`, which has no
 * timezone. The server sends/stores ISO-8601 instants (UTC); these convert
 * to and from the browser's local wall-clock time so an admin in Manila
 * picking "8:00 AM" means 8:00 AM Manila time, not 8:00 AM UTC.
 */

const pad = (value) => String(value).padStart(2, '0');

/** ISO-8601 string (or null) → "YYYY-MM-DDTHH:mm" in local time, for the input. */
export function toLocalInput(iso) {
    if (!iso) return '';

    const date = new Date(iso);

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

/** Input value (local wall-clock time) → ISO-8601 instant, or null when empty. */
export function fromLocalInput(value) {
    return value ? new Date(value).toISOString() : null;
}

/** ISO-8601 string → human-readable local date/time. */
export function formatDateTime(iso) {
    if (!iso) return '';

    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}

/** ISO-8601 string → human-readable local date only. */
export function formatDate(iso) {
    if (!iso) return '';

    return new Date(iso).toLocaleDateString(undefined, { dateStyle: 'long' });
}
