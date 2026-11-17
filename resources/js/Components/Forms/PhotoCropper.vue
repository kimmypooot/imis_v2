<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import Modal from '../Feedback/Modal.vue';
import Button from '../UI/Button.vue';
import Icon from '../UI/Icon.vue';

/**
 * Square cropper for profile photos. Drag to position, slide / scroll to
 * zoom; the circle shows what the avatar will display. Emits a square JPEG
 * `File` (the server still re-encodes it, so this only fixes the framing).
 */
const props = defineProps({
    open: { type: Boolean, required: true },
    file: { type: File, default: null },
});

const emit = defineEmits(['cancel', 'crop']);

const VIEW = 288;
const OUTPUT = 512;
const MAX_ZOOM = 4;

const src = ref(null);
const image = ref(null);
const zoom = ref(1);
const x = ref(0);
const y = ref(0);
const dragging = ref(false);
let dragStart = null;

const baseScale = computed(() => (image.value ? VIEW / Math.min(image.value.naturalWidth, image.value.naturalHeight) : 1));
const scale = computed(() => baseScale.value * zoom.value);
const drawn = computed(() => ({
    width: image.value ? image.value.naturalWidth * scale.value : 0,
    height: image.value ? image.value.naturalHeight * scale.value : 0,
}));

const clamp = () => {
    x.value = Math.min(0, Math.max(VIEW - drawn.value.width, x.value));
    y.value = Math.min(0, Math.max(VIEW - drawn.value.height, y.value));
};

const reset = () => {
    zoom.value = 1;
    if (image.value) {
        x.value = (VIEW - drawn.value.width) / 2;
        y.value = (VIEW - drawn.value.height) / 2;
    }
};

const setZoom = (next) => {
    const clamped = Math.min(MAX_ZOOM, Math.max(1, next));
    // Zoom around the middle of the frame.
    const centerX = (VIEW / 2 - x.value) / scale.value;
    const centerY = (VIEW / 2 - y.value) / scale.value;
    zoom.value = clamped;
    x.value = VIEW / 2 - centerX * scale.value;
    y.value = VIEW / 2 - centerY * scale.value;
    clamp();
};

const release = () => {
    if (src.value) URL.revokeObjectURL(src.value);
    src.value = null;
    image.value = null;
};

watch(
    () => [props.open, props.file],
    () => {
        release();
        if (!props.open || !props.file) return;

        const url = URL.createObjectURL(props.file);
        const img = new Image();
        img.onload = () => {
            image.value = img;
            reset();
        };
        img.src = url;
        src.value = url;
    },
);

const onPointerDown = (event) => {
    dragging.value = true;
    dragStart = { px: event.clientX, py: event.clientY, x: x.value, y: y.value };
    event.currentTarget.setPointerCapture(event.pointerId);
};

const onPointerMove = (event) => {
    if (!dragging.value) return;
    x.value = dragStart.x + event.clientX - dragStart.px;
    y.value = dragStart.y + event.clientY - dragStart.py;
    clamp();
};

const onPointerUp = () => {
    dragging.value = false;
};

const onWheel = (event) => setZoom(zoom.value - event.deltaY / 400);

const onKeydown = (event) => {
    const step = 10;
    const moves = { ArrowLeft: [step, 0], ArrowRight: [-step, 0], ArrowUp: [0, step], ArrowDown: [0, -step] };
    const move = moves[event.key];
    if (move) {
        event.preventDefault();
        x.value += move[0];
        y.value += move[1];
        clamp();
    } else if (event.key === '+' || event.key === '=') {
        setZoom(zoom.value + 0.2);
    } else if (event.key === '-') {
        setZoom(zoom.value - 0.2);
    }
};

// Keep Escape from also closing a parent dialog this cropper sits inside.
const onEscape = (event) => {
    if (event.key === 'Escape' && props.open) {
        event.stopImmediatePropagation();
        emit('cancel');
    }
};
watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) window.addEventListener('keydown', onEscape, true);
        else window.removeEventListener('keydown', onEscape, true);
    },
);

const apply = () => {
    if (!image.value) return;

    const canvas = document.createElement('canvas');
    canvas.width = OUTPUT;
    canvas.height = OUTPUT;
    const context = canvas.getContext('2d');
    context.fillStyle = '#ffffff';
    context.fillRect(0, 0, OUTPUT, OUTPUT);
    context.imageSmoothingQuality = 'high';
    const side = VIEW / scale.value;
    context.drawImage(image.value, -x.value / scale.value, -y.value / scale.value, side, side, 0, 0, OUTPUT, OUTPUT);

    canvas.toBlob(
        (blob) => {
            if (!blob) return;
            const name = (props.file?.name ?? 'photo').replace(/\.[^.]+$/, '') + '.jpg';
            emit('crop', new File([blob], name, { type: 'image/jpeg' }));
        },
        'image/jpeg',
        0.92,
    );
};

onBeforeUnmount(() => {
    release();
    window.removeEventListener('keydown', onEscape, true);
});
</script>

<template>
    <Modal :open="open" title="Position profile photo" icon="camera" size="sm" @close="emit('cancel')">
        <div class="flex flex-col items-center gap-4">
            <div
                class="relative touch-none select-none overflow-hidden rounded-md bg-surface-muted focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
                :style="{ width: `${VIEW}px`, height: `${VIEW}px`, maxWidth: '100%' }"
                tabindex="0"
                role="application"
                aria-label="Photo position. Drag, or use the arrow keys to move and plus and minus to zoom."
                data-autofocus
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"
                @wheel.prevent="onWheel"
                @keydown="onKeydown"
            >
                <img
                    v-if="image"
                    :src="src"
                    alt=""
                    draggable="false"
                    class="pointer-events-none absolute max-w-none"
                    :style="{ left: `${x}px`, top: `${y}px`, width: `${drawn.width}px`, height: `${drawn.height}px` }"
                />
                <!-- The circle is what the avatar shows; the dim area is cropped to it. -->
                <div class="pointer-events-none absolute inset-0 rounded-full shadow-[0_0_0_999px_rgb(0_0_0/0.55)] ring-2 ring-white/80" aria-hidden="true" />
            </div>

            <label class="flex w-full items-center gap-3 text-xs text-text-muted">
                <Icon name="search" size="sm" />
                <span class="sr-only">Zoom</span>
                <input
                    type="range"
                    min="1"
                    :max="MAX_ZOOM"
                    step="0.01"
                    :value="zoom"
                    class="w-full accent-primary"
                    @input="setZoom(Number($event.target.value))"
                />
            </label>
            <p class="text-xs text-text-muted">Drag the photo to position it. The circle is what shows on the avatar.</p>
        </div>

        <template #footer>
            <Button variant="outline" @click="emit('cancel')">Cancel</Button>
            <Button :disabled="!image" @click="apply">Use photo</Button>
        </template>
    </Modal>
</template>
