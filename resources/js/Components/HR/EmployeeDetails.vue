<script setup>
import Card from '../UI/Card.vue';
import StatusBadge from '../UI/StatusBadge.vue';
import UserAvatar from '../UI/UserAvatar.vue';
import { formatCalendarDate } from '../../Utils/datetime';

/**
 * An employee record (EmployeeResource), read-only: identity from the
 * user account, then the employment facts HR maintains. Shared by the HR
 * record page and the employee's own "My Employee Record".
 */
defineProps({
    employee: { type: Object, required: true },
});

const show = (value) => value || '—';
const date = (value) => (value ? formatCalendarDate(value) : '—');
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-3">
        <Card title="Personal" icon="user">
            <div class="flex items-center gap-4">
                <UserAvatar :name="employee.user.name" :photo-url="employee.user.profile_photo_url" size="lg" />
                <div class="min-w-0">
                    <p class="truncate text-base font-semibold text-text">{{ employee.user.name }}</p>
                    <p class="truncate text-sm text-text-muted">{{ show(employee.user.position) }}</p>
                    <p v-if="employee.user.unit" class="truncate text-sm text-text-muted">{{ employee.user.unit }}</p>
                    <p v-if="employee.user.concurrent_units?.length" class="text-sm text-text-muted">
                        {{ employee.user.concurrent_units.map((unit) => `${unit.capacity} of ${unit.office}`).join(', ') }}
                        <span class="ml-1 rounded-full bg-info-bg px-2 py-0.5 text-xs text-link">concurrent</span>
                    </p>
                </div>
            </div>
            <dl class="mt-5 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-text-muted">Username</dt>
                <dd class="text-text">{{ employee.user.username }}</dd>
                <dt class="text-text-muted">Email</dt>
                <dd class="break-all text-text">{{ show(employee.user.email) }}</dd>
                <dt class="text-text-muted">Sex</dt>
                <dd class="text-text">{{ show(employee.user.sex_label) }}</dd>
                <dt class="text-text-muted">Birthday</dt>
                <dd class="text-text">{{ date(employee.user.birthday) }}</dd>
            </dl>
        </Card>

        <Card title="Employment" icon="clipboard" class="lg:col-span-2">
            <dl class="grid gap-x-6 gap-y-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-text-muted">Employee number</dt>
                    <dd class="mt-0.5 font-medium text-text">{{ show(employee.employee_number) }}</dd>
                </div>
                <div>
                    <dt class="text-text-muted">Employment status</dt>
                    <dd class="mt-0.5 font-medium text-text">{{ employee.employment_status_label }}</dd>
                </div>
                <div>
                    <dt class="text-text-muted">Original appointment</dt>
                    <dd class="mt-0.5 font-medium text-text">{{ date(employee.original_appointment_date) }}</dd>
                </div>
                <div v-if="employee.csc_entry_date">
                    <dt class="text-text-muted">Entered the CSC</dt>
                    <dd class="mt-0.5 font-medium text-text">
                        {{ date(employee.csc_entry_date) }}
                        <p v-if="employee.csc_service?.from_other_agency" class="text-xs font-normal text-text-muted">Came from another agency</p>
                    </dd>
                </div>
                <div>
                    <dt class="text-text-muted">Years in the CSC</dt>
                    <dd class="mt-0.5 font-medium text-text">
                        <template v-if="employee.csc_service">
                            {{ employee.csc_service.label }}
                            <StatusBadge v-if="employee.csc_service.award_this_year" status="info" :label="`${employee.csc_service.award_this_year}-year loyalty award this year`" class="ml-1" />
                            <p class="text-xs font-normal text-text-muted">
                                <template v-if="employee.csc_service.last_award">Last loyalty award: {{ employee.csc_service.last_award }} years. </template>
                                <template v-if="employee.csc_service.next_award">Next: {{ employee.csc_service.next_award }} years on {{ date(employee.csc_service.next_award_date) }}.</template>
                            </p>
                        </template>
                        <template v-else>—</template>
                    </dd>
                </div>
                <div>
                    <dt class="text-text-muted">Service</dt>
                    <dd class="mt-0.5">
                        <StatusBadge :status="employee.separated ? 'inactive' : 'active'" :label="employee.separated ? 'Separated' : 'In Service'" />
                    </dd>
                </div>
                <template v-if="employee.separation_date">
                    <div>
                        <dt class="text-text-muted">Separation date</dt>
                        <dd class="mt-0.5 font-medium text-text">{{ date(employee.separation_date) }}</dd>
                    </div>
                    <div>
                        <dt class="text-text-muted">Reason for separation</dt>
                        <dd class="mt-0.5 font-medium text-text">{{ show(employee.separation_reason) }}</dd>
                    </div>
                </template>
                <div v-if="employee.remarks" class="sm:col-span-2">
                    <dt class="text-text-muted">Remarks</dt>
                    <dd class="mt-0.5 whitespace-pre-line text-text">{{ employee.remarks }}</dd>
                </div>
            </dl>
        </Card>
    </div>
</template>
