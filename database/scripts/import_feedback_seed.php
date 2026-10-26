<?php
// RETIRED: legacy department-based feedback import. Do not execute against final SFMS.

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../../api/db.php';
require_once __DIR__ . '/../../api/sentiment.php';

function average_rating_from_groups(array $groups): float
{
    $scores = [];

    foreach ($groups as $values) {
        if (!is_array($values)) {
            continue;
        }

        foreach ($values as $value) {
            if (is_numeric($value)) {
                $score = (float) $value;
                if ($score >= 0.5 && $score <= 5.0) {
                    $scores[] = $score;
                }
            }
        }
    }

    if (!$scores) {
        return 0.0;
    }

    return round(array_sum($scores) / count($scores), 1);
}

function lookup_id(PDO $pdo, string $table, string $column, string $value): ?int
{
    $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE {$column} = :value LIMIT 1");
    $stmt->execute([':value' => $value]);
    $id = $stmt->fetchColumn();

    return $id !== false ? (int) $id : null;
}

function lookup_department_service_ids(PDO $pdo, string $department, string $service): array
{
    $departmentId = lookup_id($pdo, 'departments', 'name', $department);
    $serviceId = null;

    if ($departmentId !== null) {
        $stmt = $pdo->prepare(
            'SELECT s.id
             FROM services s
             INNER JOIN departments d ON d.id = s.department_id
             WHERE d.id = :department_id AND s.name = :service
             LIMIT 1'
        );
        $stmt->execute([
            ':department_id' => $departmentId,
            ':service' => $service,
        ]);
        $serviceResult = $stmt->fetchColumn();
        if ($serviceResult !== false) {
            $serviceId = (int) $serviceResult;
        }
    }

    return [$departmentId, $serviceId];
}

function table_columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->query("SHOW COLUMNS FROM {$table}");
    return array_map(static fn(array $row): string => $row['Field'], $stmt->fetchAll());
}

function insert_row(PDO $pdo, string $table, array $row): void
{
    $columns = table_columns($pdo, $table);
    $filtered = array_intersect_key($row, array_flip($columns));

    if (!isset($filtered['reference_id'])) {
        throw new RuntimeException("{$table} row is missing reference_id");
    }

    $pdo->prepare("DELETE FROM {$table} WHERE reference_id = :reference_id")
        ->execute([':reference_id' => $filtered['reference_id']]);

    $columnNames = array_keys($filtered);
    $placeholders = array_map(static fn(string $column): string => ':' . $column, $columnNames);
    $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', $columnNames) . ') VALUES (' . implode(', ', $placeholders) . ')';

    $params = [];
    foreach ($filtered as $column => $value) {
        $params[':' . $column] = $value;
    }

    $pdo->prepare($sql)->execute($params);
}

$records = [
    [
        'reference_id' => 'FB-20260504-001',
        'isAnonymous' => true,
        'department' => 'Outpatient',
        'service' => 'General check-up',
        'visitDate' => '2026-05-01',
        'staffName' => 'Dr. Santos',
        'ratings' => [
            'service' => [4, 3, 4, 4, 5],
            'staff' => [5, 4, 4, 5, 5],
            'facility' => [4, 4, 5, 4, 4],
        ],
        'feedbackText' => 'The consultation was smooth and the doctor explained my condition clearly. Waiting time was a bit long but acceptable.',
        'suggestions' => 'Improve queue system to reduce waiting time.',
    ],
    [
        'reference_id' => 'FB-20260504-002',
        'isAnonymous' => false,
        'firstName' => 'Maria',
        'lastName' => 'Lopez',
        'email' => 'maria.lopez@gmail.com',
        'phone' => '09171234567',
        'department' => 'Immunization',
        'service' => 'Child vaccination',
        'visitDate' => '2026-05-02',
        'staffName' => null,
        'ratings' => [
            'service' => [5, 5, 5, 5, 5],
            'staff' => [5, 5, 5, 5, 5],
            'facility' => [5, 5, 5, 5, 5],
        ],
        'feedbackText' => 'Very organized and fast service. Staff were friendly and handled my child with care.',
        'suggestions' => 'None, everything was excellent.',
    ],
    [
        'reference_id' => 'FB-20260504-003',
        'isAnonymous' => true,
        'department' => 'Dental',
        'service' => 'Tooth extraction',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [3, 2, 3, 3, 4],
            'staff' => [4, 3, 3, 4, 4],
            'facility' => [3, 3, 4, 3, 3],
        ],
        'feedbackText' => 'Procedure was okay but I waited too long before being assisted.',
        'suggestions' => 'Better scheduling system.',
    ],
    [
        'reference_id' => 'FB-20260504-004',
        'isAnonymous' => true,
        'department' => 'Laboratory',
        'service' => 'Blood test',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [4, 4, 4, 4, 4],
            'staff' => [4, 4, 4, 4, 4],
            'facility' => [4, 3, 4, 4, 3],
        ],
        'feedbackText' => 'Staff were efficient but the waiting area was a bit crowded.',
        'suggestions' => 'Add more seating.',
    ],
    [
        'reference_id' => 'FB-20260504-005',
        'isAnonymous' => false,
        'firstName' => 'John',
        'lastName' => 'Cruz',
        'email' => 'john.cruz@yahoo.com',
        'phone' => '09181234567',
        'department' => 'Pharmacy',
        'service' => 'Medicine dispensing',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [5, 4, 5, 5, 5],
            'staff' => [5, 5, 5, 5, 5],
            'facility' => [4, 4, 5, 4, 4],
        ],
        'feedbackText' => 'The pharmacist explained the medication clearly and service was fast.',
        'suggestions' => 'Improve labeling for faster identification.',
    ],
    [
        'reference_id' => 'FB-20260504-006',
        'isAnonymous' => true,
        'department' => 'Emergency',
        'service' => 'Basic emergency treatment',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [5, 5, 4, 5, 5],
            'staff' => [5, 5, 5, 5, 5],
            'facility' => [4, 4, 5, 4, 4],
        ],
        'feedbackText' => 'Very responsive staff. I felt safe and attended quickly.',
        'suggestions' => 'None.',
    ],
    [
        'reference_id' => 'FB-20260504-007',
        'isAnonymous' => true,
        'department' => 'Nutrition',
        'service' => 'Dietary counseling',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [4, 4, 5, 4, 4],
            'staff' => [5, 5, 4, 5, 5],
            'facility' => [4, 4, 4, 4, 4],
        ],
        'feedbackText' => 'Helpful advice and friendly staff. Learned a lot about my diet.',
        'suggestions' => 'Provide printed diet plans.',
    ],
    [
        'reference_id' => 'FB-20260504-008',
        'isAnonymous' => true,
        'department' => 'TB',
        'service' => 'TB screening',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [3, 3, 3, 3, 3],
            'staff' => [4, 3, 3, 4, 4],
            'facility' => [3, 3, 3, 3, 3],
        ],
        'feedbackText' => 'Service was okay but could be faster.',
        'suggestions' => 'Improve processing time.',
    ],
    [
        'reference_id' => 'FB-20260504-009',
        'isAnonymous' => false,
        'firstName' => 'Ana',
        'lastName' => 'Reyes',
        'email' => 'ana.reyes@gmail.com',
        'phone' => '09191234567',
        'department' => 'MCH',
        'service' => 'Prenatal check-up',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [5, 4, 5, 5, 5],
            'staff' => [5, 5, 5, 5, 5],
            'facility' => [4, 4, 5, 4, 4],
        ],
        'feedbackText' => 'Staff were very caring and attentive. Felt comfortable during check-up.',
        'suggestions' => 'Shorten waiting time.',
    ],
    [
        'reference_id' => 'FB-20260504-010',
        'isAnonymous' => true,
        'department' => 'NCD',
        'service' => 'Blood pressure monitoring',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [4, 3, 4, 4, 4],
            'staff' => [4, 4, 4, 4, 4],
            'facility' => [4, 3, 4, 4, 3],
        ],
        'feedbackText' => 'Quick service but waiting area needs improvement.',
        'suggestions' => 'Better ventilation.',
    ],
    [
        'reference_id' => 'FB-20260504-011',
        'isAnonymous' => true,
        'department' => 'Family Planning',
        'service' => 'Counseling',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [5, 5, 5, 5, 5],
            'staff' => [5, 5, 5, 5, 5],
            'facility' => [5, 4, 5, 5, 4],
        ],
        'feedbackText' => 'Very informative session and staff were respectful.',
        'suggestions' => 'More private consultation space.',
    ],
    [
        'reference_id' => 'FB-20260504-012',
        'isAnonymous' => true,
        'department' => 'Communicable Disease',
        'service' => 'Rabies vaccination',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [4, 4, 4, 4, 4],
            'staff' => [4, 4, 4, 4, 4],
            'facility' => [4, 4, 4, 4, 4],
        ],
        'feedbackText' => 'Service was smooth and staff were helpful.',
        'suggestions' => 'None.',
    ],
    [
        'reference_id' => 'FB-20260504-013',
        'isAnonymous' => false,
        'firstName' => 'Mark',
        'lastName' => 'Villanueva',
        'email' => 'mark.v@gmail.com',
        'phone' => '09201234567',
        'department' => 'Environmental',
        'service' => 'Health education',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [3, 3, 4, 3, 3],
            'staff' => [4, 3, 3, 4, 4],
            'facility' => [3, 3, 3, 3, 3],
        ],
        'feedbackText' => 'Informative but could be more engaging.',
        'suggestions' => 'Use visual aids.',
    ],
    [
        'reference_id' => 'FB-20260504-014',
        'isAnonymous' => true,
        'department' => 'Adolescent Health',
        'service' => 'Counseling',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [5, 4, 5, 5, 5],
            'staff' => [5, 5, 5, 5, 5],
            'facility' => [4, 4, 5, 4, 4],
        ],
        'feedbackText' => 'Very supportive staff and comfortable environment.',
        'suggestions' => 'Extend counseling hours.',
    ],
    [
        'reference_id' => 'FB-20260504-015',
        'isAnonymous' => true,
        'department' => 'Outpatient',
        'service' => 'Follow-up consultation',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [4, 3, 4, 4, 4],
            'staff' => [4, 4, 4, 4, 4],
            'facility' => [4, 3, 4, 4, 3],
        ],
        'feedbackText' => 'Doctor was helpful but waiting time was long.',
        'suggestions' => 'Improve scheduling system.',
    ],
    [
        'reference_id' => 'FB-20260504-016',
        'source' => 'visitor',
        'isAnonymous' => true,
        'department' => 'Reception',
        'service' => 'Visitor registration',
        'visitDate' => '2026-05-03',
        'staffName' => null,
        'ratings' => [
            'service' => [4, 4, 4, 5, 4],
            'staff' => [5, 5, 4, 5, 5],
            'facility' => [4, 4, 4, 4, 4],
        ],
        'feedbackText' => 'Registration was quick and the front desk staff were very accommodating.',
        'suggestions' => 'Add clearer visitor signboards.',
    ],
    [
        'reference_id' => 'FB-20260504-017',
        'source' => 'visitor',
        'isAnonymous' => false,
        'firstName' => 'Elena',
        'lastName' => 'Santos',
        'email' => 'elena.santos@example.com',
        'phone' => '09170001111',
        'department' => 'Emergency',
        'service' => 'Patient companion assistance',
        'visitDate' => '2026-05-03',
        'staffName' => null,
        'ratings' => [
            'service' => [5, 5, 4, 5, 5],
            'staff' => [5, 5, 5, 5, 5],
            'facility' => [4, 4, 4, 4, 4],
        ],
        'feedbackText' => 'Visitors were guided properly and the staff communicated well.',
        'suggestions' => 'Keep the visitor guidance desk open longer.',
    ],
    [
        'reference_id' => 'FB-20260504-018',
        'source' => 'visitor',
        'isAnonymous' => true,
        'department' => 'Pharmacy',
        'service' => 'Prescription inquiry',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [4, 3, 4, 4, 4],
            'staff' => [4, 4, 4, 4, 4],
            'facility' => [4, 3, 4, 4, 3],
        ],
        'feedbackText' => 'The process was fine but the queue moved slowly.',
        'suggestions' => 'Separate visitor and patient lines.',
    ],
    [
        'reference_id' => 'FB-20260504-019',
        'source' => 'visitor',
        'isAnonymous' => true,
        'department' => 'Laboratory',
        'service' => 'Waiting area support',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [4, 4, 4, 4, 4],
            'staff' => [4, 4, 5, 4, 4],
            'facility' => [3, 3, 4, 3, 3],
        ],
        'feedbackText' => 'Waiting area was manageable but could be more comfortable for visitors.',
        'suggestions' => 'Add more seating for companions.',
    ],
    [
        'reference_id' => 'FB-20260504-020',
        'source' => 'visitor',
        'isAnonymous' => false,
        'firstName' => 'Marco',
        'lastName' => 'Diaz',
        'email' => 'marco.diaz@example.com',
        'phone' => '09170002222',
        'department' => 'Outpatient',
        'service' => 'Directional assistance',
        'visitDate' => '2026-05-04',
        'staffName' => null,
        'ratings' => [
            'service' => [5, 4, 5, 5, 5],
            'staff' => [5, 5, 5, 5, 5],
            'facility' => [4, 4, 4, 4, 4],
        ],
        'feedbackText' => 'Staff pointed us to the right area quickly and politely.',
        'suggestions' => 'Maintain the current visitor assistance desk.',
    ],
    [
        'reference_id' => 'FB-20260504-021',
        'source' => 'visitor',
        'isAnonymous' => true,
        'department' => 'Adolescent Health',
        'service' => 'Family waiting support',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [4, 4, 4, 5, 4],
            'staff' => [4, 4, 4, 4, 4],
            'facility' => [4, 4, 4, 4, 4],
        ],
        'feedbackText' => 'The area was safe and the staff were respectful to visitors.',
        'suggestions' => 'Provide a small refreshments corner.',
    ],
    [
        'reference_id' => 'FB-20260504-022',
        'source' => 'visitor',
        'isAnonymous' => true,
        'department' => 'MCH',
        'service' => 'Visitor assistance',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [5, 5, 5, 5, 5],
            'staff' => [5, 5, 5, 5, 5],
            'facility' => [4, 4, 5, 4, 4],
        ],
        'feedbackText' => 'Very helpful for visitors coming with children.',
        'suggestions' => 'No suggestions, excellent service.',
    ],
    [
        'reference_id' => 'FB-20260504-023',
        'source' => 'visitor',
        'isAnonymous' => false,
        'firstName' => 'Luz',
        'lastName' => 'Garcia',
        'email' => 'luz.garcia@example.com',
        'phone' => '09170003333',
        'department' => 'TB',
        'service' => 'Companion screening guidance',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [4, 3, 4, 4, 4],
            'staff' => [4, 4, 4, 4, 4],
            'facility' => [3, 3, 3, 3, 3],
        ],
        'feedbackText' => 'Guidance was clear but the process felt slow for visitors.',
        'suggestions' => 'Speed up the screening instructions.',
    ],
    [
        'reference_id' => 'FB-20260504-024',
        'source' => 'visitor',
        'isAnonymous' => true,
        'department' => 'Nutrition',
        'service' => 'Family consultation waiting',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [5, 4, 4, 5, 4],
            'staff' => [5, 4, 5, 5, 5],
            'facility' => [4, 4, 4, 4, 4],
        ],
        'feedbackText' => 'Visitors were treated kindly while waiting for the consultation.',
        'suggestions' => 'Post more visible direction arrows.',
    ],
    [
        'reference_id' => 'FB-20260504-025',
        'source' => 'visitor',
        'isAnonymous' => true,
        'department' => 'Environmental',
        'service' => 'Visitor information desk',
        'visitDate' => null,
        'staffName' => null,
        'ratings' => [
            'service' => [4, 4, 4, 4, 4],
            'staff' => [4, 4, 4, 4, 4],
            'facility' => [4, 4, 4, 4, 4],
        ],
        'feedbackText' => 'Information desk was useful and staff answered questions clearly.',
        'suggestions' => 'Keep the desk visible near the entrance.',
    ],
];

try {
    $pdo = getPdo();
    $pdo->beginTransaction();

    $inserted = 0;

    foreach ($records as $record) {
        $ratings = $record['ratings'];
        $averageRating = average_rating_from_groups($ratings);
        $analysisText = trim(implode(' ', array_filter([
            (string) ($record['feedbackText'] ?? ''),
            (string) ($record['suggestions'] ?? ''),
        ], static fn (string $text): bool => trim($text) !== '')));
        $sentiment = analyzeSentiment($analysisText);

        $feedbackType = 'inquiry';
        if ($sentiment['label'] === 'Negative' || $averageRating <= 3.5) {
            $feedbackType = 'complaint';
        } elseif ($sentiment['label'] === 'Positive' || $averageRating >= 4.0) {
            $feedbackType = 'compliment';
        } elseif (trim((string) ($record['suggestions'] ?? '')) !== '') {
            $feedbackType = 'suggestion';
        }

        [$departmentId, $serviceId] = lookup_department_service_ids($pdo, $record['department'], $record['service']);
        $staffId = !empty($record['staffName']) ? lookup_id($pdo, 'staff', 'full_name', (string) $record['staffName']) : null;
        $source = $record['source'] ?? 'patient';

        $fullName = null;
        if (!empty($record['firstName']) || !empty($record['lastName'])) {
            $fullName = trim(($record['firstName'] ?? '') . ' ' . ($record['lastName'] ?? ''));
        }
        if ($fullName === '') {
            $fullName = null;
        }

        $email = $record['email'] ?? null;
        $phone = $record['phone'] ?? null;
        if (($record['isAnonymous'] ?? false) === true) {
            $fullName = null;
            $email = null;
            $phone = null;
        }

        $common = [
            'reference_id' => $record['reference_id'],
            'user_type' => $source,
            'is_anonymous' => !empty($record['isAnonymous']) ? 1 : 0,
            'feedback_type' => $feedbackType,
            'department_id' => $departmentId,
            'service_id' => $serviceId,
            'department' => $record['department'],
            'service' => $record['service'],
            'visit_date' => $record['visitDate'] ?: null,
            'staff_name' => $record['staffName'] ?: null,
            'subject' => $record['service'],
            'feedback' => $record['feedbackText'],
            'suggestions' => $record['suggestions'],
            'sentiment_label' => $sentiment['label'] ?? 'Neutral',
            'sentiment_score' => $sentiment['score'] ?? 0.0,
            'priority' => computePriority($sentiment['label'] ?? 'Neutral', $averageRating),
            'status' => 'pending',
        ];

        $feedbacksRow = $common + [
            'source' => $source,
            'user_type' => $source,
            'full_name' => $fullName,
            'email' => $email,
            'contact_number' => $phone,
            'is_custom_service' => 0,
            'rating' => $averageRating,
            'ratings_json' => json_encode($ratings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        $feedbackEntriesRow = $common + [
            'staff_id' => $staffId,
            'is_custom_service' => 0,
            'ratings' => json_encode($ratings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        insert_row($pdo, 'feedbacks', $feedbacksRow);
        insert_row($pdo, 'feedback_entries', $feedbackEntriesRow);
        $inserted++;
    }

    $pdo->commit();

    echo "Inserted {$inserted} feedback records into feedbacks and feedback_entries.\n";
    echo "Reference IDs: FB-20260504-001 to FB-20260504-025\n";
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Import failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
