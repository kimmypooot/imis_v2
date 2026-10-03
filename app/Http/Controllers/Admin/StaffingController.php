<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlantillaItem;
use App\Support\HR\Plantilla\Staffing;
use Inertia\Inertia;
use Inertia\Response;

/**
 * HR-4: Human Resources → Staffing — per office, its plantilla items and
 * who actually serves there; holders serving away from their item's place
 * of assignment; and the vacancies, for the recruitment and staffing plans.
 * Same permissions as the plantilla.
 */
class StaffingController extends Controller
{
    public function index(Staffing $staffing): Response
    {
        $this->authorize('viewAny', PlantillaItem::class);

        return Inertia::render('Admin/HR/Staffing/Index', $staffing->overview());
    }
}
