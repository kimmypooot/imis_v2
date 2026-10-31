<?php

namespace App\Http\Controllers;

use App\Enums\Status;
use App\Http\Resources\ApplicationResource;
use App\Http\Resources\OrganizationalUnitResource;
use App\Http\Resources\ServiceResource;
use App\Models\OrganizationalUnit;
use App\Support\Portal\OfficeDirectory;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationalUnitController extends Controller
{
    public function index(OfficeDirectory $directory): Response
    {
        return Inertia::render('Portal/OrganizationalUnits/Index', [
            'groups' => $directory->groups(),
        ]);
    }

    public function show(Request $request, OrganizationalUnit $organizationalUnit): Response
    {
        abort_unless($organizationalUnit->status === Status::Active, 404);

        $user = $request->user();

        $organizationalUnit->load('parent:id,name');

        $children = $organizationalUnit->children()->active()->orderBy('sort_order')->orderBy('name')->get();
        $applications = $organizationalUnit->applications()->visibleTo($user)->withAccessFor($user)->orderBy('sort_order')->get();
        $services = $organizationalUnit->services()->visibleTo($user)->orderBy('sort_order')->get();

        return Inertia::render('Portal/OrganizationalUnits/Show', [
            'organizationalUnit' => new OrganizationalUnitResource($organizationalUnit),
            'children' => OrganizationalUnitResource::collection($children),
            'applications' => ApplicationResource::collection($applications),
            'services' => ServiceResource::collection($services),
        ]);
    }
}
