/**
 * Contract / appointment fields of non-plantilla staff (casual,
 * contractual, Job Order, Contract of Service) for RecordModal.
 */
export const contractFields = ({ statusOptions, officeOptions, employeeOptions = null }) => [
    ...(employeeOptions
        ? [{ key: 'employee_id', label: 'Employee', type: 'select', required: true, span: 'full', options: [{ value: '', label: 'Choose…' }, ...employeeOptions], help: 'Anyone in service who holds no plantilla item.' }]
        : []),
    { key: 'employment_status', label: 'Status', type: 'select', required: true, options: statusOptions },
    { key: 'designation', label: 'Designation', required: true, help: 'e.g. Computer Technician' },
    { key: 'organizational_unit_id', label: 'Office', type: 'select', span: 'full', options: [{ value: '', label: 'Not set' }, ...officeOptions] },
    { key: 'date_from', label: 'From', type: 'date', required: true },
    { key: 'date_to', label: 'To', type: 'date', help: 'Leave blank if open-ended.' },
    { key: 'rate', label: 'Rate (₱)', type: 'number' },
    { key: 'rate_basis', label: 'Rate per', type: 'select', options: [{ value: '', label: 'Not set' }, { value: 'daily', label: 'Day' }, { value: 'monthly', label: 'Month' }] },
    { key: 'funding_source', label: 'Funding source', help: 'e.g. MOOE, project fund' },
    { key: 'reference', label: 'Contract / order no.' },
    { key: 'remarks', label: 'Remarks', type: 'textarea' },
];

export const contractDefaults = {
    employee_id: '',
    employment_status: 'contract_of_service',
    designation: '',
    organizational_unit_id: '',
    date_from: '',
    date_to: '',
    rate: '',
    rate_basis: '',
    funding_source: '',
    reference: '',
    remarks: '',
};

export const contractNullable = ['organizational_unit_id', 'date_to', 'rate', 'rate_basis', 'funding_source', 'reference', 'remarks'];
