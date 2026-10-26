<?php

declare(strict_types=1);

/**
 * The ONE canonical language-normalization function for the entire project.
 * Every place that needs to turn a raw language string (from a URL param,
 * a session value, an AJAX request, etc.) into one of the three supported
 * languages calls this — so 'en'/'bis'/'ceb'/'tl' and their canonical forms
 * can never drift out of sync between files.
 *
 * Always returns exactly one of: 'english', 'bisaya', 'tagalog'.
 */
function normalize_selected_language(string $value): string
{
    $value = strtolower(trim($value));

    $map = [
        'english' => 'english', 'en' => 'english',
        'bisaya' => 'bisaya', 'bis' => 'bisaya', 'ceb' => 'bisaya',
        'tagalog' => 'tagalog', 'tl' => 'tagalog',
    ];

    return $map[$value] ?? 'english';
}
