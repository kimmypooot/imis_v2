/**
 * HR-4 plantilla item add/edit fields for RecordModal, modelled on the
 * DBM plantilla (PSIPOP) columns. The item number and grade may wait; a
 * title on the Positions list supplies the grade and level.
 */
export const plantillaItemFields = ({ gradeOptions, levelOptions, officeOptions, positionTitles = [] }) => [
    { key: 'item_number', label: 'Item number', help: 'Leave blank if it isn’t known yet.' },
    { key: 'position_title', label: 'Position title', required: true, suggestions: positionTitles, help: 'Pick a title already in use, or type a new one.' },
    {
        key: 'salary_grade',
        label: 'Salary grade',
        type: 'select',
        options: [{ value: '', label: 'Not set' }, ...gradeOptions],
        help: 'Taken from the position on the Positions list, when it has one.',
    },
    { key: 'level', label: 'Level', type: 'select', options: [{ value: '', label: 'Not set' }, ...levelOptions] },
    { key: 'organizational_unit_id', label: 'Place of assignment', type: 'select', span: 'full', options: [{ value: '', label: 'Not set' }, ...officeOptions] },
    { key: 'authority', label: 'Authority (e.g. DBM-approved PSIPOP)', span: 'full' },
    { key: 'remarks', label: 'Remarks', type: 'textarea' },
];

export const plantillaItemDefaults = {
    item_number: '',
    position_title: '',
    salary_grade: '',
    level: '',
    organizational_unit_id: '',
    authority: '',
    remarks: '',
};

export const plantillaItemNullable = ['item_number', 'salary_grade', 'level', 'organizational_unit_id', 'authority', 'remarks'];
