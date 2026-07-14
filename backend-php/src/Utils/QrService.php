<?php
declare(strict_types=1);

namespace App\Utils;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * QrService
 * Renders a signed token into a scannable QR PNG. The token itself is produced
 * by Token::signQr() — the image carries no plaintext, only the signed JWT.
 *
 * Backed by the installed chillerlan/php-qrcode package (see composer.json).
 */
final class QrService
{
    private static function options(bool $base64): QROptions
    {
        return new QROptions([
            'outputType'    => QROutputInterface::GDIMAGE_PNG,
            'eccLevel'      => EccLevel::H,
            'scale'         => 8,
            'quietzoneSize' => 3,
            'outputBase64'  => $base64,
        ]);
    }

    /** Return a base64 data-URI PNG for embedding in HTML / PDF templates. */
    public static function dataUri(string $token): string
    {
        return (new QRCode(self::options(true)))->render($token);
    }

    /** Persist the QR PNG to disk and return the file path. */
    public static function toFile(string $token, string $path): string
    {
        (new QRCode(self::options(false)))->render($token, $path);
        return $path;
    }
}
