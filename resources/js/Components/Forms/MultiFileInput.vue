<script setup>
import { computed, ref } from 'vue';
import Icon from '@cscro8/ui/Components/UI/Icon.vue';

/**
 * The shared FileInput's drop zone, for several files at once: click it
 * (or tab to it and press Space or Enter) to browse, or drag files onto
 * it. Chosen files are listed below with their size and a Remove button;
 * choosing or dropping more adds to the list (the same file isn't added
 * twice). Files the field doesn't accept, or over `maxSizeMb`, are
 * refused on the spot with the reason.
 *
 * `v-model` is the array of chosen `File`s. Put an `InputLabel` with the
 * same `id` above it for the visible label.
 */
const props = defineProps({
    id: { type: String, required: true },
    modelValue: { type: Array, default: () => [] },
    accept: { type: String, default: null },
    maxSizeMb: { type: Number, default: null },
    maxFiles: { type: Number, default: null },
    invalid: { type: Boolean, default: false },
    hint: { type: String, default: null },
});

const emit = defineEmits(['update:modelValue']);

const inputRef = ref(null);
const dragging = ref(false);
const rejections = ref([]);

const tokens = computed(() => (props.accept ?? '').split(',').map((token) => token.trim().toLowerCase()).filter(Boolean));
const matchesAccept = (candidate) => {
    if (!tokens.value.length) return true;
    const name = candidate.name.toLowerCase();
    const type = (candidate.type || '').toLowerCase();
    return tokens.value.some((token) => {
        if (token.startsWith('.')) return name.endsWith(token);
        if (token.endsWith('/*')) return type.startsWith(token.slice(0, -1));
        return type === token;
    });
};

const formatSize = (bytes) => {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
};
const extensionOf = (name) => (name.includes('.') ? name.split('.').pop().toUpperCase().slice(0, 4) : '');
const keyOf = (candidate) => `${candidate.name}|${candidate.size}|${candidate.lastModified}`;

const add = (list) => {
    const chosen = [...props.modelValue];
    const known = new Set(chosen.map(keyOf));
    const refused = [];

    for (const candidate of Array.from(list ?? [])) {
        if (!matchesAccept(candidate)) {
            refused.push(`${candidate.name}: not an accepted file type.`);
        } else if (props.maxSizeMb && candidate.size > props.maxSizeMb * 1024 * 1024) {
            refused.push(`${candidate.name}: larger than ${props.maxSizeMb} MB.`);
        } else if (props.maxFiles && chosen.length >= props.maxFiles) {
            refused.push(`${candidate.name}: no more than ${props.maxFiles} files at a time.`);
        } else if (!known.has(keyOf(candidate))) {
            known.add(keyOf(candidate));
            chosen.push(candidate);
        }
    }

    rejections.value = refused;
    emit('update:modelValue', chosen);
};

const onChange = (event) => {
    add(event.target.files);
    // Lets the same file be picked again after it was removed.
    event.target.value = '';
};
const onDragOver = (event) => {
    event.preventDefault();
    dragging.value = true;
};
const onDragLeave = (event) => {
    if (!event.currentTarget.contains(event.relatedTarget)) dragging.value = false;
};
const onDrop = (event) => {
    event.preventDefault();
    dragging.value = false;
    add(event.dataTransfer?.files);
};
const remove = (index) => {
    rejections.value = [];
    emit('update:modelValue', props.modelValue.filter((_, position) => position !== index));
};
const clear = () => {
    rejections.value = [];
    emit('update:modelValue', []);
};

const hintText = computed(() => {
    if (props.hint) return props.hint;
    const parts = [];
    const types = tokens.value.map((token) => (token.startsWith('.') ? token.slice(1).toUpperCase() : token.split('/').pop().toUpperCase()));
    if (types.length) parts.push([...new Set(types)].join(', '));
    if (props.maxSizeMb) parts.push(`up to ${props.maxSizeMb} MB each`);
    return parts.join(' · ');
});
const describedBy = computed(() => `${props.id}-hint`);
// The same states as the shared FileInput's drop zone.
const zoneClass = computed(() => {
    if (dragging.value) return 'cursor-copy border-primary bg-info-bg';
    if (props.invalid || rejections.value.length) return 'cursor-pointer border-danger bg-danger-bg/40 hover:bg-danger-bg';
    return 'cursor-pointer border-border-strong bg-surface hover:border-primary hover:bg-surface-muted';
});
const chipClass = (name) => {
    const extension = extensionOf(name).toLowerCase();
    if (['xlsx', 'xls', 'csv'].includes(extension)) return 'bg-success-bg text-success-fg';
    if (extension === 'pdf') return 'bg-danger-bg text-danger-fg';
    return 'bg-info-bg text-link';
};
const totalSize = computed(() => props.modelValue.reduce((sum, candidate) => sum + candidate.size, 0));
</script>

<template>
    <div @dragover="onDragOver" @dragenter="onDragOver" @dragleave="onDragLeave" @drop="onDrop">
        <input
            :id="id"
            ref="inputRef"
            type="file"
            multiple
            class="peer sr-only"
            :accept="accept"
            :aria-invalid="invalid || rejections.length > 0"
            :aria-describedby="describedBy"
            @change="onChange"
        />

        <label
            :for="id"
            class="flex items-center gap-3 rounded-lg border-2 border-dashed px-4 text-left transition-colors duration-150 ease-standard peer-focus-visible:ring-2 peer-focus-visible:ring-focus-ring peer-focus-visible:ring-offset-2 motion-reduce:transition-none"
            :class="[zoneClass, modelValue.length ? 'py-3' : 'flex-col py-6 text-center']"
        >
            <span
                class="flex shrink-0 items-center justify-center rounded-full transition duration-150 ease-standard motion-reduce:transition-none"
                :class="[modelValue.length ? 'h-9 w-9' : 'h-12 w-12', dragging ? 'scale-110 bg-primary text-white' : 'bg-info-bg text-link']"
                aria-hidden="true"
            >
                <Icon :name="dragging ? 'download' : 'upload'" :size="modelValue.length ? 'md' : 'lg'" />
            </span>
            <span class="flex flex-col gap-0.5">
                <span class="text-sm text-text">
                    <template v-if="dragging"><span class="font-semibold text-link">Drop to add</span></template>
                    <template v-else-if="modelValue.length"><span class="font-semibold text-link">Add more files</span> or drag them here</template>
                    <template v-else><span class="font-semibold text-link">Choose files</span> or drag and drop them here</template>
                </span>
                <span v-if="hintText" :id="describedBy" class="text-xs text-text-muted">{{ hintText }}</span>
            </span>
        </label>

        <div v-if="modelValue.length" class="mt-3">
            <div class="mb-1.5 flex items-center justify-between text-xs text-text-muted">
                <span>{{ modelValue.length }} {{ modelValue.length === 1 ? 'file' : 'files' }} · {{ formatSize(totalSize) }}</span>
                <button type="button" class="font-medium text-link hover:underline" @click="clear">Remove all</button>
            </div>
            <ul class="max-h-64 divide-y divide-border overflow-y-auto rounded-lg border border-border bg-surface">
                <li v-for="(candidate, index) in modelValue" :key="keyOf(candidate)" class="flex items-center gap-3 px-3 py-2">
                    <span class="flex h-9 w-9 shrink-0 flex-col items-center justify-center rounded-md" :class="chipClass(candidate.name)" aria-hidden="true">
                        <Icon name="documentText" size="sm" />
                        <span class="text-2xs leading-none font-bold">{{ extensionOf(candidate.name) }}</span>
                    </span>
                    <div class="min-w-0 flex-1">
                        <p class="truncate text-sm font-medium text-text" :title="candidate.name">{{ candidate.name }}</p>
                        <p class="text-xs text-text-muted">{{ formatSize(candidate.size) }}</p>
                    </div>
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-text-muted transition-colors hover:bg-danger-bg hover:text-danger-fg focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring max-sm:min-h-10 max-sm:min-w-10"
                        :aria-label="`Remove ${candidate.name}`"
                        @click="remove(index)"
                    >
                        <Icon name="close" size="sm" />
                    </button>
                </li>
            </ul>
        </div>

        <ul v-if="rejections.length" role="alert" class="mt-2 space-y-1 text-xs text-danger-fg">
            <li v-for="reason in rejections" :key="reason" class="flex items-start gap-1.5">
                <Icon name="exclamation" size="xs" class="mt-0.5 shrink-0" />
                {{ reason }}
            </li>
        </ul>
        <p class="sr-only" aria-live="polite">{{ modelValue.length ? `${modelValue.length} files chosen` : '' }}</p>
    </div>
</template>
