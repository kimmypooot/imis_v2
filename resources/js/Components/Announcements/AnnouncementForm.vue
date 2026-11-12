<script setup>
import TextInput from '../Forms/TextInput.vue';
import InputError from '../Forms/InputError.vue';
import FormGroup from '../Forms/FormGroup.vue';
import Textarea from '../Forms/Textarea.vue';
import Checkbox from '../Forms/Checkbox.vue';
import Button from '../UI/Button.vue';

/**
 * Shared create/edit fields. `form` is the parent's Inertia useForm()
 * object; publish_at/expires_at hold datetime-local strings here and are
 * converted to ISO instants by the parent on submit (Utils/datetime.js).
 */
defineProps({
    form: {
        type: Object,
        required: true,
    },
    submitLabel: {
        type: String,
        required: true,
    },
    publishDateRequired: {
        type: Boolean,
        default: false,
    },
});

defineEmits(['submit']);
</script>

<template>
    <form class="space-y-5" @submit.prevent="$emit('submit')">
        <div>
            <TextInput id="title" v-model="form.title" label="Title" required :error="form.errors.title" />
        </div>

        <FormGroup
            id="summary"
            label="Summary"
            helper-text="One or two sentences shown on listings and the portal home. Up to 500 characters."
            :error="form.errors.summary"
        >
            <Textarea id="summary" v-model="form.summary" :rows="2" :invalid="!!form.errors.summary" />
        </FormGroup>

        <FormGroup
            id="body"
            label="Body"
            required
            helper-text="Plain text. Separate paragraphs with a blank line. HTML is shown as text, not formatted."
            :error="form.errors.body"
        >
            <Textarea id="body" v-model="form.body" :rows="12" required :invalid="!!form.errors.body" />
        </FormGroup>

        <div class="grid gap-5 sm:grid-cols-2">
            <FormGroup
                id="publish_at"
                label="Publish date"
                :required="publishDateRequired"
                helper-text="Leave empty to publish immediately when you click Publish. A future date schedules it."
                :error="form.errors.publish_at"
            >
                <input
                    id="publish_at"
                    v-model="form.publish_at"
                    type="datetime-local"
                    :required="publishDateRequired"
                    :aria-invalid="!!form.errors.publish_at"
                    class="block w-full form-control px-3 py-2 text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                />
            </FormGroup>

            <FormGroup
                id="expires_at"
                label="Expiration"
                helper-text="Optional. The announcement leaves the portal at this time."
                :error="form.errors.expires_at"
            >
                <input
                    id="expires_at"
                    v-model="form.expires_at"
                    type="datetime-local"
                    :aria-invalid="!!form.errors.expires_at"
                    class="block w-full form-control px-3 py-2 text-text shadow-xs focus:border-primary focus:outline-none focus:ring-2 focus:ring-focus-ring"
                />
            </FormGroup>
        </div>

        <Checkbox id="featured" v-model="form.featured" label="Featured (pinned to the top of portal listings)" />

        <Button type="submit" :loading="form.processing">{{ submitLabel }}</Button>
    </form>
</template>
