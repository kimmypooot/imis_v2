<?php

namespace App\Support\HR\Pds;

use App\Enums\EmploymentStatus;
use App\Models\Pds\PdsChild;
use App\Models\Pds\PdsEducation;
use App\Models\Pds\PdsEligibility;
use App\Models\Pds\PdsLearningDevelopment;
use App\Models\Pds\PdsOtherInformation;
use App\Models\Pds\PdsReference;
use App\Models\Pds\PdsVoluntaryWork;
use App\Models\Pds\PdsWorkExperience;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * CS Form No. 212 (Revised 2026), defined once. Each section's fields
 * produce both the server-side validation rules and the schema the PDS
 * editor renders, so the two never drift apart.
 *
 * Section kinds:
 * - `profile`: single values on the employee's `pds_profiles` row (or, for
 *   `target: user`, the account — only the name extension).
 * - `questions`: items 34–40, kept as one encrypted JSON document.
 * - `list`: a repeating section, one model row per entry.
 *
 * A section may be `optional`: it can legitimately stay empty (no children,
 * no voluntary work…), so the completeness card does not count it against
 * the person.
 *
 * Field: { key, label, type, required?, options?, when?, span?, max?,
 *          min?, help?, suggestions?, target? }
 * `when` is ['field' => ..., 'is' => value] — the field only applies (is
 * shown, validated and kept) while that other field has that value.
 */
final class PdsSchema
{
    /**
     * The editor's tabs, in form order, and the sections each holds.
     *
     * @return array<int, array{key: string, label: string, sections: array<int, string>}>
     */
    public static function tabs(): array
    {
        return [
            ['key' => 'personal', 'label' => 'I. Personal Information', 'sections' => ['personal']],
            ['key' => 'family', 'label' => 'II. Family Background', 'sections' => ['family', 'children']],
            ['key' => 'education', 'label' => 'III. Education', 'sections' => ['education']],
            ['key' => 'eligibility', 'label' => 'IV. Eligibility', 'sections' => ['eligibility']],
            ['key' => 'work', 'label' => 'V. Work Experience', 'sections' => ['work_experience']],
            ['key' => 'learning', 'label' => 'VI. Learning & Development', 'sections' => ['learning_development']],
            ['key' => 'voluntary', 'label' => 'VII. Voluntary Work', 'sections' => ['voluntary_work']],
            ['key' => 'other', 'label' => 'VIII. Other Information', 'sections' => ['skills', 'distinctions', 'memberships']],
            ['key' => 'questions', 'label' => 'Questions 34–40', 'sections' => ['questions']],
            ['key' => 'references', 'label' => 'References & ID', 'sections' => ['references', 'government_id']],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function sections(): array
    {
        return [
            'personal' => [
                'kind' => 'profile',
                'label' => 'Personal information',
                'help' => 'Your name, sex at birth and date of birth come from your IMIS account. Ask HR to correct them.',
                'fields' => [
                    self::field('name_extension', 'Name extension (Jr., Sr., III)', max: 10, target: 'user'),
                    self::field('place_of_birth', '4. Place of birth', span: 'full'),
                    self::field('civil_status', '6. Civil status', 'select', options: self::options([
                        'single' => 'Single', 'married' => 'Married', 'widowed' => 'Widowed', 'separated' => 'Separated', 'other' => 'Other/s',
                    ])),
                    self::field('civil_status_other', 'Other civil status (specify)', max: 50, when: ['field' => 'civil_status', 'is' => 'other'], required: true),
                    self::field('height_m', '7. Height (m)', 'number', min: 0.5, max: 2.5, step: '0.01'),
                    self::field('weight_kg', '8. Weight (kg)', 'number', min: 1, max: 400, step: '0.1'),
                    self::field('blood_type', '9. Blood type', 'select', options: self::options(array_combine(
                        $types = ['A+', 'A-', 'B+', 'B-', 'AB+', 'AB-', 'O+', 'O-'], $types,
                    ))),
                    self::heading('Identification numbers'),
                    self::field('umid_no', '10. UMID ID no.', 'idnumber'),
                    self::field('pagibig_no', '11. PAG-IBIG ID no.', 'idnumber'),
                    self::field('philhealth_no', '12. PhilHealth no.', 'idnumber'),
                    self::field('philsys_no', '13. PhilSys Card Number (PCN)', 'idnumber'),
                    self::field('tin_no', '14. TIN no.', 'idnumber'),
                    self::heading('16. Citizenship'),
                    self::field('citizenship', 'Citizenship', 'select', options: self::options([
                        'filipino' => 'Filipino', 'dual' => 'Dual citizenship',
                    ])),
                    self::field('dual_citizenship_by', 'Dual citizenship by', 'select', options: self::options([
                        'birth' => 'By birth', 'naturalization' => 'By naturalization',
                    ]), when: ['field' => 'citizenship', 'is' => 'dual'], required: true),
                    self::field('dual_citizenship_country', 'Country', 'select', options: self::options(array_combine(
                        $countries = config('pds.countries'), $countries,
                    )), when: ['field' => 'citizenship', 'is' => 'dual'], required: true),
                    self::heading('17. Residential address'),
                    ...self::address('residential'),
                    self::heading('18. Permanent address'),
                    self::field('permanent_same_as_residential', 'Same as residential address', 'checkbox', span: 'full'),
                    ...self::address('permanent', when: ['field' => 'permanent_same_as_residential', 'is' => false]),
                    self::heading('Contact'),
                    self::field('telephone_no', '19. Telephone no.', max: 50),
                    self::field('mobile_no', '20. Mobile no.', max: 50),
                    self::field('email', '21. E-mail address (if any)', 'email', span: 'full'),
                ],
            ],

            'family' => [
                'kind' => 'profile',
                'label' => 'Family background',
                'fields' => [
                    self::heading('22. Spouse'),
                    self::field('spouse_surname', 'Surname', max: 100),
                    self::field('spouse_first_name', 'First name', max: 100),
                    self::field('spouse_middle_name', 'Middle name', max: 100),
                    self::field('spouse_name_extension', 'Name extension', max: 10),
                    self::field('spouse_occupation', 'Occupation', max: 150),
                    self::field('spouse_employer', 'Employer/business name'),
                    self::field('spouse_business_address', 'Business address', span: 'full'),
                    self::field('spouse_telephone_no', 'Telephone no.', max: 50),
                    self::heading("24. Father's name"),
                    self::field('father_surname', 'Surname', max: 100),
                    self::field('father_first_name', 'First name', max: 100),
                    self::field('father_middle_name', 'Middle name', max: 100),
                    self::field('father_name_extension', 'Name extension', max: 10),
                    self::heading("25. Mother's maiden name"),
                    self::field('mother_surname', 'Surname', max: 100),
                    self::field('mother_first_name', 'First name', max: 100),
                    self::field('mother_middle_name', 'Middle name', max: 100),
                ],
            ],

            'children' => [
                'kind' => 'list',
                'optional' => true,
                'label' => '23. Children',
                'model' => PdsChild::class,
                'noun' => 'child',
                'fields' => [
                    self::field('full_name', 'Full name', required: true, span: 'full'),
                    self::field('date_of_birth', 'Date of birth', 'date', past: true),
                ],
            ],

            'education' => [
                'kind' => 'list',
                'label' => '26. Educational background',
                'model' => PdsEducation::class,
                'noun' => 'school',
                'fields' => [
                    self::field('level', 'Level', 'select', required: true, options: self::options([
                        'elementary' => 'Elementary', 'secondary' => 'Secondary', 'vocational' => 'Vocational / Trade Course',
                        'college' => 'College', 'graduate' => 'Graduate Studies',
                    ])),
                    self::field('school', 'Name of school (write in full)', required: true),
                    self::field('degree', 'Basic education/degree/course (write in full)', span: 'full'),
                    self::field('attendance_from', 'Attended from (year)', 'year'),
                    self::field('attendance_to', 'Attended to (year)', 'year'),
                    self::field('highest_level_units', 'Highest level/units earned (if not graduated)', max: 100),
                    self::field('year_graduated', 'Year graduated', 'year'),
                    self::field('honors', 'Scholarship/academic honors received', span: 'full'),
                ],
            ],

            'eligibility' => [
                'kind' => 'list',
                'optional' => true,
                'label' => '27. Civil service eligibility',
                'model' => PdsEligibility::class,
                'noun' => 'eligibility',
                'fields' => [
                    self::field('eligibility', 'Eligibility', required: true, span: 'full',
                        help: 'Career Service, RA 1080 (Board/Bar), under special laws, CES/CSEE, Category II/IV, uniformed personnel, barangay eligibility, driver\'s license…'),
                    self::field('rating', 'Rating (if applicable)', max: 20),
                    self::field('exam_date', 'Date of examination/conferment', 'date', past: true),
                    self::field('exam_place', 'Place of examination/conferment', span: 'full'),
                    self::field('license_number', 'License number (if applicable)', max: 50),
                    self::field('license_valid_until', 'License valid until', 'date'),
                ],
            ],

            'work_experience' => [
                'kind' => 'list',
                'label' => '28. Work experience',
                'help' => 'Include private employment. Start from your most recent work.',
                'model' => PdsWorkExperience::class,
                'noun' => 'position',
                'fields' => [
                    self::field('date_from', 'From', 'date', required: true, past: true),
                    self::field('date_to', 'To (leave blank if present)', 'date'),
                    self::field('position_title', 'Position title (write in full)', required: true, span: 'full'),
                    self::field('agency', 'Department/agency/office/company (write in full)', required: true, span: 'full'),
                    self::field('monthly_salary', 'Monthly salary', 'number', min: 0, max: 9999999, step: '0.01'),
                    self::field('salary_grade', 'Salary/job/pay grade & step (format "00-0")', max: 10, pattern: '/^\d{1,2}-\d{1,2}$/'),
                    self::field('appointment_status', 'Status of appointment', max: 50,
                        suggestions: array_map(fn (EmploymentStatus $status) => $status->label(), EmploymentStatus::cases())),
                    self::field('is_government', 'Government service', 'checkbox'),
                ],
            ],

            // HR-3: kept in Learning & Development, not edited here. The
            // PDS shows the employee's approved and pending trainings
            // (PdsLearningDevelopment::scopeOnPds), read-only.
            'learning_development' => [
                'kind' => 'list',
                'optional' => true,
                'label' => '29. Learning and development interventions/training programs attended',
                'model' => PdsLearningDevelopment::class,
                'noun' => 'training',
                'managed_by' => ['label' => 'Learning & Development', 'url' => '/my/learning-development'],
                'fields' => [
                    self::field('title', 'Title (write in full)', span: 'full'),
                    self::field('date_from', 'From', 'date'),
                    self::field('date_to', 'To', 'date'),
                    self::field('hours', 'Number of hours', 'number'),
                    self::field('ld_type', 'Type of L&D'),
                    self::field('sponsor', 'Conducted/sponsored by (write in full)', span: 'full'),
                ],
            ],

            'voluntary_work' => [
                'kind' => 'list',
                'optional' => true,
                'label' => '30. Voluntary work or involvement in civic/non-government/people/voluntary organizations',
                'model' => PdsVoluntaryWork::class,
                'noun' => 'organization',
                'fields' => [
                    self::field('organization', 'Name & address of organization (write in full)', required: true, max: 512, span: 'full'),
                    self::field('date_from', 'From', 'date'),
                    self::field('date_to', 'To', 'date'),
                    self::field('hours', 'Number of hours', 'number', min: 0, max: 99999, step: '0.5'),
                    self::field('position', 'Position/nature of work'),
                ],
            ],

            'skills' => self::otherInformation('skill', '31. Special skills and hobbies', 'skill or hobby'),
            'distinctions' => self::otherInformation('distinction', '32. Non-academic distinctions/recognition', 'distinction'),
            'memberships' => self::otherInformation('membership', '33. Membership in association/organization', 'membership'),

            'questions' => [
                'kind' => 'questions',
                'label' => 'Questions 34–40',
                'fields' => [
                    self::question('q34a', '34. Are you related by consanguinity or affinity to the appointing or recommending authority, or to the chief of bureau or office or to the person who has immediate supervision over you in the Office, Bureau or Department where you will be appointed, a. within the third degree?'),
                    self::question('q34b', 'b. within the fourth degree (for Local Government Unit – Career Employees)?'),
                    self::field('q34_details', 'If YES (34), give details', 'textarea', max: 1000, span: 'full'),
                    self::question('q35a', '35. a. Have you ever been found guilty of any administrative offense?'),
                    self::details('q35a_details', 'q35a'),
                    self::question('q35b', 'b. Have you been criminally charged before any court?'),
                    self::details('q35b_details', 'q35b'),
                    self::field('q35b_date_filed', 'Date filed', 'date', past: true, when: ['field' => 'q35b', 'is' => true]),
                    self::field('q35b_case_status', 'Status of case/s', max: 255, when: ['field' => 'q35b', 'is' => true]),
                    self::question('q36', '36. Have you ever been convicted of any crime or violation of any law, decree, ordinance or regulation by any court or tribunal?'),
                    self::details('q36_details', 'q36'),
                    self::question('q37', '37. Have you ever been separated from the service in any of the following modes: resignation, retirement, dropped from the rolls, dismissal, termination, end of term, finished contract or phased out (abolition) in the public or private sector?'),
                    self::details('q37_details', 'q37'),
                    self::question('q38a', '38. a. Have you ever been a candidate in a national or local election held within the last year (except Barangay election)?'),
                    self::details('q38a_details', 'q38a'),
                    self::question('q38b', 'b. Have you resigned from the government service during the three (3)-month period before the last election to promote/actively campaign for a national or local candidate?'),
                    self::details('q38b_details', 'q38b'),
                    self::question('q39', '39. Have you acquired the status of an immigrant or permanent resident of another country?'),
                    self::details('q39_country', 'q39', 'If YES, give details (country)'),
                    self::heading('40. Pursuant to: (a) Indigenous People\'s Act (RA 8371); (b) Magna Carta for Disabled Persons (RA 7277, as amended); and (c) Expanded Solo Parents Welfare Act (RA 11861), please answer the following items:'),
                    self::question('q40a', 'a. Are you a member of any indigenous group?'),
                    self::details('q40a_group', 'q40a', 'If YES, please specify'),
                    self::question('q40b', 'b. Are you a person with disability?'),
                    self::details('q40b_id', 'q40b', 'If YES, please specify ID no.'),
                    self::question('q40c', 'c. Are you a solo parent?'),
                    self::details('q40c_id', 'q40c', 'If YES, please specify ID no.'),
                ],
            ],

            'references' => [
                'kind' => 'list',
                'label' => '41. References (persons not related by consanguinity or affinity to you)',
                'model' => PdsReference::class,
                'noun' => 'reference',
                'max_rows' => 3,
                'fields' => [
                    self::field('name', 'Name', required: true),
                    self::field('contact', 'Contact no. and/or email'),
                    self::field('address', 'Office/residential address', span: 'full'),
                ],
            ],

            'government_id' => [
                'kind' => 'profile',
                'label' => 'Government-issued ID',
                'help' => 'E.g. passport, GSIS, SSS, PRC, driver\'s license.',
                'fields' => [
                    self::field('government_id_type', 'Government-issued ID', max: 100),
                    self::field('government_id_number', 'ID/license/passport no.', 'idnumber'),
                    self::field('government_id_issuance', 'Date/place of issuance', span: 'full'),
                ],
            ],
        ];
    }

    /**
     * Every section's key.
     *
     * @return array<int, string>
     */
    public static function sectionKeys(): array
    {
        return array_keys(self::sections());
    }

    /**
     * Sections the PDS editor saves — the routes accept only these.
     * Sections `managed_by` another module (L&D) are read-only here.
     *
     * @return array<int, string>
     */
    public static function editableSectionKeys(): array
    {
        return array_keys(array_filter(self::sections(), fn (array $section) => ! isset($section['managed_by'])));
    }

    /**
     * @return array<string, mixed>
     */
    public static function section(string $key): array
    {
        return self::sections()[$key] ?? throw new InvalidArgumentException("Unknown PDS section [{$key}].");
    }

    /**
     * What the editor needs: tabs and each section's fields, without the
     * server-only bits (models, regex patterns).
     *
     * @return array<string, mixed>
     */
    public static function forEditor(): array
    {
        $sections = collect(self::sections())->map(fn (array $section, string $key) => [
            'key' => $key,
            'kind' => $section['kind'],
            'label' => $section['label'],
            'help' => $section['help'] ?? null,
            'optional' => $section['optional'] ?? false,
            'noun' => $section['noun'] ?? null,
            'max_rows' => $section['max_rows'] ?? null,
            'managed_by' => $section['managed_by'] ?? null,
            'fields' => array_map(fn (array $field) => collect($field)->except(['pattern', 'target'])->all(), $section['fields']),
        ]);

        return ['tabs' => self::tabs(), 'sections' => $sections->all()];
    }

    /**
     * Validation rules for one entry of a section (a profile part, the
     * questions, or one row of a list).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(string $sectionKey, string $prefix = ''): array
    {
        $rules = [];

        foreach (self::section($sectionKey)['fields'] as $field) {
            if ($field['type'] === 'heading') {
                continue;
            }

            $rules[$prefix.$field['key']] = self::fieldRules($field, $prefix);
        }

        return $rules;
    }

    /**
     * Field keys that hold data (headings excluded), optionally only those
     * written to `$target`.
     *
     * @return array<int, string>
     */
    public static function dataKeys(string $sectionKey, ?string $target = null): array
    {
        return collect(self::section($sectionKey)['fields'])
            ->reject(fn (array $field) => $field['type'] === 'heading')
            ->when($target !== null, fn ($fields) => $fields->filter(fn (array $field) => ($field['target'] ?? 'pds') === $target))
            ->pluck('key')
            ->values()
            ->all();
    }

    /**
     * Blanks every field whose `when` condition doesn't hold, so a hidden
     * answer (e.g. a country after switching back to Filipino) is never
     * kept.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public static function dropInapplicable(string $sectionKey, array $values): array
    {
        foreach (self::section($sectionKey)['fields'] as $field) {
            if (isset($field['when']) && ! self::conditionHolds($field['when'], $values)) {
                $values[$field['key']] = $field['type'] === 'checkbox' ? false : null;
            }
        }

        return $values;
    }

    /**
     * @param  array{field: string, is: mixed}  $when
     * @param  array<string, mixed>  $values
     */
    private static function conditionHolds(array $when, array $values): bool
    {
        $actual = $values[$when['field']] ?? null;

        return is_bool($when['is']) ? (bool) $actual === $when['is'] : $actual === $when['is'];
    }

    /**
     * @param  array<string, mixed>  $field
     * @return array<int, mixed>
     */
    private static function fieldRules(array $field, string $prefix): array
    {
        $presence = match (true) {
            ! ($field['required'] ?? false) => ['nullable'],
            isset($field['when']) => self::requiredWhen($field['when'], $prefix),
            default => ['required'],
        };

        $max = $field['max'] ?? null;

        $type = match ($field['type']) {
            'text', 'email', 'idnumber' => array_filter([
                'string',
                $field['type'] === 'email' ? 'email' : null,
                'max:'.($max ?? ($field['type'] === 'idnumber' ? 30 : 255)),
                // ID numbers: digits with optional dashes/spaces.
                $field['type'] === 'idnumber' ? 'regex:/^[0-9A-Za-z][0-9A-Za-z\- ]*$/' : null,
                isset($field['pattern']) ? 'regex:'.$field['pattern'] : null,
            ]),
            'textarea' => ['string', 'max:'.($max ?? 1000)],
            'number' => array_filter(['numeric', isset($field['min']) ? 'min:'.$field['min'] : null, isset($field['max']) ? 'max:'.$field['max'] : null]),
            'year' => ['integer', 'between:1900,'.(now()->year + 10)],
            'date' => array_filter(['date', 'after:1900-01-01', ($field['past'] ?? false) ? 'before_or_equal:today' : null]),
            'select' => [Rule::in(array_column($field['options'], 'value'))],
            'checkbox', 'yesno' => ['boolean'],
        };

        return [...$presence, ...array_values($type)];
    }

    /**
     * @param  array{field: string, is: mixed}  $when
     * @return array<int, string>
     */
    private static function requiredWhen(array $when, string $prefix): array
    {
        $other = $prefix.$when['field'];

        return match (true) {
            $when['is'] === true => ['nullable', "required_if_accepted:{$other}"],
            $when['is'] === false => ['nullable', "required_if_declined:{$other}"],
            default => ['nullable', "required_if:{$other},{$when['is']}"],
        };
    }

    /**
     * @param  array<int, array{value: string, label: string}>|null  $options
     * @param  array{field: string, is: mixed}|null  $when
     * @param  array<int, string>|null  $suggestions
     * @return array<string, mixed>
     */
    private static function field(
        string $key,
        string $label,
        string $type = 'text',
        bool $required = false,
        ?array $options = null,
        ?array $when = null,
        ?string $span = null,
        int|float|null $max = null,
        int|float|null $min = null,
        ?string $step = null,
        bool $past = false,
        ?string $help = null,
        ?array $suggestions = null,
        ?string $pattern = null,
        ?string $target = null,
    ): array {
        return array_filter(
            compact('key', 'label', 'type', 'required', 'options', 'when', 'span', 'max', 'min', 'step', 'past', 'help', 'suggestions', 'pattern', 'target'),
            fn ($value) => $value !== null && $value !== false,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function heading(string $label): array
    {
        return ['key' => 'heading_'.md5($label), 'label' => $label, 'type' => 'heading'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function question(string $key, string $label): array
    {
        return self::field($key, $label, 'yesno', span: 'full');
    }

    /**
     * The "If YES, give details" line of a question: required once the
     * answer is Yes, and only then.
     *
     * @return array<string, mixed>
     */
    private static function details(string $key, string $question, string $label = 'If YES, give details'): array
    {
        return self::field($key, $label, max: 1000, span: 'full', required: true, when: ['field' => $question, 'is' => true]);
    }

    /**
     * @param  array{field: string, is: mixed}|null  $when
     * @return array<int, array<string, mixed>>
     */
    private static function address(string $prefix, ?array $when = null): array
    {
        return [
            self::field("{$prefix}_house_no", 'House/block/lot no.', max: 100, when: $when),
            self::field("{$prefix}_street", 'Street', max: 100, when: $when),
            self::field("{$prefix}_subdivision", 'Subdivision/village', max: 100, when: $when),
            self::field("{$prefix}_barangay", 'Barangay', max: 100, when: $when),
            self::field("{$prefix}_city", 'City/municipality', max: 100, when: $when),
            self::field("{$prefix}_province", 'Province', max: 100, when: $when),
            self::field("{$prefix}_zip_code", 'ZIP code', max: 10, when: $when),
        ];
    }

    /**
     * Items 31–33 share one table, told apart by `type`.
     *
     * @return array<string, mixed>
     */
    private static function otherInformation(string $type, string $label, string $noun): array
    {
        return [
            'kind' => 'list',
            'optional' => true,
            'label' => $label,
            'model' => PdsOtherInformation::class,
            'scope' => ['type' => $type],
            'noun' => $noun,
            'fields' => [self::field('detail', ucfirst($noun).' (write in full)', required: true, max: 512, span: 'full')],
        ];
    }

    /**
     * @param  array<string, string>  $labels  value => label
     * @return array<int, array{value: string, label: string}>
     */
    private static function options(array $labels): array
    {
        return array_map(fn ($value, $label) => ['value' => (string) $value, 'label' => $label], array_keys($labels), $labels);
    }
}
