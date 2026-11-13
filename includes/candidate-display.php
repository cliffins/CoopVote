<?php
declare(strict_types=1);

/** One system-wide presentation setting, not a duplicated candidate attribute. */
function candidate_show_year_section(): bool
{
    return coop_setting('candidate_show_year_section', '1') === '1';
}

/** Combined only for presentation; year and section remain atomic stored values. */
function candidate_year_section_label(array $candidate): string
{
    return implode(' · ', array_filter([
        trim((string)($candidate['year'] ?? '')),
        ($candidate['section'] ?? '') !== '' ? 'Section ' . $candidate['section'] : '',
    ], static fn($value) => $value !== ''));
}
