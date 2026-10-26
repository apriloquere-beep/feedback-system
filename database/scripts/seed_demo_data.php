<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/**
 * RETIRED: legacy department-based demo seed. Do not execute against final SFMS.
 * Uses actual table structure and existing staff/departments
 */

try {
    $pdo = new PDO('mysql:host=localhost;dbname=web_system', 'root', '');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== DATABASE RESET & SAMPLE DATA ===\n\n";
    
    // 1. CLEAR FEEDBACK ENTRIES
    echo "Step 1: Clearing feedback entries...\n";
    $pdo->exec('DELETE FROM feedback_entries');
    echo "✓ All feedback entries cleared\n\n";
    
    // 2. KEEP ONLY 3 STAFF MEMBERS
    echo "Step 2: Archiving all staff except 3 core staff...\n";
    
    // Map user request to actual staff in database
    $staffToKeep = [
        1,  // Dr. Paolo Santos
        9,  // Dr. Maria Reyes
        4   // Nurse Jonel Cruz
    ];
    
    $staffNames = [
        1 => 'Dr. Paolo Santos',
        9 => 'Dr. Maria Reyes',
        4 => 'Nurse Jonel Cruz'
    ];
    
    echo "  Keeping:\n";
    foreach ($staffToKeep as $id) {
        echo "    - " . $staffNames[$id] . " (ID: $id)\n";
    }
    
    // Archive all other staff
    $idPlaceholders = implode(',', $staffToKeep);
    $pdo->exec("UPDATE staff SET is_active = 0, archived_at = NOW() WHERE id NOT IN ($idPlaceholders)");
    
    echo "\n✓ Other staff archived\n\n";
    
    // 3. INSERT SAMPLE FEEDBACKS
    echo "Step 3: Inserting 5 sample feedback entries...\n\n";
    
    // Sample data - mapped to existing departments
    $samples = [
        [
            'user_type' => 'patient',
            'feedback_type' => 'compliment',
            'department_id' => 1,  // Outpatient / General Consultation
            'department' => 'Outpatient / General Consultation',
            'service_id' => null,
            'service' => 'Consultation',
            'visit_date' => '2026-04-20',
            'staff_id' => 1,  // Dr. Paolo Santos
            'staff_name' => 'Dr. Paolo Santos',
            'subject' => 'Excellent Doctor Consultation',
            'feedback' => 'Very smooth consultation, doctor was very clear.',
            'suggestions' => 'Improve waiting time',
            'service_rating' => 5,
            'staff_rating' => 5,
            'facility_rating' => 4,
            'is_anonymous' => 0
        ],
        [
            'user_type' => 'patient',
            'feedback_type' => 'compliment',
            'department_id' => 2,  // Maternal & Child Health (MCH)
            'department' => 'Maternal & Child Health (MCH)',
            'service_id' => null,
            'service' => 'Check-up',
            'visit_date' => '2026-04-18',
            'staff_id' => null,
            'staff_name' => 'N/A',
            'subject' => 'Friendly and Helpful Staff',
            'feedback' => 'Staff were very friendly and helpful.',
            'suggestions' => 'Add more kids toys in waiting area',
            'service_rating' => 4,
            'staff_rating' => 5,
            'facility_rating' => 5,
            'is_anonymous' => 1
        ],
        [
            'user_type' => 'patient',
            'feedback_type' => 'complaint',
            'department_id' => 13,  // Emergency / First Aid
            'department' => 'Emergency / First Aid',
            'service_id' => null,
            'service' => 'ER Service',
            'visit_date' => '2026-04-15',
            'staff_id' => 4,  // Nurse Jonel Cruz
            'staff_name' => 'Nurse Jonel Cruz',
            'subject' => 'Long ER Waiting Time',
            'feedback' => 'Service was okay but waiting was long.',
            'suggestions' => 'Faster ER response needed',
            'service_rating' => 3,
            'staff_rating' => 4,
            'facility_rating' => 3,
            'is_anonymous' => 0
        ],
        [
            'user_type' => 'patient',
            'feedback_type' => 'compliment',
            'department_id' => 6,  // Laboratory Services
            'department' => 'Laboratory Services',
            'service_id' => null,
            'service' => 'Blood Test',
            'visit_date' => '2026-04-10',
            'staff_id' => null,
            'staff_name' => 'N/A',
            'subject' => 'Professional Lab Service',
            'feedback' => 'Very fast and professional service.',
            'suggestions' => 'Keep up the good work',
            'service_rating' => 5,
            'staff_rating' => 5,
            'facility_rating' => 5,
            'is_anonymous' => 1
        ],
        [
            'user_type' => 'patient',
            'feedback_type' => 'compliment',
            'department_id' => 1,  // Outpatient / General Consultation
            'department' => 'Outpatient / General Consultation',
            'service_id' => null,
            'service' => 'Follow-up Visit',
            'visit_date' => '2026-04-08',
            'staff_id' => 9,  // Dr. Maria Reyes
            'staff_name' => 'Dr. Maria Reyes',
            'subject' => 'Clean Facility and Good Service',
            'feedback' => 'Good service overall, clean facility.',
            'suggestions' => 'Improve signage in hallways',
            'service_rating' => 4,
            'staff_rating' => 4,
            'facility_rating' => 4,
            'is_anonymous' => 0
        ]
    ];
    
    // Build ratings JSON
    $insertStmt = $pdo->prepare('
        INSERT INTO feedback_entries 
        (reference_id, user_type, is_anonymous, feedback_type, department_id, service_id, staff_id, 
         department, service, visit_date, staff_name, subject, feedback, suggestions, ratings, status, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ');
    
    $inserted = 0;
    foreach ($samples as $idx => $sample) {
        // Generate reference ID
        $refId = 'FB-' . strtoupper(bin2hex(random_bytes(3))) . date('Ymd');
        
        // Build ratings as JSON
        $ratings = json_encode([
            'service' => $sample['service_rating'],
            'staff' => $sample['staff_rating'],
            'facility' => $sample['facility_rating']
        ]);
        
        try {
            $insertStmt->execute([
                $refId,
                $sample['user_type'],
                $sample['is_anonymous'],
                $sample['feedback_type'],
                $sample['department_id'],
                $sample['service_id'],
                $sample['staff_id'],
                $sample['department'],
                $sample['service'],
                $sample['visit_date'],
                $sample['staff_name'],
                $sample['subject'],
                $sample['feedback'],
                $sample['suggestions'],
                $ratings,
                'completed'
            ]);
            
            $feedback_id = $pdo->lastInsertId();
            $staff_info = $sample['staff_name'] !== 'N/A' ? $sample['staff_name'] : 'Anonymous';
            echo "  ✓ Feedback #" . ($idx + 1) . ": " . $sample['department'] . " | " . $staff_info . " (ID: $feedback_id)\n";
            $inserted++;
        } catch (Exception $e) {
            echo "  ✗ Failed: " . $e->getMessage() . "\n";
        }
    }
    
    echo "\n✓ Successfully inserted $inserted sample feedbacks\n\n";
    
    // 4. VERIFICATION
    echo "Step 4: Verification...\n";
    
    $stmt = $pdo->query('SELECT COUNT(*) as count FROM feedback_entries');
    $feedbackCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "  Total feedback entries: $feedbackCount\n";
    
    $stmt = $pdo->query('SELECT COUNT(*) as count FROM staff WHERE is_active = 1');
    $activeStaffCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "  Active staff members: $activeStaffCount\n";
    
    $stmt = $pdo->query('SELECT COUNT(*) as count FROM staff WHERE is_active = 0');
    $archivedStaffCount = $stmt->fetch(PDO::FETCH_ASSOC)['count'];
    echo "  Archived staff members: $archivedStaffCount\n";
    
    echo "\n✓ Current feedback entries:\n";
    $stmt = $pdo->query('
        SELECT 
            id,
            reference_id,
            department,
            service,
            staff_name,
            visit_date,
            subject,
            is_anonymous,
            ratings
        FROM feedback_entries
        ORDER BY visit_date DESC
    ');
    
    $feedbacks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($feedbacks as $idx => $fb) {
        $ratings = json_decode($fb['ratings'], true);
        $anonymous = $fb['is_anonymous'] ? '(Anonymous)' : '(' . $fb['staff_name'] . ')';
        echo "  " . ($idx + 1) . ". [" . $fb['reference_id'] . "] " . $fb['department'] . " | " . $fb['visit_date'] . " $anonymous\n";
        echo "     Ratings: Service=" . $ratings['service'] . "/5, Staff=" . $ratings['staff'] . "/5, Facility=" . $ratings['facility'] . "/5\n";
    }
    
    echo "\n✓ Active staff members:\n";
    $stmt = $pdo->query('SELECT id, full_name, department_id, role FROM staff WHERE is_active = 1 ORDER BY full_name');
    $staff = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($staff as $s) {
        echo "  - " . $s['full_name'] . " (ID: " . $s['id'] . ")\n";
    }
    
    echo "\n=== RESET COMPLETE & VERIFIED ===\n";
    echo "✓ 5 sample feedbacks inserted\n";
    echo "✓ 3 core staff retained\n";
    echo "✓ Database clean and ready\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace: " . $e->getTraceAsString() . "\n";
    die(1);
}
