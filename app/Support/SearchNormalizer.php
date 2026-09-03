<?php

namespace App\Support;

class SearchNormalizer
{
    /**
     * Diacritic-to-ASCII fold map for name search (Ñ, á, é, etc.).
     *
     * @var array<string, string>
     */
    private const CHAR_MAP = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'Á' => 'a', 'À' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Ã' => 'a', 'Å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'É' => 'e', 'È' => 'e', 'Ê' => 'e', 'Ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'Í' => 'i', 'Ì' => 'i', 'Î' => 'i', 'Ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
        'Ó' => 'o', 'Ò' => 'o', 'Ô' => 'o', 'Ö' => 'o', 'Õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'Ú' => 'u', 'Ù' => 'u', 'Û' => 'u', 'Ü' => 'u',
        'ñ' => 'n', 'Ñ' => 'n',
        'ç' => 'c', 'Ç' => 'c',
    ];

    /**
     * Fold diacritics to ASCII, lowercase, and collapse whitespace for name matching.
     */
    public static function normalize(?string $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        $folded = strtr($value, self::CHAR_MAP);
        $lower = mb_strtolower($folded);
        $collapsed = preg_replace('/\s+/', ' ', trim($lower));

        return mb_substr($collapsed ?? '', 0, 255);
    }
}
