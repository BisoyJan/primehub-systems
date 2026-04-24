<?php

namespace App\Traits;

trait AddsQrCodeBorder
{
    /**
     * Add a solid black border around the edge of a generated QR code image.
     *
     * @param  string  $imageData  Raw image bytes from the Endroid QR code result.
     * @param  string  $format  Either 'png' or 'svg'.
     * @param  int  $thickness  Border thickness in pixels (PNG) or user units (SVG).
     */
    protected function addQrCodeBorder(string $imageData, string $format, int $thickness = 2): string
    {
        if ($format === 'svg') {
            return $this->addSvgBorder($imageData, $thickness);
        }

        return $this->addPngBorder($imageData, $thickness);
    }

    private function addPngBorder(string $imageData, int $thickness): string
    {
        if (! function_exists('imagecreatefromstring')) {
            return $imageData;
        }

        $image = @imagecreatefromstring($imageData);
        if ($image === false) {
            return $imageData;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $black = imagecolorallocate($image, 0, 0, 0);

        for ($i = 0; $i < $thickness; $i++) {
            imagerectangle($image, $i, $i, $width - 1 - $i, $height - 1 - $i, $black);
        }

        ob_start();
        imagepng($image);
        $bordered = ob_get_clean();
        imagedestroy($image);

        return $bordered !== false ? $bordered : $imageData;
    }

    private function addSvgBorder(string $imageData, int $thickness): string
    {
        if (! preg_match('/<svg\b[^>]*>/i', $imageData, $match, PREG_OFFSET_CAPTURE)) {
            return $imageData;
        }

        $svgTag = $match[0][0];
        $insertPos = $match[0][1] + strlen($svgTag);

        $width = null;
        $height = null;
        if (preg_match('/\bwidth\s*=\s*"([\d.]+)/i', $svgTag, $w)) {
            $width = (float) $w[1];
        }
        if (preg_match('/\bheight\s*=\s*"([\d.]+)/i', $svgTag, $h)) {
            $height = (float) $h[1];
        }
        if (($width === null || $height === null) && preg_match('/\bviewBox\s*=\s*"([^"]+)"/i', $svgTag, $vb)) {
            $parts = preg_split('/[\s,]+/', trim($vb[1]));
            if (count($parts) === 4) {
                $width = $width ?? (float) $parts[2];
                $height = $height ?? (float) $parts[3];
            }
        }

        if ($width === null || $height === null) {
            return $imageData;
        }

        $half = $thickness / 2;
        $rect = sprintf(
            '<rect x="%s" y="%s" width="%s" height="%s" fill="none" stroke="#000000" stroke-width="%d"/>',
            $half,
            $half,
            $width - $thickness,
            $height - $thickness,
            $thickness
        );

        return substr($imageData, 0, $insertPos).$rect.substr($imageData, $insertPos);
    }
}
