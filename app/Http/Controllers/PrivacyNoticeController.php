<?php

namespace App\Http\Controllers;

use App\Support\Privacy\PrivacyNotice;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-2: the privacy notice (RA 10173). Open to everyone, signed in or
 * not, so the sign-in page can link to it before anyone gives a password.
 */
class PrivacyNoticeController extends Controller
{
    public function __invoke(PrivacyNotice $notice): Response
    {
        return Inertia::render('Privacy', ['notice' => $notice->build()]);
    }
}
