<script setup>
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';
import TextInput from '@cscro8/ui/Components/Forms/TextInput.vue';
import InputLabel from '@cscro8/ui/Components/Forms/InputLabel.vue';
import InputError from '@cscro8/ui/Components/Forms/InputError.vue';
import { formatCalendarDate } from '../../Utils/datetime';

/**
 * One DTR day's raw biometric punches, for HRD. Anyone who can read the DTR
 * sees them; `dtr.manage` can add a missing punch and void or restore one,
 * each with a reason. A voided punch stays on file and is not counted.
 */
const props = defineProps({
    employeeId: { type: Number, required: true },
    date: { type: String, default: null },
    canManage: { type: Boolean, default: false },
});

const emit = defineEmits(['close']);

const base = () => `/admin/hr/employees/${props.employeeId}/dtr/punches`;
const punches = ref([]);
const biometricId = ref(null);
const loading = ref(false);
const failed = ref(false);

const form = useForm({ date: '', time: '', reason: '' });
// The punch whose "Void" reason box is open.
const voiding = ref(null);
const voidForm = useForm({ reason: '' });

const load = async () => {
    if (!props.date) return;

    loading.value = true;
    failed.value = false;
    try {
        const response = await fetch(`${base()}?date=${props.date}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(String(response.status));
        const data = await response.json();
        punches.value = data.punches;
        biometricId.value = data.biometric_id;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
};

watch(
    () => props.date,
    (date) => {
        punches.value = [];
        voiding.value = null;
        form.reset();
        form.clearErrors();
        if (date) {
            form.date = date;
            load();
        }
    },
);

const after = { preserveScroll: true, onSuccess: () => load() };

const add = () => form.post(base(), { ...after, onSuccess: () => { form.reset('time', 'reason'); load(); } });

const startVoid = (punch) => {
    voiding.value = punch.id;
    voidForm.reset();
    voidForm.clearErrors();
};

const confirmVoid = (punch) =>
    voidForm.patch(`${base()}/${punch.id}/void`, { ...after, onSuccess: () => { voiding.value = null; load(); } });

const restore = (punch) => router.patch(`${base()}/${punch.id}/restore`, {}, after);

const clock = (time) => time.slice(0, 5);
</script>

<template>
    <Modal :open="date !== null" :title="date ? `Punches on ${formatCalendarDate(date)}` : 'Punches'" icon="clock" tone="primary" @close="emit('close')">
        <div class="space-y-4">
            <p class="text-sm text-text-muted">
                The biometric punches of this DTR day (4:00 AM to 4:00 AM). Punches within two minutes of each other count once. A voided punch stays on file and is not counted.
            </p>

            <p v-if="!biometricId && !loading && !failed" class="rounded-md bg-warning-bg px-3 py-2 text-sm text-warning-fg">This employee has no biometric ID yet, so there are no punches to show or add.</p>
            <p v-if="failed" class="rounded-md bg-danger-bg px-3 py-2 text-sm text-danger-fg">The punches could not be loaded. Close this and try again.</p>

            <ul v-if="punches.length" class="divide-y divide-border rounded-md border border-border">
                <li v-for="punch in punches" :key="punch.id" class="space-y-2 px-3 py-2">
                    <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                        <span class="text-base font-semibold tabular-nums" :class="punch.voided ? 'text-text-muted line-through' : 'text-text'">{{ clock(punch.time) }}</span>
                        <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-semibold" :class="punch.source === 'manual' ? 'bg-info-bg text-link' : 'bg-surface-muted text-text-muted'">
                            {{ punch.source === 'manual' ? 'Added by HRD' : 'Biometric device' }}
                        </span>
                        <span v-if="punch.voided" class="inline-flex rounded-full bg-danger-bg px-2.5 py-0.5 text-xs font-semibold text-danger-fg">Voided</span>
                        <span v-if="canManage" class="ml-auto flex gap-2">
                            <Button v-if="punch.voided" size="sm" variant="secondary" @click="restore(punch)">Restore</Button>
                            <Button v-else-if="voiding !== punch.id" size="sm" variant="danger" @click="startVoid(punch)">Void</Button>
                        </span>
                    </div>
                    <p v-if="punch.reason" class="text-xs text-text-muted">Reason: {{ punch.reason }}</p>
                    <p v-if="punch.void_reason" class="text-xs text-text-muted">Voided because: {{ punch.void_reason }}</p>

                    <form v-if="voiding === punch.id" class="flex flex-wrap items-end gap-2" @submit.prevent="confirmVoid(punch)">
                        <div class="min-w-48 flex-1">
                            <TextInput :id="`void-reason-${punch.id}`" v-model="voidForm.reason" label="Why is this punch wrong?" required maxlength="255" :error="voidForm.errors.reason" />
                        </div>
                        <Button size="sm" variant="secondary" @click="voiding = null">Cancel</Button>
                        <Button size="sm" type="submit" variant="danger" :loading="voidForm.processing" :disabled="!voidForm.reason.trim()">Void punch</Button>
                    </form>
                </li>
            </ul>
            <p v-else-if="!loading && biometricId && !failed" class="text-sm text-text-muted">No punches on this day.</p>

            <form v-if="canManage && biometricId" class="space-y-3 border-t border-border pt-4" @submit.prevent="add">
                <p class="text-sm font-medium text-text">Add a missing punch</p>
                <div class="grid gap-3 sm:grid-cols-[10rem_1fr]">
                    <div>
                        <InputLabel for="punch-time" required>Time</InputLabel>
                        <input id="punch-time" v-model="form.time" type="time" class="filter-control mt-1 w-full" required />
                        <InputError :message="form.errors.time" />
                    </div>
                    <TextInput id="punch-reason" v-model="form.reason" label="Reason (for example forgot to punch out)" required maxlength="255" :error="form.errors.reason" />
                </div>
                <div class="flex justify-end">
                    <Button type="submit" :loading="form.processing" :disabled="!form.time || !form.reason.trim()" icon="plus">Add punch</Button>
                </div>
            </form>
        </div>
        <template #footer>
            <Button variant="secondary" icon="close" @click="emit('close')">Close</Button>
        </template>
    </Modal>
</template>
