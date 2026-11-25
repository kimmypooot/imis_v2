<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fuller descriptions for the standing committees, shown on the committee
 * list and on each committee's page. Only the short placeholder each one
 * was seeded with (or a blank) is replaced, so a description an admin has
 * already written is kept.
 */
return new class extends Migration
{
    /**
     * short_name => [the seeded placeholder, the description].
     *
     * @var array<string, array{0: ?string, 1: string}>
     */
    private const DESCRIPTIONS = [
        'ITG' => [
            'IT service requests and technical support',
            'Handles the regional office\'s information and communications technology. It acts on IT service requests filed through ICT SRTS, gives technical support to employees, maintains computers, printers and the network, and keeps IMIS and the systems under it running, secured and backed up.',
        ],
        'GAD' => [
            'Gender and development programs and initiatives',
            'The office\'s GAD Focal Point System under the Magna Carta of Women (RA 9710). It prepares the annual GAD Plan and Budget and the GAD Accomplishment Report, sees that gender is considered in the office\'s programs and services, keeps sex-disaggregated data, and runs the GAD Corner and the office\'s gender-awareness activities.',
        ],
        'BAC' => [
            'Procurement',
            'Conducts the office\'s procurement under the Government Procurement Act. It holds the pre-procurement and pre-bid conferences, determines the eligibility of bidders, opens and evaluates bids, conducts post-qualification and recommends the award of contracts to the Head of the Procuring Entity, for competitive bidding and the alternative modes alike.',
        ],
        'PMT' => [
            'Employee performance management and tracking',
            'The Performance Management Team under the Strategic Performance Management System (CSC MC No. 6, s. 2012). It reviews the office and division performance commitments so they support the office\'s goals, validates the ratings at the end of each period, recommends them for approval by the Head of Office, and acts on appeals from performance ratings.',
        ],
        'SALN' => [
            null,
            'Takes charge of the yearly Statements of Assets, Liabilities and Net Worth (SALN) under RA 6713. It receives the statements filed by employees, reviews them for completeness and correct form, asks for corrections where needed, and keeps and transmits the statements to the offices concerned on time.',
        ],
    ];

    public function up(): void
    {
        foreach (self::DESCRIPTIONS as $shortName => [$placeholder, $description]) {
            DB::table('committees')
                ->where('short_name', $shortName)
                ->where(fn ($query) => $query->whereNull('description')->orWhere('description', '')->when($placeholder, fn ($query) => $query->orWhere('description', $placeholder)))
                ->update(['description' => $description, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::DESCRIPTIONS as $shortName => [$placeholder, $description]) {
            DB::table('committees')->where('short_name', $shortName)->where('description', $description)->update(['description' => $placeholder]);
        }
    }
};
