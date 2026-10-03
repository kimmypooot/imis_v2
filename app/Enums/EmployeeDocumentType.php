<?php

namespace App\Enums;

/**
 * Kinds of document kept in a 201 file — the usual CSC checklist, with
 * "Other" (described by its title) for anything else.
 */
enum EmployeeDocumentType: string
{
    case Appointment = 'appointment';
    case OathOfOffice = 'oath_of_office';
    case AssumptionToDuty = 'assumption_to_duty';
    case PositionDescription = 'position_description';
    case PersonalDataSheet = 'personal_data_sheet';
    case WorkExperienceSheet = 'work_experience_sheet';
    case Diploma = 'diploma';
    case TranscriptOfRecords = 'transcript_of_records';
    case Eligibility = 'eligibility';
    case ProfessionalLicense = 'professional_license';
    case BirthCertificate = 'birth_certificate';
    case MarriageCertificate = 'marriage_certificate';
    case NbiClearance = 'nbi_clearance';
    case MedicalCertificate = 'medical_certificate';
    case Saln = 'saln';
    case SalaryAdjustment = 'salary_adjustment';
    case PerformanceRating = 'performance_rating';
    case TrainingCertificate = 'training_certificate';
    case OfficeOrder = 'office_order';
    case ServiceRecord = 'service_record';
    case Clearance = 'clearance';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Appointment => 'Appointment (CS Form No. 33)',
            self::OathOfOffice => 'Oath of Office',
            self::AssumptionToDuty => 'Certificate of Assumption to Duty',
            self::PositionDescription => 'Position Description Form',
            self::PersonalDataSheet => 'Personal Data Sheet (signed)',
            self::WorkExperienceSheet => 'Work Experience Sheet',
            self::Diploma => 'Diploma',
            self::TranscriptOfRecords => 'Transcript of Records',
            self::Eligibility => 'Certificate of Eligibility / Rating',
            self::ProfessionalLicense => 'PRC License / Board Certificate',
            self::BirthCertificate => 'Birth Certificate (PSA)',
            self::MarriageCertificate => 'Marriage Certificate (PSA)',
            self::NbiClearance => 'NBI Clearance',
            self::MedicalCertificate => 'Medical Certificate',
            self::Saln => 'SALN',
            self::SalaryAdjustment => 'Notice of Salary Adjustment / Step Increment',
            self::PerformanceRating => 'Performance Rating (IPCR)',
            self::TrainingCertificate => 'Training Certificate',
            self::OfficeOrder => 'Office Order / Designation',
            self::ServiceRecord => 'Service Record',
            self::Clearance => 'Clearance',
            self::Other => 'Other',
        };
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $case) => ['value' => $case->value, 'label' => $case->label()], self::cases());
    }
}
