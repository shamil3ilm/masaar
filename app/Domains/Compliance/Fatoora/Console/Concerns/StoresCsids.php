<?php

declare(strict_types=1);

namespace App\Domains\Compliance\Fatoora\Console\Concerns;

use Illuminate\Support\Facades\File;

/**
 * Where fatoora:onboard keeps what each step obtains, so the next step can
 * carry on from it: storage/app/zatca/ccsid_* and pcsid_*.
 *
 * Plain files rather than the credential store, because this is the sandbox
 * and local path - ZATCA's test taxpayer, whose certificates belong to no
 * organization - and later steps run as separate invocations.
 *
 * Needs WritesSecrets alongside it.
 */
trait StoresCsids
{
    /**
     * The certificate's base64 DER, from whatever the authority sent.
     *
     * ZATCA wraps it a second time: the token decodes to the DER's own
     * base64, which is what belongs between the PEM headers. A token that is
     * already the body decodes to bytes rather than base64, so this only
     * unwraps when unwrapping produces something a PEM can hold.
     */
    private function certificateBodyFrom(string $token): string
    {
        $token = trim($token);

        if ($token === '') {
            return '';
        }

        $decoded = base64_decode($token, true);

        if ($decoded !== false && preg_match("#^[A-Za-z0-9+/=\n]+$#", $decoded) === 1) {
            return trim($decoded);
        }

        return $token;
    }

    private function saveCcsidCredentials(array $data): void
    {
        $dir = $this->secretDir();

        // Save the raw token and secret
        $this->putSecret($dir.'/ccsid_token.txt', $data['binarySecurityToken'] ?? '');
        $this->putSecret($dir.'/ccsid_secret.txt', $data['secret'] ?? '');
        // ZATCA sends requestID as a JSON number, and putSecret takes a
        // string under strict_types, so onboarding died here after the
        // authority had already issued the certificate.
        $this->putSecret($dir.'/ccsid_request_id.txt', (string) ($data['requestID'] ?? ''));

        // Handle certificate - for local mode use raw cert, for API mode convert from base64
        if (! empty($data['certificate']) && str_starts_with($data['certificate'], '-----BEGIN')) {
            // Local mode: certificate is already in PEM format
            File::put($dir.'/ccsid_certificate.pem', $data['certificate']);
        } else {
            // binarySecurityToken is base64 of the certificate's base64 DER -
            // one layer more than a PEM body. Wrapping it as it arrived put
            // base64 of base64 between the headers, which openssl_x509_read
            // refuses with "X.509 Certificate cannot be retrieved", and every
            // document signed with it failed the compliance check.
            $certBase64 = $this->certificateBodyFrom((string) ($data['binarySecurityToken'] ?? ''));

            if ($certBase64 !== '') {
                File::put($dir.'/ccsid_certificate.pem', $this->certificatePem($certBase64));
            }
        }

        // Copy private key if provided via --key option
        $keyPath = $this->option('key') ?? storage_path('app/zatca/taxpayer.key');
        if (file_exists($keyPath)) {
            $privateKey = file_get_contents($keyPath);
            $this->putSecret($dir.'/ccsid_private_key.pem', $privateKey);
            $this->line('Private key copied from: '.$keyPath);
        } else {
            $this->warn('Private key not found at: '.$keyPath);
            $this->line('You will need to provide the private key for invoice signing.');
        }

        $this->line('Credentials saved to storage/app/zatca/ccsid_*.txt');
    }

    private function savePcsidCredentials(array $data): void
    {
        $dir = $this->secretDir();
        $this->putSecret($dir.'/pcsid_token.txt', $data['binarySecurityToken'] ?? '');
        $this->putSecret($dir.'/pcsid_secret.txt', $data['secret'] ?? '');

        // A JSON number, as in the CCSID response, and putSecret takes a string
        // under strict_types.
        $this->putSecret($dir.'/pcsid_request_id.txt', (string) ($data['requestID'] ?? ''));

        // The CCSID path writes a usable certificate and this one did not, so
        // the step that finishes onboarding left nothing behind to sign with.
        $body = $this->certificateBodyFrom((string) ($data['binarySecurityToken'] ?? ''));

        if ($body !== '') {
            File::put($dir.'/pcsid_certificate.pem', $this->certificatePem($body));
        }

        $this->line('Production credentials saved to storage/app/zatca/pcsid_*.txt');
    }

    private function loadCcsidCredentials(): ?array
    {
        $dir = storage_path('app/zatca');
        $tokenPath = $dir.'/ccsid_token.txt';
        $secretPath = $dir.'/ccsid_secret.txt';
        $requestIdPath = $dir.'/ccsid_request_id.txt';
        $certPath = $dir.'/ccsid_certificate.pem';
        $keyPath = $dir.'/ccsid_private_key.pem';

        if (! file_exists($tokenPath) || ! file_exists($secretPath)) {
            return null;
        }

        return [
            'token' => trim(file_get_contents($tokenPath)),
            'secret' => trim(file_get_contents($secretPath)),
            'requestId' => file_exists($requestIdPath) ? trim(file_get_contents($requestIdPath)) : '',
            'certificate' => file_exists($certPath) ? trim(file_get_contents($certPath)) : null,
            'privateKey' => file_exists($keyPath) ? trim(file_get_contents($keyPath)) : null,
        ];
    }

    /**
     * The production credentials, with the key the request was made with.
     *
     * There was no loader for these. The PCSID was obtained, written to disk
     * and never read back, so the certificate that actually clears invoices
     * had never signed one. The private key is the CCSID's: a production
     * certificate is issued against the same key the certificate request
     * carried, and only the certificate changes.
     */
    private function loadPcsidCredentials(): ?array
    {
        $dir = storage_path('app/zatca');
        $tokenPath = $dir.'/pcsid_token.txt';
        $secretPath = $dir.'/pcsid_secret.txt';
        $certPath = $dir.'/pcsid_certificate.pem';
        $keyPath = $dir.'/ccsid_private_key.pem';

        if (! file_exists($tokenPath) || ! file_exists($secretPath)) {
            return null;
        }

        return [
            'token' => trim((string) file_get_contents($tokenPath)),
            'secret' => trim((string) file_get_contents($secretPath)),
            'certificate' => file_exists($certPath) ? trim((string) file_get_contents($certPath)) : null,
            'privateKey' => file_exists($keyPath) ? trim((string) file_get_contents($keyPath)) : null,
        ];
    }

    /**
     * A PEM certificate around a base64 body, in 64-character lines.
     */
    private function certificatePem(string $body): string
    {
        return "-----BEGIN CERTIFICATE-----\n"
            .chunk_split($body, 64, "\n")
            .'-----END CERTIFICATE-----';
    }
}
