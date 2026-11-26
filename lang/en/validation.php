<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'The :attribute field must be accepted.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'any_of' => 'The :attribute field is invalid.',
    'array' => 'The :attribute field must be an array.',
    'array_keys' => 'The :attribute field must only contain the following keys: :values.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'base64' => 'The :attribute field must be a valid Base64 string.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'doesnt_contain' => 'The :attribute field must not contain any of the following: :values.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => 'The :attribute field must be a valid email address.',
    'encoding' => 'The :attribute field must be encoded in :encoding.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => 'The :attribute field must exist in :other.',
    'in_array_keys' => 'The :attribute field must contain at least one of the following keys: :values.',
    'integer' => 'The :attribute field must be an integer.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute field format is invalid.',
    'numeric' => 'The :attribute field must be a number.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => 'The :attribute field must be present.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required.',
    'required_if_accepted' => 'The :attribute field is required.',
    'required_if_declined' => 'The :attribute field is required.',
    'required_unless' => 'The :attribute field is required.',
    'required_with' => 'The :attribute field is required.',
    'required_with_all' => 'The :attribute field is required.',
    'required_without' => 'The :attribute field is required.',
    'required_without_all' => 'The :attribute field is required.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'The :attribute field must be a string.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => 'The :attribute field must be uppercase.',
    'url' => 'The :attribute field must be a valid URL.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => 'The :attribute field must be a valid UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    // IMIS: the conditional "required" rules above no longer say "when x is
    // y" with raw field names and values; where the reason matters, say it
    // in words here.
    'custom' => [
        'remarks' => [
            'required_if' => 'Give the reason in the remarks, so the employee knows what to do.',
        ],
        'password' => [
            'required_if_accepted' => 'Enter your IMIS password to sign electronically.',
        ],
        'half_day_period' => [
            'required_if' => 'Choose AM or PM for the half day.',
        ],
        'date_from' => [
            'required_without' => 'Enter the start date, or pick the dates.',
        ],
        'date_to' => [
            'required_without' => 'Enter the end date, or pick the dates.',
        ],
        'end_reason' => [
            'required_with' => 'Give the reason the appointment ended.',
        ],
        'separation_reason' => [
            'required_with' => 'Give the reason for the separation.',
        ],
        'separation_cause' => [
            'required_with' => 'Give the cause of the separation.',
        ],
        'reference' => [
            'required_without' => 'Enter the reference printed on the SALN.',
        ],
        'label' => [
            'required_without' => 'Choose a pay component or type a label.',
        ],
        'ld_type_other' => [
            'required_if' => 'Say what kind of learning and development it was.',
        ],
        'solo_parent_id_number' => [
            'required_if' => 'Enter the solo parent ID number.',
        ],
        'year' => [
            'required_if' => 'Enter the year of the annual SALN.',
        ],
        'as_of' => [
            'required_unless' => 'Enter the date the SALN is as of.',
        ],
        'business_interests' => [
            'required_unless' => 'List your business interests, or tick that you have none.',
        ],
        'relatives' => [
            'required_unless' => 'List your relatives in government, or tick that you have none.',
        ],
        'effective_date' => [
            'required_if' => 'Enter the effective date to record the notice.',
        ],
        'rate_basis' => [
            'required_with' => 'Choose whether the rate is daily or monthly.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    // IMIS: names for request keys that read badly as they are (ids, short
    // forms, nested rows). A form or validator that passes its own names
    // (e.g. LeaveApplications::attributes()) still wins.
    'attributes' => [
        // Record corrections
        'field' => 'item to correct',
        'proposed_value' => 'correct value',

        // People, offices and links
        'employee_id' => 'employee',
        'employee_ids.*' => 'employee',
        'user_id' => 'user',
        'user_ids.*' => 'user',
        'immediate_supervisor_id' => 'immediate supervisor',
        'organizational_unit_id' => 'office',
        'from_unit_id' => 'office it is from',
        'to_unit_id' => 'office it is to',
        'parent_id' => 'parent office',
        'acting_for_id' => 'person acted for',
        'delegate_id' => 'officer-in-charge',
        'designee_id' => 'designee',
        'signatory_id' => 'signatory',
        'charged_to_id' => 'leave charged to',
        'excess_charged_to_id' => 'leave the excess is charged to',
        'category_id' => 'category',
        'appointment_id' => 'appointment',
        'schedule_id' => 'schedule',
        'work_schedule_id' => 'work schedule',
        'leave_type_id' => 'type of leave',
        'pay_component_id' => 'pay component',
        'payroll_entry_id' => 'payroll month',
        'application_id' => 'system',
        'application_role_id' => 'role',
        'access.*.application_id' => 'system',
        'access.*.application_role_id' => 'role',
        'entries.*.application_role_id' => 'role',
        'entries.*.user_id' => 'user',
        'recruitment_vacancy_id' => 'vacancy',
        'biometric_id' => 'biometric ID',
        'saln_id' => 'SALN',
        'q40b_id' => 'ID number (item 40b)',
        'q40c_id' => 'ID number (item 40c)',
        'tin_no' => 'TIN',

        // Learning and development
        'ld_type' => 'type of learning and development',
        'ld_type_other' => 'other type of learning and development',

        // DTR
        'entries.*.date' => 'date',
        'entries.*.time_in' => 'time in',
        'entries.*.time_out' => 'time out',
        'entries.*.remark' => 'remark',
        'rest_weekdays.*' => 'rest day',

        // Release notes
        'items.*.type' => 'kind of change',
        'items.*.description' => 'description',
        'publish_at' => 'publication date',
        'expires_at' => 'expiry date',

        // SALN statement
        'declarant.family_name' => 'family name',
        'declarant.first_name' => 'first name',
        'declarant.middle_initial' => 'middle initial',
        'declarant.position' => 'position',
        'declarant.agency' => 'agency or office',
        'declarant.office_address' => 'office address',
        'spouse.family_name' => "spouse's family name",
        'spouse.first_name' => "spouse's first name",
        'spouse.middle_initial' => "spouse's middle initial",
        'spouse.position' => "spouse's position",
        'spouse.agency' => "spouse's agency or office",
        'spouse.office_address' => "spouse's office address",
        'other_spouses.*' => 'other spouse',
        'children.*.name' => "child's name",
        'children.*.birth_date' => "child's date of birth",
        'children.*.age' => "child's age",
        'real_properties.*.owner' => 'owner',
        'real_properties.*.description' => 'description',
        'real_properties.*.kind' => 'kind',
        'real_properties.*.location' => 'location',
        'real_properties.*.assessed_value' => 'assessed value',
        'real_properties.*.market_value' => 'current fair market value',
        'real_properties.*.acquisition_year' => 'year acquired',
        'real_properties.*.acquisition_mode' => 'mode of acquisition',
        'real_properties.*.acquisition_cost' => 'acquisition cost',
        'personal_properties.*.owner' => 'owner',
        'personal_properties.*.description' => 'description',
        'personal_properties.*.acquisition_year' => 'year acquired',
        'personal_properties.*.acquisition_cost' => 'acquisition cost',
        'liabilities.*.owner' => 'owner',
        'liabilities.*.nature' => 'nature of the liability',
        'liabilities.*.creditor' => 'creditor',
        'liabilities.*.outstanding_balance' => 'outstanding balance',
        'business_interests.*.owner' => 'owner',
        'business_interests.*.name' => 'name of the business',
        'business_interests.*.address' => 'business address',
        'business_interests.*.nature' => 'nature of the business',
        'business_interests.*.acquired_on' => 'date acquired',
        'relatives.*.name' => "relative's name",
        'relatives.*.relationship' => 'relationship',
        'relatives.*.position' => "relative's position",
        'relatives.*.agency_and_address' => "relative's agency and address",
        'government_id.type' => 'government ID',
        'government_id.number' => 'ID number',
        'government_id.issued_on' => 'date issued',
        'filing_mode' => 'joint or separate filing',
        'oath_mode' => 'oath',
        'officer_name' => 'administering officer',
        'officer_title' => "administering officer's title",
    ],

];
