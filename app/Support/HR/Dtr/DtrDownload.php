<?php

namespace App\Support\HR\Dtr;

use Illuminate\Http\Response;

/**
 * DTR: an Excel workbook as a download.
 */
class DtrDownload
{
    public static function xlsx(string $bytes, string $name): Response
    {
        $safe = trim(preg_replace('/[^A-Za-z0-9 ._,\-]+/', ' ', $name)) ?: 'CS Form 48';

        return response($bytes, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$safe.'.xlsx"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
