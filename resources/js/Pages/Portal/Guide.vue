<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head } from '@inertiajs/vue3';
import PortalLayout from '../../Layouts/PortalLayout.vue';
import PageHeader from '../../Components/UI/PageHeader.vue';
import Card from '../../Components/UI/Card.vue';
import Button from '../../Components/UI/Button.vue';
import Icon from '../../Components/UI/Icon.vue';
import EmptyState from '../../Components/UI/EmptyState.vue';

defineOptions({ layout: PortalLayout });

/**
 * The User Guide: task-based how-to articles (config/user-guide.php),
 * already trimmed server-side to what applies to the signed-in person.
 * Search narrows them as you type; "Print" gives a paper or PDF copy.
 */
const props = defineProps({
    categories: { type: Array, required: true },
});

const query = ref('');
const print = () => window.print();

const articleCount = computed(() => props.categories.reduce((total, category) => total + category.articles.length, 0));

const matches = (article, needle) =>
    [article.title, article.summary, ...article.steps, ...article.tips].some((text) => text.toLowerCase().includes(needle));

const visible = computed(() => {
    const needle = query.value.trim().toLowerCase();
    if (!needle) return props.categories;

    return props.categories
        .map((category) => ({ ...category, articles: category.articles.filter((article) => matches(article, needle)) }))
        .filter((category) => category.articles.length);
});

const visibleCount = computed(() => visible.value.reduce((total, category) => total + category.articles.length, 0));

const categoryId = (title) => `guide-${title.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')}`;

/**
 * Scroll-spy: the category and article being read are the last ones whose
 * top edge has passed just under the fixed header. Near the bottom of the
 * page the last one wins, since it can never reach the top.
 */
const activeCategory = ref(null);
const activeArticle = ref(null);
const tocRef = ref(null);
const READING_LINE = 110;
let frame = null;

const lastPassed = (ids) => {
    let current = ids[0] ?? null;

    for (const id of ids) {
        const element = document.getElementById(id);
        if (element && element.getBoundingClientRect().top <= READING_LINE) current = id;
    }

    return current;
};

const update = () => {
    frame = null;
    const categories = visible.value.map((category) => categoryId(category.title));
    const articles = visible.value.flatMap((category) => category.articles.map((article) => article.slug));
    const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4;

    activeCategory.value = atBottom ? categories.at(-1) ?? null : lastPassed(categories);
    activeArticle.value = atBottom ? articles.at(-1) ?? null : lastPassed(articles);

    // The article must belong to the active category; otherwise the category's first one.
    const own = visible.value.find((category) => categoryId(category.title) === activeCategory.value);
    if (own && !own.articles.some((article) => article.slug === activeArticle.value)) {
        activeArticle.value = own.articles[0]?.slug ?? null;
    }
};

const schedule = () => {
    if (frame === null) frame = window.requestAnimationFrame(update);
};

// On phones the contents is a row of tabs: keep the current one in view.
watch(activeCategory, async (id) => {
    await nextTick();
    tocRef.value?.querySelector(`[data-toc="${id}"]`)?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
});

watch(visible, () => nextTick(schedule));

onMounted(() => {
    window.addEventListener('scroll', schedule, { passive: true });
    window.addEventListener('resize', schedule);
    update();
});

onBeforeUnmount(() => {
    window.removeEventListener('scroll', schedule);
    window.removeEventListener('resize', schedule);
    if (frame !== null) window.cancelAnimationFrame(frame);
});

const goTo = (id) => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    document.getElementById(id)?.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
};
</script>

<template>
    <Head title="User Guide" />

    <PageHeader
        title="User Guide"
        description="Short, step-by-step help for the things you do in IMIS. Only what applies to your account is shown."
        :breadcrumbs="[{ label: 'Home', href: '/dashboard' }, { label: 'User Guide' }]"
    >
        <template #actions>
            <Button variant="outline" class="print:hidden" @click="print">
                <Icon name="printer" size="sm" />Print
            </Button>
        </template>
    </PageHeader>

    <div class="space-y-8">
        <!-- Search banner -->
        <section class="relative overflow-hidden rounded-lg bg-primary p-6 text-white md:p-8 print:hidden">
            <span class="pointer-events-none absolute -top-12 -right-12 h-48 w-48 rounded-full border border-white/10" aria-hidden="true" />
            <span class="pointer-events-none absolute -right-4 -bottom-20 h-48 w-48 rounded-full border border-white/10" aria-hidden="true" />
            <div class="relative max-w-2xl">
                <h2 class="text-lg font-semibold md:text-xl">How can we help?</h2>
                <p class="mt-1 text-sm text-white/80">Search {{ articleCount }} guides — try "leave", "password" or "payslip".</p>
                <div class="relative mt-4">
                    <label for="guide-search" class="sr-only">Search the user guide</label>
                    <Icon name="search" size="sm" class="pointer-events-none absolute top-1/2 left-3.5 -translate-y-1/2 text-text-muted" />
                    <input
                        id="guide-search"
                        v-model="query"
                        type="search"
                        autocomplete="off"
                        placeholder="Search the guide…"
                        class="block w-full rounded-md border-0 bg-surface py-3 pr-4 pl-10 text-sm text-text shadow-sm placeholder:text-text-muted focus:outline-none focus:ring-2 focus:ring-focus-ring-on-dark"
                    />
                </div>
            </div>
        </section>

        <div class="lg:flex lg:items-start lg:gap-10">
            <!-- Contents -->
            <nav v-if="visible.length" aria-label="Guide contents" class="mb-8 shrink-0 lg:sticky lg:top-20 lg:mb-0 lg:w-64 print:hidden">
                <p class="mb-2 px-3 text-2xs font-semibold tracking-wide text-text-muted uppercase">Contents</p>
                <ul ref="tocRef" class="flex gap-2 overflow-x-auto pb-2 lg:max-h-[calc(100dvh-9rem)] lg:flex-col lg:gap-1 lg:overflow-y-auto lg:overflow-x-visible lg:pb-0">
                    <li v-for="category in visible" :key="category.title" class="shrink-0">
                        <a
                            :href="`#${categoryId(category.title)}`"
                            :data-toc="categoryId(category.title)"
                            class="relative flex items-center gap-3 rounded-md px-3 py-2 text-compact whitespace-nowrap transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring max-lg:border"
                            :class="
                                activeCategory === categoryId(category.title)
                                    ? 'bg-info-bg font-semibold text-primary max-lg:border-info-border'
                                    : 'text-text-muted hover:bg-surface-muted hover:text-text max-lg:border-border max-lg:bg-surface'
                            "
                            :aria-current="activeCategory === categoryId(category.title) ? 'location' : undefined"
                            @click.prevent="goTo(categoryId(category.title))"
                        >
                            <span
                                class="absolute inset-y-1.5 left-0 hidden w-0.5 rounded-full bg-accent transition-opacity lg:block"
                                :class="activeCategory === categoryId(category.title) ? 'opacity-100' : 'opacity-0'"
                                aria-hidden="true"
                            />
                            <Icon :name="category.icon" size="sm" class="shrink-0 text-primary" />
                            <span class="flex-1">{{ category.title }}</span>
                        </a>

                        <!-- The articles of the category being read, with the current one marked. -->
                        <ul
                            v-if="activeCategory === categoryId(category.title)"
                            class="mt-1 mb-2 ml-[1.35rem] hidden space-y-0.5 border-l border-border pl-3 lg:block"
                        >
                            <li v-for="article in category.articles" :key="article.slug">
                                <a
                                    :href="`#${article.slug}`"
                                    class="block rounded-sm py-1 pr-2 text-xs leading-snug transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-focus-ring"
                                    :class="activeArticle === article.slug ? 'font-semibold text-primary' : 'text-text-muted hover:text-text'"
                                    :aria-current="activeArticle === article.slug ? 'location' : undefined"
                                    @click.prevent="goTo(article.slug)"
                                >{{ article.title }}</a>
                            </li>
                        </ul>
                    </li>
                </ul>
            </nav>

            <div class="min-w-0 flex-1 space-y-10">
                <section v-for="category in visible" :id="categoryId(category.title)" :key="category.title" class="scroll-mt-20" :aria-labelledby="`${categoryId(category.title)}-title`">
                    <h2 :id="`${categoryId(category.title)}-title`" class="mb-4 flex items-center gap-3 text-lg font-semibold text-text">
                        <span class="flex h-9 w-9 items-center justify-center rounded-md bg-info-bg text-primary" aria-hidden="true">
                            <Icon :name="category.icon" />
                        </span>
                        {{ category.title }}
                    </h2>

                    <div class="space-y-6">
                        <Card
                            v-for="article in category.articles"
                            :id="article.slug"
                            :key="article.slug"
                            :title="article.title"
                            :description="article.summary"
                            class="scroll-mt-20 break-inside-avoid"
                        >
                            <ol class="space-y-3.5">
                                <li v-for="(step, index) in article.steps" :key="step" class="flex gap-3.5">
                                    <span class="mt-px flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary text-xs font-semibold text-white tabular-nums" aria-hidden="true">
                                        {{ index + 1 }}
                                    </span>
                                    <span class="min-w-0 text-sm leading-relaxed text-text"><span class="sr-only">Step {{ index + 1 }}: </span>{{ step }}</span>
                                </li>
                            </ol>

                            <div v-if="article.tips.length" class="mt-6 rounded-md border border-info-border bg-info-bg p-4">
                                <p class="flex items-center gap-2 text-xs font-semibold tracking-wide text-primary uppercase">
                                    <Icon name="info" size="sm" />Good to know
                                </p>
                                <ul class="mt-2 list-disc space-y-1.5 pl-5 text-sm leading-relaxed text-text-muted marker:text-primary">
                                    <li v-for="tip in article.tips" :key="tip">{{ tip }}</li>
                                </ul>
                            </div>

                            <template v-if="article.link" #footer>
                                <Button variant="outline" size="sm" :href="article.link.href" class="print:hidden">
                                    {{ article.link.label }}
                                    <Icon name="arrowRight" size="sm" />
                                </Button>
                            </template>
                        </Card>
                    </div>
                </section>

                <Card v-if="!visible.length">
                    <EmptyState icon="search" :title="`Nothing found for “${query}”`" description="Try a simpler word, or send us feedback if a guide you need is missing.">
                        <template #action>
                            <Button variant="outline" size="sm" @click="query = ''">Clear search</Button>
                        </template>
                    </EmptyState>
                </Card>

                <p v-else-if="query" class="text-center text-xs text-text-muted" role="status">{{ visibleCount }} of {{ articleCount }} guides shown</p>
            </div>
        </div>
    </div>
</template>
