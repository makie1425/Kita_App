<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AdjustmentEvidence
{
    public static function decode(?string $value): ?array
    {
        if (! $value) {
            return null;
        }
        if (! preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/=]+)$#', $value, $matches)) {
            throw ValidationException::withMessages(['evidence' => 'Upload a PNG, JPEG or WebP image.']);
        }
        $bytes = base64_decode($matches[2], true);
        $info = $bytes === false ? false : @getimagesizefromstring($bytes);
        if (! $info || strlen($bytes) > 2 * 1024 * 1024 || ($info['mime'] ?? '') !== 'image/'.$matches[1]
            || $info[0] > 12000 || $info[1] > 12000) {
            throw ValidationException::withMessages(['evidence' => 'Use a valid image up to 2 MB and 12000 pixels per side.']);
        }

        return ['mime' => $info['mime'], 'content' => base64_encode($bytes)];
    }
}
