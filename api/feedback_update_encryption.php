<?php

declare(strict_types=1);

require_once __DIR__ . '/security/FeedbackEncryption.php';

/** @param array{subject:?string,feedback:string,suggestions:?string,appreciation:?string} $values */
function encryptFeedbackUpdateFields(string $table, string $referenceId, array $values): array
{
    $encryption = new FeedbackEncryption();
    $encrypted = [];
    foreach ($values as $field => $value) {
        $encrypted[$field . '_enc'] = $encryption->encryptNullable(
            $value,
            new EncryptionContext($table, $referenceId, $field)
        );
    }
    return $encrypted;
}

/** @param array{subject:?string,feedback:string,suggestions:?string,appreciation:?string} $values */
function encryptFeedbackEntryUpdateFields(string $referenceId, array $values): array
{
    return encryptFeedbackUpdateFields('feedback_entries', $referenceId, $values);
}

/** @param array{subject:?string,feedback:string,suggestions:?string,appreciation:?string} $values */
function encryptCanonicalFeedbackUpdateFields(string $referenceId, array $values): array
{
    return encryptFeedbackUpdateFields('feedbacks', $referenceId, $values);
}
