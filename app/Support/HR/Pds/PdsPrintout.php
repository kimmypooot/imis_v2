<?php

namespace App\Support\HR\Pds;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * A PDS on CS Form No. 212 (Revised 2026), for the print page and as the
 * Excel copy: both from PdsForm, so they show the same entries. Used for
 * one's own PDS, HR's view of someone's, and a signed submission.
 */
class PdsPrintout
{
    public function __construct(private PdsForm $form, private PdsFormWorkbook $workbook) {}

    /**
     * Props for HR/PdsPrint.
     *
     * @param  array{identity: array<string, mixed>, sections: array<string, mixed>}  $pds
     * @param  array{name: string, signed_at: string, status: string}|null  $signature
     * @return array<string, mixed>
     */
    public function page(array $pds, ?string $photoUrl, ?array $signature, string $excelUrl): array
    {
        return [
            'pages' => $this->form->pages($pds, $signature),
            'photoUrl' => $photoUrl,
            'signature' => $signature,
            'excelUrl' => $excelUrl,
        ];
    }

    /**
     * The filled-in workbook as a download.
     *
     * @param  array{identity: array<string, mixed>, sections: array<string, mixed>}  $pds
     * @param  array{name: string, signed_at: string}|null  $signature
     * @param  string|null  $photoPath  the photo's path on PdsPhotos::DISK
     */
    public function download(array $pds, ?string $photoPath, ?array $signature): Response
    {
        $photo = $photoPath && Storage::disk(PdsPhotos::DISK)->exists($photoPath) ? Storage::disk(PdsPhotos::DISK)->path($photoPath) : null;
        $name = trim(($pds['identity']['surname'] ?? '').', '.($pds['identity']['first_name'] ?? ''), ', ');
        $filename = preg_replace('/[^\w .,-]/u', '', "PDS - {$name}") ?: 'PDS';

        return response($this->workbook->build($this->form->pages($pds, $signature), $photo), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.xlsx"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
