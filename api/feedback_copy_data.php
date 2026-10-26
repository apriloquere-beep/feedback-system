<?php

declare(strict_types=1);

require_once __DIR__ . '/security/FeedbackDecryption.php';
require_once __DIR__ . '/security/RespondentType.php';

/**
 * Decrypt only the sensitive feedbacks fields used by the email-copy body.
 * Encrypted aliases are removed by FeedbackDecryption::map().
 *
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function decryptFeedbackCopyRow(array $row, string $referenceId): array
{
    return (new FeedbackDecryption())->map($row, 'feedbacks', $referenceId, [
        'respondent_type' => ['respondent_type_enc', 'respondent_type'],
        'respondent_type_other' => ['respondent_type_other_enc', 'respondent_type_other'],
        'age' => ['age_enc', 'age'],
        'sex' => ['sex_enc', 'sex'],
        'gender_preference' => ['gender_preference_enc', 'gender_preference'],
        'gender_preference_other' => ['gender_preference_other_enc', 'gender_preference_other'],
        'religion' => ['religion_enc', 'religion'],
        'religion_other' => ['religion_other_enc', 'religion_other'],
        'education_level' => ['education_level_enc', 'education_level'],
        'visit_frequency' => ['visit_frequency_enc', 'visit_frequency'],
        'service_other' => ['service_other_enc', 'service_other'],
        'service_subtype_other' => ['service_subtype_other_enc', 'service_subtype_other'],
        'feedback' => ['feedback_enc', 'feedback'],
        'suggestions' => ['suggestions_enc', 'suggestions'],
        'appreciation' => ['appreciation_enc', 'appreciation'],
    ]);
}
