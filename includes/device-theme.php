<?php
declare(strict_types=1);

/** A presentation preference only; never used for authentication or authorization. */
function device_theme(): string
{
    $theme = $_COOKIE['cv_theme_device'] ?? '';
    return in_array($theme, ['navy', 'light', 'forest', 'ocean', 'plum', 'slate'], true)
        ? $theme : 'navy';
}
