import { ref } from 'vue';

/**
 * Tracks whether an Inertia visit made by this page is in flight, for
 * BusyRegion. Spread `handlers` into the visit's options:
 *
 *   const { busy, handlers } = useBusy();
 *   router.get(url, data, { preserveState: true, ...handlers });
 */
export function useBusy() {
    const busy = ref(false);
    const handlers = {
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    };

    return { busy, handlers };
}
