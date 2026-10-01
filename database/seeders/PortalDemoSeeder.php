<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Enums\Visibility;
use App\Models\Application;
use App\Models\Service;
use App\Models\ServiceCategory;
use Illuminate\Database\Seeder;

/**
 * Deterministic development/demo data for the portal directory. Contains
 * no real CSC RO VIII organizational data, no real services, and no
 * production URLs — see the inline notes on each record for why.
 */
class PortalDemoSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedApplications();
        $this->seedServices();
    }

    /**
     * These names come directly from the project's own Phase 4
     * specification (the real, known CSC RO VIII ITG systems), so listing
     * them is using documented information, not inventing it. Their URLs
     * are intentionally left null — this project has no authority to
     * assign them a production address — so the portal renders them as
     * "not yet configured" rather than a fabricated link.
     */
    private function seedApplications(): void
    {
        $applications = [
            ['name' => 'Online Tracking and Reporting System', 'short_name' => 'OTRS', 'category' => 'records'],
            ['name' => 'ORS / HRMPSB Connect', 'short_name' => 'ORS', 'category' => 'human-resources'],
            ['name' => 'PROCTAD', 'short_name' => 'PROCTAD', 'category' => 'examinations'],
            ['name' => 'CSE / COMEXAMS', 'short_name' => 'COMEXAMS', 'category' => 'examinations'],
            ['name' => 'Examinee Number Lookup', 'short_name' => 'ENL', 'category' => 'examinations'],
            ['name' => 'C-ERIS', 'short_name' => 'C-ERIS', 'category' => 'records'],
        ];

        foreach ($applications as $index => $application) {
            $record = Application::query()->firstOrCreate(
                ['name' => $application['name']],
                [
                    'short_name' => $application['short_name'],
                    'description' => 'Demonstration directory entry — link not yet configured.',
                    'icon' => 'grid',
                    'url' => null,
                    'category' => $application['category'],
                    'external' => true,
                    'featured' => true,
                    'status' => Status::Active,
                    'visibility' => Visibility::Authenticated,
                    'sort_order' => $index + 1,
                ],
            );

            // The legacy IMIS gave every system the same base levels
            // (User < Admin); each system's real roles are configured by
            // its administrators from the Roles & access page.
            foreach (['User', 'Admin'] as $order => $role) {
                $record->roles()->firstOrCreate(['name' => $role], ['sort_order' => $order]);
            }
        }
    }

    /**
     * No specific CSC RO VIII frontline services are established in this
     * project's documentation, so these are explicitly labeled demo
     * placeholders rather than invented official services.
     */
    private function seedServices(): void
    {
        $categories = [
            'Frontline (Demo)' => 'Demonstration category for over-the-counter services.',
            'Certification (Demo)' => 'Demonstration category for certificate-issuance services.',
        ];

        $categoryModels = [];

        foreach ($categories as $name => $description) {
            $categoryModels[$name] = ServiceCategory::query()->firstOrCreate(
                ['name' => $name],
                [
                    'slug' => ServiceCategory::generateUniqueSlug($name),
                    'description' => $description,
                    'status' => Status::Active,
                    'sort_order' => count($categoryModels) + 1,
                ],
            );
        }

        $services = [
            [
                'name' => 'Sample Frontline Service (Demo)',
                'category' => 'Frontline (Demo)',
                'purpose' => 'Demonstrates how a service entry presents its purpose.',
                'requirements' => 'Valid ID; demonstration data only.',
                'procedure' => 'Visit the responsible office; demonstration data only.',
                'fees' => 'None (demo)',
                'processing_time' => 'Same day (demo)',
            ],
            [
                'name' => 'Sample Certification Request (Demo)',
                'category' => 'Certification (Demo)',
                'purpose' => 'Demonstrates how a service entry presents its purpose.',
                'requirements' => 'Completed request form; demonstration data only.',
                'procedure' => 'Submit a request; demonstration data only.',
                'fees' => '₱50.00 (demo)',
                'processing_time' => '3 working days (demo)',
            ],
        ];

        foreach ($services as $index => $service) {
            Service::query()->firstOrCreate(
                ['name' => $service['name']],
                [
                    'slug' => Service::generateUniqueSlug($service['name']),
                    'category_id' => $categoryModels[$service['category']]->id,
                    'description' => 'Demonstration service entry for the Phase 4/6 portal foundation.',
                    'purpose' => $service['purpose'],
                    'requirements' => $service['requirements'],
                    'procedure' => $service['procedure'],
                    'fees' => $service['fees'],
                    'processing_time' => $service['processing_time'],
                    'icon' => 'clipboard',
                    'external_url' => null,
                    'status' => Status::Active,
                    'visibility' => Visibility::Authenticated,
                    'featured' => $index === 0,
                    'sort_order' => $index + 1,
                ],
            );
        }
    }
}
