/**
 * Employee record add/edit fields for RecordModal. Adding also picks the
 * person (`userOptions`); editing never changes whose record it is.
 */
export const employeeFields = (employmentStatusOptions, userOptions = null) => [
    ...(userOptions ? [{ key: 'user_id', label: 'Employee', type: 'select', required: true, span: 'full', options: userOptions }] : []),
    { key: 'employee_number', label: 'Employee number' },
    { key: 'employment_status', label: 'Employment status', type: 'select', options: [{ value: '', label: 'Not set' }, ...employmentStatusOptions] },
    { key: 'original_appointment_date', label: 'Original appointment date', type: 'date' },
    { key: 'separation_date', label: 'Separation date', type: 'date' },
    { key: 'separation_reason', label: 'Reason for separation' },
    { key: 'remarks', label: 'Remarks', type: 'textarea' },
];

export const employeeDefaults = {
    employee_number: '',
    employment_status: '',
    original_appointment_date: '',
    separation_date: '',
    separation_reason: '',
    remarks: '',
};

export const employeeNullable = ['employee_number', 'employment_status', 'original_appointment_date', 'separation_date', 'separation_reason', 'remarks'];
