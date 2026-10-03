<?php

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Committee;
use App\Models\OrganizationalUnit;
use App\Models\Service;
use App\Models\User;
use App\Support\Admin\AdminDashboard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The admin dashboard, laid out like the legacy IMIS admin home: welcome
 * banner, metric cards, charts, and tables (AdminDashboard), plus real
 * inactive-record counts. It manages nothing itself.
 */
class AdminController extends Controller
{
    /**
     * Modules this dashboard can report an inactive-record count for,
     * keyed by the permission that must be held to see that count. Only
     * real counts are ever shown — never an invented/placeholder metric.
     *
     * @var array<string, array{label: string, href: string, model: class-string}>
     */
    private const ATTENTION_SOURCES = [
        'organizational_units.view' => [
            'label' => 'Organizational Units',
            'href' => '/admin/organizational-units',
            'model' => OrganizationalUnit::class,
        ],
        'services.view' => [
            'label' => 'Services',
            'href' => '/admin/services',
            'model' => Service::class,
        ],
        'applications.view' => [
            'label' => 'Systems / Applications',
            'href' => '/admin/applications',
            'model' => Application::class,
        ],
        'users.view' => [
            'label' => 'Users',
            'href' => '/users',
            'model' => User::class,
        ],
        'committees.view' => [
            'label' => 'Committees',
            'href' => '/admin/committees',
            'model' => Committee::class,
        ],
    ];

    public function index(Request $request): Response
    {
        $this->authorize('access-admin');

        $user = $request->user();

        $attention = collect(self::ATTENTION_SOURCES)
            ->filter(fn ($source, $permission) => $user->hasPermission($permission))
            ->map(fn ($source) => [
                'label' => $source['label'],
                'href' => $source['href'],
                // 'inactive' is shared by every status enum this dashboard
                // reports on (Status, UserStatus); querying the raw value
                // keeps this config enum-agnostic rather than binding each
                // row to a specific enum class.
                'inactiveCount' => $source['model']::query()->where('status', 'inactive')->count(),
            ])
            ->filter(fn ($row) => $row['inactiveCount'] > 0)
            ->values();

        return Inertia::render('Admin/Index', [
            'attention' => $attention,
            // The legacy admin dashboard (metric cards, charts, tables).
            'dashboard' => fn () => (new AdminDashboard($user))->build(),
        ]);
    }
}
