<?php

declare(strict_types=1);

require_once __DIR__ . '/FeedbackEncryption.php';

/**
 * Shared read-side adapter for the staged plaintext-to-encrypted transition.
 * An encrypted value always wins. Legacy plaintext is used only when no
 * encrypted envelope exists; authentication failures are never downgraded.
 */
final class FeedbackDecryption
{
    public function __construct(private readonly FeedbackEncryption $encryption = new FeedbackEncryption())
    {
    }

    public function value(
        mixed $encryptedValue,
        mixed $legacyPlaintext,
        string $table,
        string $referenceId,
        string $fieldName
    ): ?string {
        if ($encryptedValue !== null) {
            if (!is_string($encryptedValue)) {
                throw new EncryptionException('The encrypted database value has an invalid type.');
            }

            return $this->encryption->decryptNullable(
                $encryptedValue,
                new EncryptionContext($table, $referenceId, $fieldName)
            );
        }

        if ($legacyPlaintext === null) {
            return null;
        }

        if (!is_scalar($legacyPlaintext)) {
            throw new EncryptionException('The legacy database value has an invalid type.');
        }

        return (string) $legacyPlaintext;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, array{0:string,1:string,2?:string}> $fields output => [encrypted alias, plaintext alias, AAD field]
     * @return array<string, mixed>
     */
    public function map(array $row, string $table, string $referenceId, array $fields): array
    {
        foreach ($fields as $output => $definition) {
            [$encryptedAlias, $plaintextAlias] = $definition;
            $fieldName = $definition[2] ?? $output;
            $row[$output] = $this->value(
                $row[$encryptedAlias] ?? null,
                $row[$plaintextAlias] ?? null,
                $table,
                $referenceId,
                $fieldName
            );
            unset($row[$encryptedAlias]);
            if ($plaintextAlias !== $output) {
                unset($row[$plaintextAlias]);
            }
        }

        return $row;
    }
}
