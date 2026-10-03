<script setup>
import AdminCard from '../UI/AdminCard.vue';
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
        <AdminCard title="Personal" icon="user">
            <div class="flex items-center gap-4">
                <UserAvatar :name="employee.user.name" :photo-url="employee.user.profile_photo_url" size="lg" />
                <div class="min-w-0">
                    <p class="truncate text-base font-semibold text-text">{{ employee.user.name }}</p>
                    <p class="truncate text-sm text-text-muted">{{ show(employee.user.position) }}</p>
                    <p v-if="employee.user.unit" class="truncate text-sm text-text-muted">{{ employee.user.unit }}</p>
                </div>
            </div>
            <dl class="mt-5 grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                <dt class="text-text-muted">Username</dt>
                <dd class="text-text">{{ employee.user.username }}</dd>
                <dt class="text-text-muted">Email</dt>
                <dd class="break-all text-text">{{ show(employee.user.email) }}</dd>
                <dt class="text-text-muted">Sex</dt>
                <dd class="text-text">{{ show(employee.user.sex) }}</dd>
                <dt class="text-text-muted">Birthday</dt>
                <dd class="text-text">{{ date(employee.user.birthday) }}</dd>
            </dl>
        </AdminCard>

        <AdminCard title="Employment" icon="clipboard" class="lg:col-span-2">
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
                <div>
                    <dt class="text-text-muted">Service</dt>
                    <dd class="mt-0.5">
                        <StatusBadge :status="employee.separated ? 'inactive' : 'active'" :label="employee.separated ? 'Separated' : 'In service'" />
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
        </AdminCard>
    </div>
</template>
