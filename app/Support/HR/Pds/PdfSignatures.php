<?php

namespace App\Support\HR\Pds;

/**
 * Reads the digital signatures in a PDF (e.g. a PDS signed with a PNPKI
 * certificate in Acrobat) and checks each one, without any PDF or crypto
 * package: PHP's OpenSSL does the cryptography, this reads the structure.
 *
 * A PDF signature covers the file's bytes except its own /Contents (the
 * /ByteRange) and holds a CMS SignedData (adbe.pkcs7.detached or
 * ETSI.CAdES.detached). It is intact when the covered bytes hash to the
 * messageDigest it signed and the signer's certificate verifies the
 * signature over those signed attributes. The file is unchanged since its
 * last signature when that signature's range reaches the end of the file.
 *
 * Whether the certificate chains to the PNPKI root is checked only when a
 * CA bundle is configured (pds.pnpki_ca_bundle); otherwise `trusted` is
 * null (not checked).
 */
class PdfSignatures
{
    private const DIGESTS = [
        '1.3.14.3.2.26' => 'sha1',
        '2.16.840.1.101.3.4.2.1' => 'sha256',
        '2.16.840.1.101.3.4.2.2' => 'sha384',
        '2.16.840.1.101.3.4.2.3' => 'sha512',
    ];

    private const MESSAGE_DIGEST = '1.2.840.113549.1.9.4';

    private const SIGNING_TIME = '1.2.840.113549.1.9.5';

    /**
     * @return array{signatures: array<int, array{signer: ?string, issuer: ?string, signed_at: ?string, intact: bool, trusted: ?bool, problem: ?string}>, unchanged_since_last: bool}
     */
    public function inspect(string $path): array
    {
        $pdf = (string) file_get_contents($path);
        preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $pdf, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $signatures = [];
        $lastEnd = 0;
        foreach ($matches as $match) {
            [$a, $b, $c, $d] = array_map(fn ($part) => (int) $part[0], array_slice($match, 1, 4));
            $lastEnd = max($lastEnd, $c + $d);
            $signatures[] = $this->check($pdf, [$a, $b, $c, $d], $match[0][1]);
        }

        return ['signatures' => $signatures, 'unchanged_since_last' => $signatures !== [] && $lastEnd === strlen($pdf)];
    }

    /**
     * @param  array{0: int, 1: int, 2: int, 3: int}  $range
     * @return array{signer: ?string, issuer: ?string, signed_at: ?string, intact: bool, trusted: ?bool, problem: ?string}
     */
    private function check(string $pdf, array $range, int $at): array
    {
        $result = ['signer' => null, 'issuer' => null, 'signed_at' => $this->pdfTime($pdf, $at), 'intact' => false, 'trusted' => null, 'problem' => null];
        [$a, $b, $c, $d] = $range;

        if ($a !== 0 || $c + $d > strlen($pdf) || $c <= $a + $b) {
            return [...$result, 'problem' => 'Its byte range does not fit the file.'];
        }

        $hex = trim(substr($pdf, $a + $b, $c - ($a + $b)), "<> \r\n");
        $cms = ctype_xdigit($hex) && strlen($hex) % 2 === 0 ? hex2bin($hex) : false;
        if (! $cms) {
            return [...$result, 'problem' => 'Its signature value could not be read.'];
        }

        try {
            $signed = $this->signedData($cms);
        } catch (\Throwable) {
            return [...$result, 'problem' => 'Its signature is not in a format IMIS can read (CMS).'];
        }

        $content = substr($pdf, $a, $b).substr($pdf, $c, $d);
        $algorithm = self::DIGESTS[$signed['digest_oid']] ?? null;
        if ($algorithm === null) {
            return [...$result, 'problem' => 'It uses a digest algorithm IMIS does not support.'];
        }
        if ($signed['message_digest'] === null || ! hash_equals($signed['message_digest'], hash($algorithm, $content, true))) {
            return [...$result, 'problem' => 'The signed content has been changed since it was signed.'];
        }

        // The signer's certificate is the one whose key verifies the signature.
        foreach ($signed['certificates'] as $der) {
            $pem = "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END CERTIFICATE-----\n";
            $key = @openssl_pkey_get_public($pem);
            if ($key === false || openssl_verify($signed['signed_attributes'], $signed['signature'], $key, $algorithm) !== 1) {
                continue;
            }

            $info = openssl_x509_parse($pem) ?: [];
            $bundle = config('pds.pnpki_ca_bundle');

            return [
                ...$result,
                'signer' => $this->name($info['subject'] ?? []),
                'issuer' => $this->name($info['issuer'] ?? []),
                'signed_at' => $signed['signing_time'] ?? $result['signed_at'],
                'intact' => true,
                'trusted' => $bundle && is_file($bundle) ? openssl_x509_checkpurpose($pem, X509_PURPOSE_ANY, [$bundle]) === true : null,
            ];
        }

        return [...$result, 'problem' => 'Its signature does not match the certificate in it.'];
    }

    /**
     * @param  array<string, string|array<int, string>>  $dn
     */
    private function name(array $dn): ?string
    {
        $value = $dn['CN'] ?? $dn['O'] ?? null;

        return is_array($value) ? implode(', ', $value) : $value;
    }

    /** The signature dictionary's /M (D:YYYYMMDDHHmmSS), near its /ByteRange. */
    private function pdfTime(string $pdf, int $at): ?string
    {
        $around = substr($pdf, max(0, $at - 1500), 3000);
        if (! preg_match('/\/M\s*\(D:(\d{4})(\d{2})(\d{2})(\d{2})?(\d{2})?(\d{2})?/', $around, $m)) {
            return null;
        }

        return sprintf('%s-%s-%sT%s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?: '00', $m[5] ?: '00', $m[6] ?: '00');
    }

    // ── CMS SignedData (RFC 5652), read with a minimal DER reader ──────

    /**
     * @return array{digest_oid: string, signed_attributes: string, signature: string, message_digest: ?string, signing_time: ?string, certificates: array<int, string>}
     */
    private function signedData(string $der): array
    {
        [$contentInfo] = $this->children($der);                     // ContentInfo SEQUENCE
        [, $explicit] = $this->children($contentInfo['body']);      // OID signedData, [0]
        [$signedData] = $this->children($explicit['body']);         // SignedData SEQUENCE

        $certificates = [];
        $signerInfo = null;
        foreach ($this->children($signedData['body']) as $part) {
            if ($part['tag'] === 0xA0) {                            // [0] IMPLICIT certificates
                foreach ($this->children($part['body']) as $certificate) {
                    $certificates[] = $certificate['raw'];
                }
            } elseif ($part['tag'] === 0x31) {                      // the last SET is signerInfos
                $signerInfo = $this->children($part['body'])[0] ?? null;
            }
        }
        if ($signerInfo === null) {
            throw new \RuntimeException('No signer.');
        }

        $digestOid = null;
        $attributes = null;
        $signature = null;
        foreach ($this->children($signerInfo['body']) as $i => $part) {
            if ($part['tag'] === 0x30 && $digestOid === null && $i >= 2) {   // digestAlgorithm
                $digestOid = $this->oid($this->children($part['body'])[0]['body']);
            } elseif ($part['tag'] === 0xA0) {                               // [0] IMPLICIT signedAttrs
                $attributes = $part;
            } elseif ($part['tag'] === 0x04) {                               // signature
                $signature = $part['body'];
            }
        }
        if ($digestOid === null || $attributes === null || $signature === null) {
            throw new \RuntimeException('Incomplete signer.');
        }

        $messageDigest = null;
        $signingTime = null;
        foreach ($this->children($attributes['body']) as $attribute) {
            [$type, $values] = $this->children($attribute['body']);
            $value = $this->children($values['body'])[0] ?? null;
            $oid = $this->oid($type['body']);
            if ($oid === self::MESSAGE_DIGEST) {
                $messageDigest = $value['body'] ?? null;
            } elseif ($oid === self::SIGNING_TIME && $value !== null) {
                $signingTime = $this->time($value);
            }
        }

        return [
            'digest_oid' => $digestOid,
            // The signature covers the attributes as a SET (tag 0x31), not as [0].
            'signed_attributes' => "\x31".substr($attributes['raw'], 1),
            'signature' => $signature,
            'message_digest' => $messageDigest,
            'signing_time' => $signingTime,
            'certificates' => $certificates,
        ];
    }

    /**
     * The DER elements in a byte string, in order.
     *
     * @return array<int, array{tag: int, body: string, raw: string}>
     */
    private function children(string $der): array
    {
        $items = [];
        $offset = 0;
        $length = strlen($der);
        while ($offset < $length) {
            $start = $offset;
            $tag = ord($der[$offset++]);
            if ($offset >= $length) {
                break;
            }
            $size = ord($der[$offset++]);
            if ($size & 0x80) {
                $bytes = $size & 0x7F;
                if ($bytes === 0 || $bytes > 4) {
                    throw new \RuntimeException('Unsupported length.');
                }
                $size = 0;
                for ($i = 0; $i < $bytes; $i++) {
                    $size = ($size << 8) | ord($der[$offset++]);
                }
            }
            if ($offset + $size > $length) {
                throw new \RuntimeException('Truncated.');
            }
            $items[] = ['tag' => $tag, 'body' => substr($der, $offset, $size), 'raw' => substr($der, $start, $offset - $start + $size)];
            $offset += $size;
            // The /Contents value is zero-padded after the CMS.
            if ($offset < $length && trim(substr($der, $offset), "\0") === '') {
                break;
            }
        }

        return $items;
    }

    private function oid(string $body): string
    {
        $first = ord($body[0]);
        $parts = [intdiv($first, 40), $first % 40];
        $value = 0;
        for ($i = 1; $i < strlen($body); $i++) {
            $byte = ord($body[$i]);
            $value = ($value << 7) | ($byte & 0x7F);
            if (! ($byte & 0x80)) {
                $parts[] = $value;
                $value = 0;
            }
        }

        return implode('.', $parts);
    }

    /**
     * UTCTime (0x17) or GeneralizedTime (0x18) as ISO 8601 (UTC).
     *
     * @param  array{tag: int, body: string, raw: string}  $value
     */
    private function time(array $value): ?string
    {
        $text = $value['body'];
        $format = $value['tag'] === 0x17 ? 'ymdHis' : 'YmdHis';
        $date = \DateTimeImmutable::createFromFormat($format, substr($text, 0, $value['tag'] === 0x17 ? 12 : 14), new \DateTimeZone('UTC'));

        return $date ? $date->format('Y-m-d\TH:i:s\Z') : null;
    }
}
