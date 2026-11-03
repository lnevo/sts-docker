<?php
// Shared HTML helpers (include-only, no output). The one escape helper for page output.

if (!function_exists('sts_h')) {
    function sts_h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}
