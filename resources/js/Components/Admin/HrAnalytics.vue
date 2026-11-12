<script setup>
import Badge from '../UI/Badge.vue';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Card from '../UI/Card.vue';
import StatTile from '../UI/StatTile.vue';
import SimpleChart from '../Charts/SimpleChart.vue';
import SplitBar from '../Charts/SplitBar.vue';
import { formatCalendarDate } from '../../Utils/datetime';

/**
 * The Human Resources analytics on the HR dashboard (HrDashboard):
 * headcount and its breakdowns, what's waiting on HR, birthdays,
 * retirement, loyalty milestones, leave and L&D. Sections the viewer
 * can't see arrive as null and are left out.
 */
const props = defineProps({
    hr: { type: Object, required: true },
});

const percent = (part, whole) => (whole ? Math.round((part / whole) * 100) : 0);
const shortDate = (value) => new Date(`${value}T00:00:00`).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
const when = (days) => (days === 0 ? 'Today' : days === 1 ? 'Tomorrow' : `In ${days} days`);
const ordinal = (n) => `${n}${['th', 'st', 'nd', 'rd'][n % 100 > 10 && n % 100 < 14 ? 0 : n % 10 < 4 ? n % 10 : 0]}`;

const sexSegments = computed(() => [
    { label: 'Female', value: props.hr.sex.female, color: 'chart-1' },
    { label: 'Male', value: props.hr.sex.male, color: 'chart-2' },
    ...(props.hr.sex.not_recorded ? [{ label: 'Not recorded', value: props.hr.sex.not_recorded, color: 'chart-neutral' }] : []),
]);

const retirementKinds = {
    past_compulsory: { label: 'Past compulsory age', classes: 'bg-danger-bg text-danger-fg' },
    compulsory: { label: 'Compulsory (65)', classes: 'bg-warning-bg text-warning-fg' },
    optional: { label: 'Optional (60)', classes: 'bg-info-bg text-link' },
    // Already 60–64: may retire any time; dated at 65.
    eligible: { label: 'Eligible (60+)', classes: 'bg-surface-muted text-text-muted' },
};

const pendingTotal = computed(() => props.hr.pending.reduce((sum, item) => sum + item.count, 0));
const gapsTotal = computed(() => props.hr.gaps.birthday + props.hr.gaps.sex + props.hr.gaps.employmentStatus);
const onLeaveToday = computed(() => props.hr.leave?.onLeaveToday.length ?? 0);
</script>

<template>
    <div class="space-y-4">
        <!-- Headline numbers -->
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <StatTile label="Personnel in service" :value="hr.headcount" icon="users" tone="primary">
                {{ hr.sex.female }} female · {{ hr.sex.male }} male<template v-if="hr.age.average !== null"> · average age {{ hr.age.average }}</template>
            </StatTile>
            <StatTile
                v-if="hr.plantilla"
                label="Plantilla filled"
                :value="`${percent(hr.plantilla.filled, hr.plantilla.items)}%`"
                icon="grid"
                tone="success"
                :progress="percent(hr.plantilla.filled, hr.plantilla.items)"
            >
                {{ hr.plantilla.filled }} of {{ hr.plantilla.items }} items ·
                <Link href="/admin/hr/staffing" class="font-semibold text-link hover:underline">{{ hr.plantilla.vacant }} vacant</Link>
            </StatTile>
            <StatTile v-if="hr.leave" label="On leave today" :value="onLeaveToday" icon="calendar" tone="info">
                {{ hr.leave.upcoming.length }} more in the next 14 days · {{ hr.leave.inReview }} in review
            </StatTile>
            <StatTile label="Retirement, next 12 months" :value="hr.retirement.compulsory + hr.retirement.optional" icon="clock" tone="warning">
                {{ hr.retirement.compulsory }} compulsory · {{ hr.retirement.optional }} optional · {{ hr.retirement.eligibleNow }} eligible now
            </StatTile>
        </div>

        <!-- What's waiting, and gaps in the records -->
        <div class="grid gap-4 xl:grid-cols-12">
            <Card title="Waiting on HR" :description="pendingTotal ? `${pendingTotal} to act on` : 'Nothing waiting'" icon="bell" class="xl:col-span-7">
                <ul class="divide-y divide-border">
                    <li v-for="item in hr.pending" :key="item.href" class="flex items-center justify-between py-2 text-sm">
                        <Link :href="item.href" class="font-medium text-link hover:text-primary-hover">{{ item.label }}</Link>
                        <Badge pill :tone="item.count ? 'warning' : 'neutral'">
                            {{ item.count }}
                        </Badge>
                    </li>
                </ul>
            </Card>
            <Card title="Records to complete" description="Gaps that make these figures incomplete" icon="exclamation" class="xl:col-span-5">
                <ul v-if="gapsTotal || hr.service.unknown" class="space-y-1.5 text-sm text-text">
                    <li v-if="hr.gaps.employmentStatus"><strong>{{ hr.gaps.employmentStatus }}</strong> without an employment status</li>
                    <li v-if="hr.service.unknown"><strong>{{ hr.service.unknown }}</strong> without an appointment date (length of service, loyalty awards)</li>
                    <li v-if="hr.gaps.birthday"><strong>{{ hr.gaps.birthday }}</strong> without a birthday (age, birthdays, retirement)</li>
                    <li v-if="hr.gaps.sex"><strong>{{ hr.gaps.sex }}</strong> without sex recorded</li>
                </ul>
                <p v-else class="text-sm text-text-muted">Every record has what these figures need.</p>
                <Link href="/admin/hr/employees" class="mt-3 inline-block text-sm font-medium text-link hover:underline">Go to employee records</Link>
            </Card>
        </div>

        <!-- Breakdowns -->
        <div class="grid gap-4 xl:grid-cols-12">
            <Card title="By sex" icon="users" class="xl:col-span-4">
                <SplitBar
                    :segments="sexSegments"
                    label="People"
                    :summary="`Personnel in service by sex: ${hr.sex.female} female, ${hr.sex.male} male${hr.sex.not_recorded ? `, ${hr.sex.not_recorded} not recorded` : ''}.`"
                />
            </Card>
            <Card
                title="By age"
                :description="hr.age.average !== null ? `Average ${hr.age.average} · youngest ${hr.age.youngest} · oldest ${hr.age.oldest}` : null"
                icon="chartBar"
                class="xl:col-span-4"
            >
                <SimpleChart type="bar" :labels="hr.age.labels" :values="hr.age.values" label="People" :height="200" summary="Personnel in service by age band." />
            </Card>
            <Card title="By employment status" icon="clipboard" class="xl:col-span-4">
                <SimpleChart
                    type="bar"
                    :labels="hr.employmentStatus.labels"
                    :values="hr.employmentStatus.values"
                    label="People"
                    :height="Math.max(140, hr.employmentStatus.labels.length * 34)"
                    summary="Personnel in service by employment status."
                />
            </Card>
        </div>

        <!-- Birthdays and retirement -->
        <div class="grid gap-4 xl:grid-cols-12">
            <Card title="Upcoming birthdays" description="Next 30 days" icon="calendar" class="xl:col-span-5">
                <ul class="max-h-80 divide-y divide-border overflow-y-auto">
                    <li v-if="!hr.birthdays.length" class="py-6 text-center text-sm text-text-muted">No birthdays in the next 30 days.</li>
                    <li v-for="person in hr.birthdays" :key="person.employee_id" class="flex items-center gap-3 py-2 text-sm">
                        <span
                            class="flex h-10 w-10 shrink-0 flex-col items-center justify-center rounded-lg text-2xs leading-tight font-semibold"
                            :class="person.in_days === 0 ? 'bg-primary text-white' : 'bg-info-bg text-link'"
                        >
                            {{ shortDate(person.date).split(' ')[0] }}
                            <span class="text-sm">{{ shortDate(person.date).split(' ')[1] }}</span>
                        </span>
                        <div class="min-w-0 flex-1">
                            <Link :href="`/admin/hr/employees/${person.employee_id}`" class="font-medium text-text hover:text-link">{{ person.name }}</Link>
                            <div class="text-xs text-text-muted">{{ person.office || '—' }} · turns {{ person.turning }}</div>
                        </div>
                        <span class="text-xs font-medium" :class="person.in_days === 0 ? 'text-link' : 'text-text-muted'">{{ when(person.in_days) }}</span>
                    </li>
                </ul>
            </Card>

            <Card title="Upcoming retirees" description="Optional at 60, compulsory at 65 (RA 8291): who reaches either in the next 12 months, and who is already eligible" icon="clock" class="xl:col-span-7">
                <div class="max-h-80 overflow-auto">
                    <table class="data-table" data-sticky>
                        <caption class="sr-only">Personnel reaching retirement age within 12 months, or already eligible</caption>
                        <thead>
                            <tr>
                                <th scope="col">Name / Position</th>
                                <th scope="col">Office</th>
                                <th scope="col">Retirement</th>
                                <th scope="col" class="text-right">Reaches age on</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!hr.retirement.people.length">
                                <td colspan="4" class="px-3 py-6 text-center text-text-muted">No one reaches or has reached retirement age.</td>
                            </tr>
                            <tr v-for="person in hr.retirement.people" :key="person.employee_id">
                                <td>
                                    <Link :href="`/admin/hr/employees/${person.employee_id}`" class="font-medium text-text hover:text-link">{{ person.name }}</Link>
                                    <div class="text-xs text-text-muted">{{ person.position || '—' }} · age {{ person.age }}</div>
                                </td>
                                <td class="text-text-muted">{{ person.office || '—' }}</td>
                                <td>
                                    <span class="rounded-full px-2 py-0.5 text-xs font-semibold" :class="retirementKinds[person.kind].classes">{{ retirementKinds[person.kind].label }}</span>
                                </td>
                                <td class="text-right whitespace-nowrap">{{ formatCalendarDate(person.date) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <!-- Leave -->
        <div v-if="hr.leave" class="grid gap-4 xl:grid-cols-12">
            <Card title="On leave" description="Today and the next 14 days" icon="calendar" class="xl:col-span-5">
                <ul class="max-h-80 divide-y divide-border overflow-y-auto text-sm">
                    <li v-if="!hr.leave.onLeaveToday.length && !hr.leave.upcoming.length" class="py-6 text-center text-text-muted">No approved leave today or in the next 14 days.</li>
                    <li v-for="item in [...hr.leave.onLeaveToday.map((row) => ({ ...row, today: true })), ...hr.leave.upcoming]" :key="item.id" class="flex items-center gap-3 py-2">
                        <div class="min-w-0 flex-1">
                            <Link :href="`/admin/hr/employees/${item.employee_id}/leave`" class="font-medium text-text hover:text-link">{{ item.name }}</Link>
                            <div class="text-xs text-text-muted">{{ item.type || 'Leave' }} · {{ item.days }} {{ item.days === 1 ? 'day' : 'days' }}</div>
                        </div>
                        <span class="text-right text-xs whitespace-nowrap" :class="item.today ? 'font-semibold text-link' : 'text-text-muted'">
                            {{ item.today ? 'On leave · until ' : '' }}{{ item.today ? shortDate(item.date_to) : `${shortDate(item.date_from)} – ${shortDate(item.date_to)}` }}
                        </span>
                    </li>
                </ul>
            </Card>

            <Card title="Recent leave applications" icon="clipboard" class="xl:col-span-7">
                <template #actions>
                    <Link href="/admin/hr/leave/applications" class="text-sm font-medium text-link hover:text-primary-hover">All applications</Link>
                </template>
                <div class="max-h-80 overflow-auto">
                    <table class="data-table" data-sticky>
                        <caption class="sr-only">The latest leave applications</caption>
                        <thead>
                            <tr>
                                <th scope="col">Name / Leave</th>
                                <th scope="col">Dates</th>
                                <th scope="col" class="text-right">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!hr.leave.recent.length">
                                <td colspan="3" class="px-3 py-6 text-center text-text-muted">No leave applications yet.</td>
                            </tr>
                            <tr v-for="item in hr.leave.recent" :key="item.id">
                                <td>
                                    <span class="font-medium text-text">{{ item.name }}</span>
                                    <div class="text-xs text-text-muted">{{ item.type || 'Leave' }} · {{ item.days }} {{ item.days === 1 ? 'day' : 'days' }}</div>
                                </td>
                                <td class="whitespace-nowrap text-text-muted">{{ shortDate(item.date_from) }} – {{ shortDate(item.date_to) }}</td>
                                <td class="text-right text-xs font-medium text-text">{{ item.status_label }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </Card>
        </div>

        <!-- Service, loyalty, L&D -->
        <div class="grid gap-4 xl:grid-cols-12">
            <Card
                title="Length of service"
                :description="hr.service.average !== null ? `Average ${hr.service.average} years` : 'From the original appointment date'"
                icon="chartBar"
                class="xl:col-span-5"
            >
                <SimpleChart type="bar" :labels="hr.service.labels" :values="hr.service.values" label="People" :height="230" summary="Personnel in service by length of service." />
                <p v-if="hr.service.unknown" class="mt-2 text-xs text-text-muted">{{ hr.service.unknown }} without an appointment date aren't counted.</p>
            </Card>

            <Card :title="`Loyalty awards, ${hr.loyalty.year}`" description="10 years of service, then every 5 (CSC)" icon="checkCircle" class="xl:col-span-4">
                <ul class="max-h-72 divide-y divide-border overflow-y-auto text-sm">
                    <li v-if="!hr.loyalty.withDates" class="py-6 text-center text-text-muted">No appointment dates recorded yet.</li>
                    <li v-else-if="!hr.loyalty.people.length" class="py-6 text-center text-text-muted">No milestones this year.</li>
                    <li v-for="person in hr.loyalty.people" :key="person.employee_id" class="flex items-center justify-between gap-3 py-2">
                        <div class="min-w-0">
                            <Link :href="`/admin/hr/employees/${person.employee_id}`" class="font-medium text-text hover:text-link">{{ person.name }}</Link>
                            <div class="text-xs text-text-muted">{{ person.office || '—' }} · {{ formatCalendarDate(person.date) }}</div>
                        </div>
                        <Badge pill tone="success">{{ ordinal(person.years) }} year</Badge>
                    </li>
                </ul>
            </Card>

            <Card v-if="hr.learning" :title="`Learning & Development, ${hr.learning.year}`" icon="activity" class="xl:col-span-3">
                <dl class="space-y-3 text-sm">
                    <div>
                        <dt class="text-text-muted">Trained this year</dt>
                        <dd class="text-2xl font-semibold text-text">{{ percent(hr.learning.trained, hr.learning.headcount) }}%</dd>
                        <dd class="text-xs text-text-muted">{{ hr.learning.trained }} of {{ hr.learning.headcount }} in service</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">Trainings · hours</dt>
                        <dd class="font-semibold text-text">{{ hr.learning.trainings }} · {{ hr.learning.hours }}</dd>
                    </div>
                </dl>
                <Link href="/admin/hr/learning-development/dashboard" class="mt-3 inline-block text-sm font-medium text-link hover:underline">L&D dashboard</Link>
            </Card>
        </div>
    </div>
</template>
