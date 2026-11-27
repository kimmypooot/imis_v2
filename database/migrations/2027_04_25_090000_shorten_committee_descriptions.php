<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Short committee descriptions, worded like the offices' ("Legal case
 * monitoring and management"), so the dashboard's directory cards stay one
 * line. They replace the paragraphs written by describe_committees, but only
 * while a committee still has that paragraph: anything an admin has written
 * since is kept.
 */
return new class extends Migration
{
    /**
     * short_name => [the paragraph, the short description].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const DESCRIPTIONS = [
        'BAC' => [
            'Conducts the office\'s procurement under the Government Procurement Act. It holds the pre-procurement and pre-bid conferences, determines the eligibility of bidders, opens and evaluates bids, conducts post-qualification and recommends the award of contracts to the Head of the Procuring Entity, for competitive bidding and the alternative modes alike.',
            'Procurement and bidding management',
        ],
        'GAD' => [
            'The office\'s GAD Focal Point System under the Magna Carta of Women (RA 9710). It prepares the annual GAD Plan and Budget and the GAD Accomplishment Report, sees that gender is considered in the office\'s programs and services, keeps sex-disaggregated data, and runs the GAD Corner and the office\'s gender-awareness activities.',
            'Gender and development programs and reports',
        ],
        'ITG' => [
            'Handles the regional office\'s information and communications technology. It acts on IT service requests filed through ICT SRTS, gives technical support to employees, maintains computers, printers and the network, and keeps IMIS and the systems under it running, secured and backed up.',
            'ICT support and systems maintenance',
        ],
        'PMT' => [
            'The Performance Management Team under the Strategic Performance Management System (CSC MC No. 6, s. 2012). It reviews the office and division performance commitments so they support the office\'s goals, validates the ratings at the end of each period, recommends them for approval by the Head of Office, and acts on appeals from performance ratings.',
            'Performance commitments and ratings review',
        ],
        'SALN' => [
            'Takes charge of the yearly Statements of Assets, Liabilities and Net Worth (SALN) under RA 6713. It receives the statements filed by employees, reviews them for completeness and correct form, asks for corrections where needed, and keeps and transmits the statements to the offices concerned on time.',
            'SALN filing and review',
        ],
    ];

    public function up(): void
    {
        foreach (self::DESCRIPTIONS as $shortName => [$paragraph, $short]) {
            DB::table('committees')
                ->where('short_name', $shortName)
                ->where('description', $paragraph)
                ->update(['description' => $short, 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        foreach (self::DESCRIPTIONS as $shortName => [$paragraph, $short]) {
            DB::table('committees')
                ->where('short_name', $shortName)
                ->where('description', $short)
                ->update(['description' => $paragraph, 'updated_at' => now()]);
        }
    }
};
