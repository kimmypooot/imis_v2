<script setup>
/**
 * The legacy dashboard's welcome banner: greeting, system title, and the
 * portal's welcome message (from System Settings) on CSC navy. Holds the
 * page's <h1>.
 */
defineProps({
    greeting: { type: String, required: true },
    message: { type: String, default: null },
});
</script>

<template>
    <section
        class="relative overflow-hidden rounded-xl bg-linear-to-br from-csc-blue to-csc-blue-dark px-6 py-10 text-center text-white shadow-sm sm:px-10"
    >
        <!-- Decorative rings, echoing the legacy banner's orbit circles -->
        <div class="pointer-events-none absolute -top-16 -right-16 h-56 w-56 rounded-full border-[24px] border-white/5" aria-hidden="true" />
        <div class="pointer-events-none absolute -bottom-20 -left-12 h-48 w-48 rounded-full border-[20px] border-white/5" aria-hidden="true" />

        <div class="relative">
            <p class="text-sm font-medium text-white/80">{{ greeting }}</p>
            <h1 class="mt-2 text-xl leading-snug font-bold sm:text-2xl">
                CSC RO VIII — Integrated Management Information System
            </h1>
            <p class="mx-auto mt-2 max-w-2xl text-sm text-white/75">
                {{ message || 'Enhancing public service delivery through innovation and digital transformation.' }}
            </p>
        </div>
    </section>
</template>
