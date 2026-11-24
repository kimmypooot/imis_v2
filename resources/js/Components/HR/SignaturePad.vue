<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';
import Button from '@cscro8/ui/Components/UI/Button.vue';

/**
 * A box to sign in with a finger, pen or mouse. The model is a PNG data
 * URL of the strokes (null while empty); the server cleans and crops it.
 * The strokes are always dark on white here, whatever the theme, because
 * that is how the signature will print.
 */
const model = defineModel({ type: String, default: null });

const canvas = ref(null);
const drawing = ref(false);
const empty = ref(true);
let context = null;
let last = null;

const resize = () => {
    const element = canvas.value;
    if (!element) return;
    const ratio = window.devicePixelRatio || 1;
    const { width, height } = element.getBoundingClientRect();
    element.width = Math.round(width * ratio);
    element.height = Math.round(height * ratio);
    context = element.getContext('2d');
    context.scale(ratio, ratio);
    context.lineWidth = 2.4;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = '#0c123c';
    empty.value = true;
    model.value = null;
};

const point = (event) => {
    const rect = canvas.value.getBoundingClientRect();
    return { x: event.clientX - rect.left, y: event.clientY - rect.top };
};

const start = (event) => {
    event.preventDefault();
    canvas.value.setPointerCapture(event.pointerId);
    drawing.value = true;
    last = point(event);
    // A tap leaves a dot.
    context.beginPath();
    context.arc(last.x, last.y, 1.1, 0, Math.PI * 2);
    context.fillStyle = '#0c123c';
    context.fill();
};

const move = (event) => {
    if (!drawing.value) return;
    const next = point(event);
    context.beginPath();
    context.moveTo(last.x, last.y);
    context.lineTo(next.x, next.y);
    context.stroke();
    last = next;
    empty.value = false;
};

const stop = () => {
    if (!drawing.value) return;
    drawing.value = false;
    empty.value = false;
    model.value = canvas.value.toDataURL('image/png');
};

const clear = () => {
    resize();
};

onMounted(() => {
    resize();
    window.addEventListener('resize', resize);
});
onBeforeUnmount(() => window.removeEventListener('resize', resize));

defineExpose({ clear });
</script>

<template>
    <div>
        <div class="relative rounded-md border border-dashed border-border-strong bg-white">
            <canvas
                ref="canvas"
                class="block h-40 w-full touch-none cursor-crosshair rounded-md"
                aria-label="Signature box. Draw your signature here."
                @pointerdown="start"
                @pointermove="move"
                @pointerup="stop"
                @pointercancel="stop"
                @pointerleave="stop"
            />
            <span v-if="empty" class="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-slate-400">Sign here</span>
            <span class="pointer-events-none absolute inset-x-6 bottom-8 border-t border-slate-300" aria-hidden="true" />
        </div>
        <div class="mt-2 flex justify-end">
            <Button variant="secondary" size="sm" icon="close" :disabled="empty" @click="clear">Clear</Button>
        </div>
    </div>
</template>
