<?php

namespace Tests\Feature\HR;

use App\Enums\EmployeeDocumentType;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Models\Pds\PdsSubmission;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\HR\Pds\PdfSignatures;
use App\Support\HR\Pds\PdsPnpki;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A PNPKI-signed copy of a signed PDS: every digital signature in the PDF
 * is checked (intact, nothing added after the last, one per page) before
 * it is filed in the 201 File. The PDFs here are signed for real, with a
 * throwaway certificate, one incremental signature at a time as Acrobat
 * does.
 */
class PdsPnpkiTest extends TestCase
{
    use RefreshDatabase;

    /** @var array{cert: string, key: string}|null */
    private static ?array $signer = null;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_each_signature_in_a_pdf_is_checked(): void
    {
        $signatures = app(PdfSignatures::class);

        $good = $signatures->inspect($this->file($this->signedPdf(3)));
        $this->assertCount(3, $good['signatures']);
        $this->assertTrue($good['unchanged_since_last']);
        $this->assertTrue(collect($good['signatures'])->every(fn ($signature) => $signature['intact']));
        $this->assertSame('JUAN S. DELA CRUZ', $good['signatures'][0]['signer']);
        $this->assertSame('Test PNPKI CA', $good['signatures'][0]['issuer']);
        $this->assertNull($good['signatures'][0]['trusted']);

        // A byte changed in what was signed.
        $tampered = $signatures->inspect($this->file(str_replace('/Type /Catalog', '/Type /Catalug', $this->signedPdf(2))));
        $this->assertFalse($tampered['signatures'][0]['intact']);
        $this->assertSame('The signed content has been changed since it was signed.', $tampered['signatures'][0]['problem']);

        // Something added after the last signature.
        $this->assertFalse($signatures->inspect($this->file($this->signedPdf(2)."\n% added later\n"))['unchanged_since_last']);

        $this->assertSame([], $signatures->inspect($this->file("%PDF-1.7\n%%EOF\n"))['signatures']);
    }

    public function test_the_employee_attaches_the_signed_copy_and_it_is_filed_in_the_201_file(): void
    {
        $employee = $this->employee();
        $submission = $this->sign($employee);
        $pages = app(PdsPnpki::class)->pagesOf($submission);

        $this->actingAs($employee->user)->get("/my/pds/submissions/{$submission->id}")
            ->assertInertia(fn (Assert $page) => $page->where('pnpki.copy', null)->where('pnpki.can_attach', true)->where('pnpki.pages', $pages));

        $this->actingAs($employee->user)
            ->post("/my/pds/submissions/{$submission->id}/pnpki", ['file' => $this->upload($this->signedPdf($pages))])
            ->assertSessionHasNoErrors();

        $document = EmployeeDocument::query()->where('employee_id', $employee->id)->sole();
        $this->assertSame(EmployeeDocumentType::PersonalDataSheet, $document->document_type);
        Storage::disk('local')->assertExists($document->file_path);
        $submission->refresh();
        $this->assertSame($document->id, $submission->pnpki_document_id);
        $this->assertCount($pages, $submission->pnpki_signatures['signatures']);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'employee_document.uploaded')->count());

        // HR sees it when verifying.
        $this->actingAs($this->userWith(['pds.verify', 'pds.view']))->get("/admin/hr/pds-verifications/{$submission->id}")
            ->assertInertia(fn (Assert $page) => $page->where('pnpki.document_id', $document->id)->has('pnpki.signatures', $pages));

        // A newer copy replaces it; the old one is archived, never deleted.
        $this->actingAs($employee->user)
            ->post("/my/pds/submissions/{$submission->id}/pnpki", ['file' => $this->upload($this->signedPdf($pages))])
            ->assertSessionHasNoErrors();
        $this->assertNotNull($document->refresh()->archived_at);
        $this->assertSame('Replaced by a newer PNPKI-signed copy.', $document->archive_reason);
        $this->assertNotSame($document->id, $submission->refresh()->pnpki_document_id);
    }

    public function test_a_copy_that_is_not_properly_signed_is_refused(): void
    {
        $employee = $this->employee();
        $submission = $this->sign($employee);
        $pages = app(PdsPnpki::class)->pagesOf($submission);
        $attach = fn (string $pdf) => $this->actingAs($employee->user)->post("/my/pds/submissions/{$submission->id}/pnpki", ['file' => $this->upload($pdf)]);

        $attach("%PDF-1.7\n%%EOF\n")->assertSessionHasErrors(['file' => "This PDF has no digital signature. Sign each page's signature box with your PNPKI certificate, save, and attach the signed PDF."]);
        $attach($this->signedPdf($pages - 1))->assertSessionHasErrors(['file' => 'This PDF has '.($pages - 1)." signatures but your PDS has {$pages} pages. Sign the signature box on every page."]);
        $attach($this->signedPdf($pages)."\n% added later\n")->assertSessionHasErrors('file');
        $attach(str_replace('/Type /Catalog', '/Type /Catalug', $this->signedPdf($pages)))->assertSessionHasErrors('file');

        $this->assertSame(0, EmployeeDocument::query()->count());
        $this->assertNull($submission->refresh()->pnpki_document_id);

        // Someone else's submission isn't theirs to attach to.
        $this->actingAs($this->employee()->user)
            ->post("/my/pds/submissions/{$submission->id}/pnpki", ['file' => $this->upload($this->signedPdf($pages))])
            ->assertNotFound();
    }

    // ── Helpers ──────────────────────────────────────────────────────

    /**
     * A PDF with `$count` digital signatures, each an incremental update
     * covering everything before it, as Acrobat saves them.
     */
    private function signedPdf(int $count): string
    {
        $signer = $this->signer();
        $pdf = "%PDF-1.7\n1 0 obj\n<< /Type /Catalog >>\nendobj\n";
        $placeholder = str_repeat('0', 8192);

        for ($i = 0; $i < $count; $i++) {
            $pdf .= "\n".($i + 2)." 0 obj\n<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /M (D:20261127090000+08'00') "
                ."/ByteRange [0000000000 0000000000 0000000000 0000000000] /Contents <{$placeholder}> >>\nendobj\n%%EOF\n";
            $start = strrpos($pdf, '/Contents <') + strlen('/Contents ');
            $end = $start + 2 + strlen($placeholder);
            $range = sprintf('[%010d %010d %010d %010d]', 0, $start, $end, strlen($pdf) - $end);
            $at = strrpos($pdf, '[0000000000 0000000000 0000000000 0000000000]');
            $pdf = substr_replace($pdf, $range, $at, strlen($range));

            $in = tempnam(sys_get_temp_dir(), 'sig');
            $out = tempnam(sys_get_temp_dir(), 'sig');
            file_put_contents($in, substr($pdf, 0, $start).substr($pdf, $end));
            openssl_pkcs7_sign($in, $out, $signer['cert'], $signer['key'], [], PKCS7_DETACHED | PKCS7_BINARY);
            // The signature part (not the "protocol=" mention in the message's header).
            preg_match('/Content-Type: application\/(?:x-)?pkcs7-signature.*?\r?\n\r?\n(.*?)\r?\n\r?\n?------/s', (string) file_get_contents($out), $part);
            @unlink($in);
            @unlink($out);

            $hex = bin2hex(base64_decode(preg_replace('/\s+/', '', $part[1])));
            $pdf = substr_replace($pdf, str_pad($hex, strlen($placeholder), '0'), $start + 1, strlen($placeholder));
        }

        return $pdf;
    }

    /**
     * A self-signed test certificate, made once.
     *
     * @return array{cert: string, key: string}
     */
    private function signer(): array
    {
        if (self::$signer !== null) {
            return self::$signer;
        }

        // Windows PHP needs an OpenSSL config to make keys and certificates.
        $config = array_filter([getenv('OPENSSL_CONF') ?: null, 'C:/xampp/php/extras/ssl/openssl.cnf'], fn ($path) => $path && is_file($path));
        $options = $config ? ['config' => reset($config)] : [];

        $key = openssl_pkey_new($options + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'JUAN S. DELA CRUZ'], $key, $options);
        $issuer = openssl_pkey_new($options + ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $ca = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test PNPKI CA'], $issuer, $options), null, $issuer, 30, $options);
        $cert = openssl_csr_sign($csr, $ca, $issuer, 30, $options);
        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem, null, $options);

        return self::$signer = ['cert' => $certPem, 'key' => $keyPem];
    }

    private function file(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(fn () => @unlink($path));

        return $path;
    }

    private function upload(string $pdf): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('signed.pdf', $pdf);
    }

    private function sign(Employee $employee): PdsSubmission
    {
        $this->actingAs($employee->user)->post('/my/pds/submit', ['declaration' => true, 'password' => 'password'])->assertSessionHasNoErrors();

        return PdsSubmission::query()->where('employee_id', $employee->id)->latest('id')->firstOrFail();
    }

    private function employee(): Employee
    {
        return Employee::factory()->for(User::factory())->create();
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function userWith(array $permissions): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        foreach ($permissions as $permission) {
            $role->permissions()->attach(Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]));
        }
        $user->roles()->attach($role);

        return $user;
    }
}
