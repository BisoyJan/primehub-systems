<?php

namespace App\Utils;

class StationNumberUtil
{
    /**
     * Regex pattern to match station numbers with optional prefix, number, letter, and suffix
     * Examples: "PC-1A", "ST-001", "WS-10B-FLOOR2"
     * Groups: (prefix)(number)(letter)(suffix)
     */
    public const REGEX_PATTERN = '/^(.*?)(\d+)([A-Za-z]?)(.*)$/';
}
