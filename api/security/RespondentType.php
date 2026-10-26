<?php

declare(strict_types=1);

/** The public user type choices and compatibility for previously saved values. */
final class RespondentType
{
    public const PATIENT = 'Patient / Client';
    public const COMPANION = "Patient's Companion (Relative)";
    public const BUSINESS = 'Business (visited for business purposes)';
    public const OTHER = 'Other';

    public static function selectedLabel(?string $value): ?string
    {
        return match (trim((string) $value)) {
            self::PATIENT, 'Pasyente/Kliyente', 'Pasyente / Kliyente', 'Pasyente' => self::PATIENT,
            self::COMPANION, 'Kauban sa Pasyente', 'Tagtungod sa Pasyente (Kaliwat)', 'Kasama ng Pasyente' => self::COMPANION,
            self::BUSINESS, 'Bisita' => self::BUSINESS,
            self::OTHER, 'other', 'others', 'Uban Pa', 'uban pa' => self::OTHER,
            default => null,
        };
    }

    public static function label(?string $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        // Legacy submissions stored the free-text Other description as the type.
        return self::selectedLabel($raw) ?? self::OTHER;
    }

    public static function category(?string $value): string
    {
        return match (self::label($value)) {
            self::PATIENT => 'patient',
            self::COMPANION => 'companion',
            self::BUSINESS => 'business',
            self::OTHER => 'other',
            default => 'unspecified',
        };
    }

    public static function legacyOtherDetail(?string $value): ?string
    {
        $raw = trim((string) $value);
        return $raw !== '' && self::selectedLabel($raw) === null ? $raw : null;
    }
}
