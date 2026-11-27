<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Modal from '@cscro8/ui/Components/Feedback/Modal.vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';

/**
 * Crops a picture to passport size for the PDS: a frame in the photo's
 * own proportions (3.5 × 4.5 cm) that the person drags and zooms the
 * picture inside, then saved as exactly `size` pixels (413 × 531, 300 dpi),
 * the size uploads must have. Mouse, touch and keyboard (arrows move,
 * + and − zoom). Emits `crop` with the new JPEG file.
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    file: { type: Object, default: null /** File */ },
    size: { type: Object, required: true /** { width, height } */ },
    /** Why the cropper opened (e.g. "This one is 600 × 800"), shown at the top. */
    message: { type: String, default: null },
});

const emit = defineEmits(['close', 'crop']);

// The frame on screen, in CSS pixels, in the photo's proportions (its inside: the border is outside it).
const FRAME_WIDTH = 252;
const frame = computed(() => ({ width: FRAME_WIDTH, height: Math.round((FRAME_WIDTH * props.size.height) / props.size.width) }));

// An oval where a face usually sits on a passport photo (geometry, not a design value).
const FACE_GUIDE = { top: '14%', width: '62%', height: '58%', borderRadius: '50%' };

const image = ref(null);
const url = ref(null);
const natural = ref({ width: 0, height: 0 });
const zoom = ref(1); // 1 = the picture just covers the frame
const offset = ref({ x: 0, y: 0 }); // the picture's top-left within the frame
const saving = ref(false);

const coverScale = computed(() => (natural.value.width ? Math.max(frame.value.width / natural.value.width, frame.value.height / natural.value.height) : 1));
const scale = computed(() => coverScale.value * zoom.value);

/** Keeps the picture covering the whole frame. */
const clamp = ({ x, y }) => ({
    x: Math.min(0, Math.max(frame.value.width - natural.value.width * scale.value, x)),
    y: Math.min(0, Math.max(frame.value.height - natural.value.height * scale.value, y)),
});

const centre = () => {
    offset.value = clamp({
        x: (frame.value.width - natural.value.width * scale.value) / 2,
        y: (frame.value.height - natural.value.height * scale.value) / 2,
    });
};

const load = (file) => {
    if (url.value) URL.revokeObjectURL(url.value);
    url.value = file ? URL.createObjectURL(file) : null;
    natural.value = { width: 0, height: 0 };
    zoom.value = 1;
};

watch(
    () => [props.open, props.file],
    ([open, file]) => open && load(file),
    { immediate: true },
);
onBeforeUnmount(() => url.value && URL.revokeObjectURL(url.value));

const onLoad = () => {
    natural.value = { width: image.value.naturalWidth, height: image.value.naturalHeight };
    centre();
};

/** Zooms about the frame's centre, so what's in the middle stays there. */
const setZoom = (value) => {
    const next = Math.min(4, Math.max(1, Number(value)));
    const cx = (frame.value.width / 2 - offset.value.x) / scale.value;
    const cy = (frame.value.height / 2 - offset.value.y) / scale.value;
    zoom.value = next;
    offset.value = clamp({ x: frame.value.width / 2 - cx * scale.value, y: frame.value.height / 2 - cy * scale.value });
};

// Dragging (mouse or touch).
let drag = null;
const onPointerDown = (event) => {
    drag = { x: event.clientX, y: event.clientY, start: { ...offset.value } };
    event.currentTarget.setPointerCapture(event.pointerId);
};
const onPointerMove = (event) => {
    if (!drag) return;
    offset.value = clamp({ x: drag.start.x + event.clientX - drag.x, y: drag.start.y + event.clientY - drag.y });
};
const onPointerUp = () => (drag = null);
const onWheel = (event) => setZoom(zoom.value * (event.deltaY < 0 ? 1.1 : 1 / 1.1));

const onKeydown = (event) => {
    const step = event.shiftKey ? 20 : 5;
    const moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
    if (moves[event.key]) {
        event.preventDefault();
        offset.value = clamp({ x: offset.value.x + moves[event.key][0], y: offset.value.y + moves[event.key][1] });
    } else if (event.key === '+' || event.key === '=') {
        event.preventDefault();
        setZoom(zoom.value * 1.1);
    } else if (event.key === '-') {
        event.preventDefault();
        setZoom(zoom.value / 1.1);
    }
};

/** The part of the picture in the frame, in the picture's own pixels. */
const source = computed(() => ({
    x: -offset.value.x / scale.value,
    y: -offset.value.y / scale.value,
    width: frame.value.width / scale.value,
    height: frame.value.height / scale.value,
}));
// Enlarging a small picture to passport size blurs it.
const blurry = computed(() => natural.value.width > 0 && source.value.width < props.size.width * 0.9);

const save = () => {
    saving.value = true;
    const canvas = document.createElement('canvas');
    canvas.width = props.size.width;
    canvas.height = props.size.height;
    const context = canvas.getContext('2d');
    context.fillStyle = '#fff';
    context.fillRect(0, 0, canvas.width, canvas.height);
    context.imageSmoothingQuality = 'high';
    context.drawImage(image.value, source.value.x, source.value.y, source.value.width, source.value.height, 0, 0, canvas.width, canvas.height);
    canvas.toBlob(
        (blob) => {
            saving.value = false;
            if (blob) emit('crop', new File([blob], 'pds-photo.jpg', { type: 'image/jpeg' }));
        },
        'image/jpeg',
        0.92,
    );
};
</script>

<template>
    <Modal :open="open" title="Crop your photo to passport size" icon="camera" tone="primary" size="md" @close="emit('close')">
        <div class="space-y-4">
            <p v-if="message" class="rounded-md border border-warning-border bg-warning-bg px-3 py-2 text-sm text-warning-fg">{{ message }}</p>
            <p class="text-sm text-text-muted">
                Drag the picture to place your face in the frame, and zoom with the slider. It is saved as exactly {{ size.width }} × {{ size.height }} pixels (3.5 × 4.5 cm).
            </p>

            <div class="flex flex-col items-center gap-3">
                <div
                    class="relative box-content cursor-grab touch-none overflow-hidden rounded-md border-2 border-border-strong bg-surface-muted select-none focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring active:cursor-grabbing"
                    :style="{ width: `${frame.width}px`, height: `${frame.height}px` }"
                    tabindex="0"
                    role="application"
                    aria-label="Photo crop frame. Use the arrow keys to move the picture, and + or − to zoom."
                    @pointerdown="onPointerDown"
                    @pointermove="onPointerMove"
                    @pointerup="onPointerUp"
                    @pointercancel="onPointerUp"
                    @wheel.prevent="onWheel"
                    @keydown="onKeydown"
                >
                    <img
                        v-if="url"
                        ref="image"
                        :src="url"
                        alt=""
                        draggable="false"
                        class="absolute top-0 left-0 max-w-none origin-top-left"
                        :style="{ width: `${natural.width * scale}px`, height: `${natural.height * scale}px`, transform: `translate(${offset.x}px, ${offset.y}px)` }"
                        @load="onLoad"
                    />
                    <!-- Where a face usually sits on a passport photo -->
                    <span class="pointer-events-none absolute left-1/2 -translate-x-1/2 border-2 border-dashed border-surface/80" :style="FACE_GUIDE" aria-hidden="true" />
                </div>

                <label class="flex w-full max-w-xs items-center gap-3 text-sm text-text">
                    <span class="shrink-0">Zoom</span>
                    <input type="range" min="1" max="4" step="0.01" :value="zoom" class="w-full accent-primary" @input="setZoom($event.target.value)" />
                </label>
            </div>

            <p v-if="blurry" class="text-xs text-warning-fg">This picture is small, so the photo may look blurry when printed. Use a larger picture if you can.</p>
        </div>

        <template #footer>
            <Button variant="secondary" @click="emit('close')">Cancel</Button>
            <Button :loading="saving" :disabled="!natural.width" icon="check" @click="save">Use this photo</Button>
        </template>
    </Modal>
</template>
