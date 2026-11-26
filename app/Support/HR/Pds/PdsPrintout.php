<?php

namespace App\Support\HR\Pds;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

/**
 * A PDS on CS Form No. 212 (Revised 2026), for the print page and as the
 * Excel copy: both from PdsForm, so they show the same entries. Used for
 * one's own PDS, HR's view of someone's, and a signed submission.
 *
 * Each is printed for one way of signing (`?signature=`): by hand (boxes
 * and dates blank), with a PNPKI digital signature applied to the saved
 * PDF (boxes blank, dates filled), or with the IMIS e-signature (only a
 * signed copy, which keeps the signer's e-signature).
 */
class PdsPrintout
{
    public function __construct(private PdsForm $form, private PdsFormWorkbook $workbook) {}

    /**
     * The way of signing asked for, if it's one this PDS can have; else the
     * default: the e-signature for a signed copy, by hand otherwise.
     *
     * @param  array<string, mixed>|null  $signature  null for the current (unsigned) PDS
     */
    public function mode(Request $request, ?array $signature): string
    {
        $asked = (string) $request->query('signature', '');

        return array_key_exists($asked, $this->modes($signature)) ? $asked : ($signature !== null ? PdsForm::ESIGN : PdsForm::HAND);
    }

    /**
     * Props for HR/PdsPrint.
     *
     * @param  array{identity: array<string, mixed>, sections: array<string, mixed>}  $pds
     * @param  array{name: string, signed_at: string, status: string, image_url: ?string, image_file: ?string}|null  $signature
     * @param  string  $printUrl  this page, without a query
     * @param  string  $excelUrl  the Excel copy, without a query
     * @return array<string, mixed>
     */
    public function page(array $pds, ?string $photoUrl, ?array $signature, string $printUrl, string $excelUrl, string $mode): array
    {
        return [
            'pages' => $this->form->pages($pds, $signature, $mode),
            'photoUrl' => $photoUrl,
            // The image's file on disk is for the Excel copy only, never the browser.
            'signature' => $signature === null ? null : array_diff_key($signature, ['image_file' => true]),
            'mode' => $mode,
            'modes' => collect($this->modes($signature))
                ->map(fn (array $option, string $value) => [...$option, 'value' => $value, 'url' => "{$printUrl}?signature={$value}"])
                ->values()
                ->all(),
            'excelUrl' => "{$excelUrl}?signature={$mode}",
        ];
    }

    /**
     * The filled-in workbook as a download.
     *
     * @param  array{identity: array<string, mixed>, sections: array<string, mixed>}  $pds
     * @param  array{name: string, signed_at: string, image_file?: ?string}|null  $signature
     * @param  string|null  $photoPath  the photo's path on PdsPhotos::DISK
     */
    public function download(array $pds, ?string $photoPath, ?array $signature, string $mode): Response
    {
        $photo = $photoPath && Storage::disk(PdsPhotos::DISK)->exists($photoPath) ? Storage::disk(PdsPhotos::DISK)->path($photoPath) : null;
        $name = trim(($pds['identity']['surname'] ?? '').', '.($pds['identity']['first_name'] ?? ''), ', ');
        $filename = preg_replace('/[^\w .,-]/u', '', "PDS - {$name}") ?: 'PDS';
        $image = $mode === PdsForm::ESIGN ? ($signature['image_file'] ?? null) : null;

        return response($this->workbook->build($this->form->pages($pds, $signature, $mode), $photo, $image), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.xlsx"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * The ways this PDS can be signed on paper.
     *
     * @param  array<string, mixed>|null  $signature
     * @return array<string, array{label: string, description: string}>
     */
    private function modes(?array $signature): array
    {
        $modes = [
            PdsForm::HAND => ['label' => 'Sign by hand', 'description' => 'The signature boxes and their dates are left blank, to sign and date by hand.'],
            PdsForm::PNPKI => ['label' => 'PNPKI digital signature', 'description' => 'The signature boxes are left blank and the dates filled in. Save it as PDF, then sign each box with the PNPKI certificate (Adobe Acrobat or the PNPKI signing tool).'],
        ];

        if ($signature !== null) {
            $modes[PdsForm::ESIGN] = [
                'label' => 'E-signature',
                'description' => ! empty($signature['image_url']) || ! empty($signature['image_file'])
                    ? 'The signer\'s e-signature, as kept when this PDS was signed, in every signature box, with the date signed.'
                    : 'No e-signature image was saved when this PDS was signed, so each box notes that it was signed through IMIS.',
            ];
        }

        return $modes;
    }
}
