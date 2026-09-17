<?php
/** Pure field rules shared by admin and voter handlers. */
declare(strict_types=1);

/** Normalize text fields without changing meaningful spaces inside a value. */
function trim_form_text(string $value): string
{
    return preg_replace('/^[\s\p{Z}\x{FEFF}\x{200B}]+|[\s\p{Z}\x{FEFF}\x{200B}]+$/u', '', $value) ?? trim($value);
}

function blank_form_value(string $value): bool
{
    return trim_form_text($value) === '';
}

/** Match the profile strength meter; bcrypt accepts at most 72 UTF-8 bytes. */
function changed_password_error(string $password): ?string
{
    if (mb_strlen($password, 'UTF-8') < 8) return 'Use at least 8 characters for your new password.';
    if (strlen($password) > 72) return 'Your new password is too long. Use at most 72 UTF-8 bytes.';
    $types = 0;
    foreach (['/\p{Lu}/u', '/\p{Ll}/u', '/\p{N}/u', '/[\p{P}\p{S}]/u'] as $pattern) {
        $types += preg_match($pattern, $password) === 1 ? 1 : 0;
    }
    return $types >= 3 ? null : 'Use at least 3 types: uppercase letters, lowercase letters, numbers and symbols.';
}

function person_name_error(string $name): ?string
{
    if ($name === '') return 'A name is required.';
    if (!preg_match("/^[\p{L}\p{M} .'’\-]+$/u", $name) || !preg_match('/\p{L}/u', $name)) {
        return 'Use letters, spaces, apostrophes, periods or hyphens. Numbers are not allowed in names.';
    }
    if (mb_strlen($name, 'UTF-8') > 100) return 'Use at most 100 characters for a name.';
    return null;
}

function phone_number_error(string $phone): ?string
{
    if ($phone === '') return null;
    if (!preg_match('/^\+?[0-9 ()-]+$/D', $phone)) {
        return 'Phone numbers cannot contain letters. Use digits and an optional leading +.';
    }
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($digits) < 7 || strlen($digits) > 15 || strlen($phone) > 20) {
        return 'Enter a phone number with 7–15 digits (up to 20 characters including formatting).';
    }
    return null;
}

/**
 * Return field-keyed errors for both admin and voter forms. Requiredness belongs
 * to each route; these validators only check the format of supplied values.
 * A name's combined length must also fit the single members.full_name column.
 */
function person_fields_errors(string $first, string $last, string $phone = ''): array
{
    $errors = [];
    if ($error = person_name_error($first)) $errors['first'] = $error;
    if ($error = person_name_error($last)) $errors['last'] = $error;
    if (mb_strlen(trim($first . ' ' . $last), 'UTF-8') > 100) $errors['last'] = 'Use at most 100 characters for the full name.';
    if ($error = phone_number_error($phone)) $errors['phone'] = $error;
    return $errors;
}

function birth_date_error(string $value): ?string
{
    if ($value === '') return null;
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) || (int) substr($value, 0, 4) < 1000) {
        return 'Enter a valid date of birth.';
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) return 'Enter a valid date of birth.';
    if ($date > new DateTimeImmutable('today')) return 'Date of birth cannot be in the future.';
    return null;
}

function whole_number_error(mixed $value, int $min, int $max): ?string
{
    if ($value === null || $value === '') return null;
    if (!is_scalar($value) || is_bool($value) || !preg_match('/^[0-9]+$/D', (string) $value) || (float) $value < $min || (float) $value > $max) {
        return "Enter a whole number between {$min} and {$max}.";
    }
    return null;
}
