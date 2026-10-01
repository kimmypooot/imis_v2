<?php

namespace App\Http\Controllers;

use App\Http\Resources\ApplicationResource;
use App\Models\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApplicationController extends Controller
{
    public function index(Request $request): Response
    {
        $applications = Application::query()
            ->visibleTo($request->user())
            ->withAccessFor($request->user())
            ->with('organizationalUnit:id,name')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return Inertia::render('Portal/Applications/Index', [
            'applications' => ApplicationResource::collection($applications),
        ]);
    }

    public function show(Request $request, Application $application): Response
    {
        abort_unless($application->isVisibleTo($request->user()), 404);

        $application->load('organizationalUnit:id,name')->loadAccessFor($request->user());

        return Inertia::render('Portal/Applications/Show', [
            'application' => new ApplicationResource($application),
        ]);
    }
}
