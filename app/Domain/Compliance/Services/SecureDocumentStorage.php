<?php

declare(strict_types=1);

namespace App\Domain\Compliance\Services;

use App\Domain\Compliance\Models\KycDocument;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Secure storage + authorised retrieval for KYC documents.
 *
 *  - Files land on the configured KYC disk with private visibility, under
 *    `kyc/<user-uuid>/`. The disk defaults to 'private'; no 'private' /
 *    'secure' disk is defined in config/filesystems.php today, so when the
 *    configured disk is absent this falls back to the 'local' disk
 *    (storage/app, not web-accessible). Define a real private disk before
 *    storing production documents.
 *  - Only the file hash (SHA-256) is recorded, never document contents.
 *    Nothing about a document's contents is ever written to logs.
 *  - Retrieval is authorised (owner, or a user with the compliance review
 *    permission) AND requires a short-lived HMAC access token issued by
 *    issueAccessToken(). The token binds document id + expiry to the app
 *    key; it is verified without any database lookup of the file itself.
 *  - Raw biometrics are never stored: selfie / liveness evidence is held
 *    by the KYC provider, not here.
 */
final class SecureDocumentStorage
{
    public function disk(): string
    {
        $configured = (string) config('kyc.verification.document_disk', 'private');

        // The configured disk may not exist (see class docblock). Resolve
        // it — a faked disk in tests resolves fine — and fall back to
        // 'local', which is always defined and is not web-accessible.
        try {
            Storage::disk($configured);

            return $configured;
        } catch (Throwable) {
            return 'local';
        }
    }

    /**
     * Store an uploaded KYC document privately.
     *
     * @return array{path: string, disk: string, hash: string}
     */
    public function store(User $user, UploadedFile $file, string $documentType): array
    {
        $disk = $this->disk();
        $prefix = trim((string) config('kyc.verification.document_path_prefix', 'kyc'), '/');

        $path = $file->storeAs(
            "{$prefix}/{$user->uuid}",
            $documentType . '_' . now()->format('YmdHis') . '_' . uniqid() . '.' . $file->getClientOriginalExtension(),
            ['disk' => $disk, 'visibility' => 'private']
        );

        if (! is_string($path) || $path === '') {
            throw new RuntimeException('Failed to store KYC document.');
        }

        $hash = hash_file('sha256', Storage::disk($disk)->path($path));

        Log::info('KYC document stored', [
            'user_uuid'     => $user->uuid,
            'document_type' => $documentType,
            'disk'          => $disk,
            'sha256'        => $hash,
        ]);

        return ['path' => $path, 'disk' => $disk, 'hash' => $hash];
    }

    /** Whether $requester may access $document (owner, or compliance reviewer). */
    public function isAuthorized(User $requester, KycDocument $document): bool
    {
        if ($requester->uuid === $document->user_uuid) {
            return true;
        }

        return $requester->can('compliance.kyc.review');
    }

    /**
     * Issue a short-lived access token for a document. The token is
     * `expiry.documentId.hmac`; it carries no file path and no contents.
     */
    public function issueAccessToken(KycDocument $document, User $requester, int $ttlMinutes = 15): string
    {
        if (! $this->isAuthorized($requester, $document)) {
            throw new RuntimeException('Not authorized to access this KYC document.');
        }

        $expiry = now()->addMinutes($ttlMinutes)->timestamp;
        $payload = $expiry . '.' . $document->getKey();
        $signature = hash_hmac('sha256', $payload, (string) config('app.key'));

        return $payload . '.' . $signature;
    }

    /** Verify an access token previously issued by issueAccessToken(). */
    public function verifyAccessToken(string $token, KycDocument $document): bool
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$expiry, $documentId, $signature] = $parts;

        if ((string) $document->getKey() !== $documentId) {
            return false;
        }

        if (! is_numeric($expiry) || (int) $expiry < now()->timestamp) {
            return false;
        }

        $expected = hash_hmac('sha256', $expiry . '.' . $documentId, (string) config('app.key'));

        return hash_equals($expected, $signature);
    }

    /**
     * Resolve the stored path for an authorised, token-bearing retrieval.
     * Returns null when unauthorised or the token is invalid/expired.
     * Callers stream the file themselves; this method never logs the path.
     */
    public function authorizedPath(User $requester, KycDocument $document, string $token): ?string
    {
        if (! $this->isAuthorized($requester, $document)) {
            return null;
        }

        if (! $this->verifyAccessToken($token, $document)) {
            return null;
        }

        return Storage::disk($this->disk())->exists($document->file_path)
            ? $document->file_path
            : null;
    }
}
