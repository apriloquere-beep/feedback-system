<?php

declare(strict_types=1);

require_once __DIR__ . '/RespondentType.php';

/** Post-decryption filtering for authenticated feedback read endpoints. */
final class FeedbackAnalytics
{
    private const SENTIMENTS = ['positive' => 'Positive', 'negative' => 'Negative', 'neutral' => 'Neutral'];

    public static function filter(array $entries, array $filters): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $searchScope = strtolower(trim((string) ($filters['searchScope'] ?? '')));
        $status = trim((string) ($filters['status'] ?? ''));
        $sentimentInput = strtolower(trim((string) ($filters['sentiment'] ?? '')));
        if ($sentimentInput !== '' && !isset(self::SENTIMENTS[$sentimentInput])) {
            return [];
        }
        $sentiment = self::SENTIMENTS[$sentimentInput] ?? '';
        $priority = trim((string) ($filters['priority'] ?? ''));
        $feedbackType = trim((string) ($filters['feedbackType'] ?? ''));
        $service = trim((string) ($filters['service'] ?? ''));
        $respondent = strtolower(trim((string) ($filters['respondent'] ?? '')));
        $minRating = isset($filters['minRating']) && is_numeric($filters['minRating']) ? (float) $filters['minRating'] : null;
        $maxRating = isset($filters['maxRating']) && is_numeric($filters['maxRating']) ? (float) $filters['maxRating'] : null;
        $dateFrom = self::validDate((string) ($filters['dateFrom'] ?? ''));
        $dateTo = self::validDate((string) ($filters['dateTo'] ?? ''));

        return array_values(array_filter($entries, static function (array $entry) use ($search, $searchScope, $status, $sentiment, $priority, $feedbackType, $service, $respondent, $minRating, $maxRating, $dateFrom, $dateTo): bool {
            if ($search !== '') {
                if ($searchScope === 'comments') {
                    $commentFields = [
                        $entry['feedback'] ?? '', $entry['suggestions'] ?? '', $entry['appreciation'] ?? '',
                    ];
                    if (!array_filter($commentFields, static fn ($value): bool => trim((string) $value) !== '')) {
                        return false;
                    }
                    $searchableFields = $commentFields;
                } else {
                    $searchableFields = [
                        $entry['referenceId'] ?? '', $entry['facility'] ?? '',
                        $entry['service'] ?? '', $entry['serviceSubtype'] ?? '',
                        $entry['serviceOther'] ?? '', $entry['serviceSubtypeOther'] ?? '',
                        $entry['feedbackServiceOther'] ?? '', $entry['feedbackServiceSubtypeOther'] ?? '',
                        $entry['name'] ?? $entry['fullName'] ?? '', $entry['email'] ?? '',
                        $entry['subject'] ?? '', $entry['feedback'] ?? '',
                        $entry['suggestions'] ?? '', $entry['appreciation'] ?? '',
                        $entry['sentimentLabel'] ?? '',
                    ];
                }
                $haystack = implode(' ', array_map('strval', $searchableFields));
                if (stripos($haystack, $search) === false) return false;
            }
            if ($status !== '' && normalizeStatus((string) ($entry['status'] ?? 'pending')) !== normalizeStatus($status)) return false;
            if ($sentiment !== '' && (string) ($entry['sentimentLabel'] ?? '') !== $sentiment) return false;
            if ($priority !== '' && strcasecmp((string) ($entry['priority'] ?? 'Normal'), $priority) !== 0) return false;
            if ($feedbackType !== '' && strcasecmp((string) ($entry['feedbackType'] ?? ''), $feedbackType) !== 0) return false;
            if ($service !== '' && (string) ($entry['serviceId'] ?? '') !== $service) return false;
            if ($respondent !== '' && self::respondentCategory((string) ($entry['respondentType'] ?? '')) !== $respondent) return false;
            $entryDate = substr((string) ($entry['timestamp'] ?? ''), 0, 10);
            if ($dateFrom !== null && ($entryDate === '' || $entryDate < $dateFrom)) return false;
            if ($dateTo !== null && ($entryDate === '' || $entryDate > $dateTo)) return false;

            $rating = (float) ($entry['averageRating'] ?? 0.0);
            if ($minRating !== null && $rating < $minRating) return false;
            if ($maxRating !== null && $rating > $maxRating) return false;
            return true;
        }));
    }

    private static function validDate(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        [$year, $month, $day] = array_map('intval', explode('-', $value));
        return checkdate($month, $day, $year) ? $value : null;
    }

    private static function respondentCategory(string $respondentType): string
    {
        return RespondentType::category($respondentType);
    }
}
