import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Admin-3, Admin → Branding: the office's logos, sign-in background and
 * header name (shared as `branding` on every page), falling back to the
 * built-in ones.
 */
const builtIn = {
    logo: '/images/brand/csc-logo.png',
    app_logo: '/images/brand/CSC-IMIS.png',
    login_background: '/images/brand/cscbg_facade.jpeg',
    header_name: 'CSC RO VIII',
};

export function useBranding() {
    const page = usePage();

    return computed(() => ({ ...builtIn, ...(page.props.branding ?? {}) }));
}
