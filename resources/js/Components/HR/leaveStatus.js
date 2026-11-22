/**
 * HR-6b: StatusBadge tones for leave application statuses and step actions.
 */
export const leaveStatusTones = {
    for_review: 'warning',
    returned: 'expired',
    approved: 'active',
    disapproved: 'inactive',
    cancelled: 'logged_out',
};

export const leaveActionTones = {
    pending: 'draft',
    recommended: 'active',
    not_recommended: 'warning',
    certified: 'active',
    insufficient_credits: 'inactive',
    approved: 'active',
    disapproved: 'inactive',
    returned: 'expired',
};

/**
 * "Jun 8 – Jun 12, 2026 (5 days)", or a half day; dates chosen one by one
 * as the server words them ("June 2-4, 9 & 12, 2026").
 */
export const leaveDates = (application, format) => {
    const range = application.dates?.length && application.dates_label
        ? application.dates_label
        : application.date_from === application.date_to
            ? format(application.date_from)
            : `${format(application.date_from)} – ${format(application.date_to)}`;
    const days = Number(application.days).toLocaleString(undefined, { maximumFractionDigits: 3 });

    return application.is_half_day ? `${range} (half day, ${application.half_day_period})` : `${range} (${days} ${Number(application.days) === 1 ? 'day' : 'days'})`;
};
