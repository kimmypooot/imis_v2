<?php

namespace App\Http\Controllers;

use App\Support\Guide\UserGuide;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The in-app User Guide: task-based how-to articles for the people using
 * IMIS, limited to what applies to the signed-in person (UserGuide).
 */
class GuideController extends Controller
{
    public function __invoke(Request $request, UserGuide $guide): Response
    {
        return Inertia::render('Portal/Guide', [
            'categories' => $guide->for($request->user()),
        ]);
    }
}
