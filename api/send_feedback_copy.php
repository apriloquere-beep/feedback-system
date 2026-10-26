<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/staff_role_labels.php';
require_once __DIR__ . '/feedback_copy_data.php';
require_once __DIR__ . '/sentiment.php';
require_once __DIR__ . '/security/AdminSecurity.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;

sfmsStartSecureSession();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);

$payload = readJsonBody() ?: $_POST;
$referenceId = preg_replace('/[^A-Z0-9\-]/', '', strtoupper((string) ($payload['referenceId'] ?? '')));
$email = trim((string) ($payload['email'] ?? ''));
$consent = filter_var($payload['consent'] ?? false, FILTER_VALIDATE_BOOLEAN);

if ($referenceId === '' || !preg_match('/^[A-Z0-9\-]{4,40}$/', $referenceId)) jsonResponse(['success' => false, 'message' => 'Invalid feedback reference.'], 422);
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) jsonResponse(['success' => false, 'message' => 'Please enter a valid email address.'], 422);
if (!$consent) jsonResponse(['success' => false, 'message' => 'Please check the consent box before sending.'], 422);

// feedback_submit.php places the just-created reference in this session. This
// prevents the public endpoint from being used to email arbitrary records.
$sessionReference = (string) ($_SESSION['feedback_copy_reference_id'] ?? '');
if ($sessionReference === '' || !hash_equals($sessionReference, $referenceId)) {
    jsonResponse(['success' => false, 'message' => 'This email-copy request has expired. Please submit feedback again before requesting a copy.'], 403);
}

const FB2_RATING_KEY_MAP = [
    'sqd4'=>'fb2_ratA_sqd4','a2'=>'fb2_ratA_a2','a3'=>'fb2_ratA_a3','a4'=>'fb2_ratA_a4','sqd3'=>'fb2_ratA_sqd3','sqd2'=>'fb2_ratA_sqd2','a7'=>'fb2_ratA_a7','sqd1'=>'fb2_ratA_sqd1',
    'b9'=>'fb2_ratB_b9','b10'=>'fb2_ratB_b10','b11'=>'fb2_ratB_b11','b12'=>'fb2_ratB_b12','b13'=>'fb2_ratB_b13','sqd8'=>'fb2_ratB_sqd8','sqd5'=>'fb2_ratB_sqd5',
    'st0'=>'fb2_staff_st0','st1'=>'fb2_staff_st1','st2'=>'fb2_staff_st2','st3'=>'fb2_staff_st3','st4'=>'fb2_staff_st4','st5'=>'fb2_staff_st5','st6'=>'fb2_staff_st6','st7'=>'fb2_staff_st7','st8'=>'fb2_staff_st8','st9'=>'fb2_staff_st9',
    'c17'=>'fb2_ratC_c17','c18'=>'fb2_ratC_c18','sqd6'=>'fb2_ratC_sqd6','sqd0'=>'fb2_ratC_sqd0',
];

function copyDisplayChoice(string $field, $value): string {
    $value = trim((string) ($value ?? ''));
    if ($value === '') return 'Not provided';
    if ($field === 'respondent') return RespondentType::label($value) ?? 'Not provided';
    $maps = [
        'sex'=>['Babaye'=>'Female','Babae'=>'Female','Lalaki'=>'Male','Lalake'=>'Male'],
        'gender'=>['Babaye'=>'Female','Babae'=>'Female','Lalaki'=>'Male','Lalake'=>'Male'],
        'religion'=>['Wala'=>'None','Walang Relihiyon'=>'None','Kristiyano'=>'Christian'],
        'education'=>['Elementarya'=>'Elementary','Highskol'=>'High School','Hayskul'=>'High School','Kolehiyo'=>'College','Bokasyonal'=>'Vocational','Walang Pormal na Edukasyon'=>'No Formal Education'],
        'frequency'=>['Wala gyud'=>'Never','Hindi pa kailanman'=>'Never','1-3x'=>'1–3x / year','4-6x'=>'4–6x / year','7-12x'=>'7–12x / year','>12x'=>'>12x / year'],
        'cc1'=>['1'=>"I know what a CC is and I saw this office/agency's CC.",'2'=>"I know what a CC is but I did NOT see this office's CC.",'3'=>"I learned of the CC only when I saw this office's CC.",'4'=>'I do not know what a CC is and I did not see one in this office.'],
        'cc2'=>['Sayon'=>'Easy to see','Murag'=>'Somewhat easy to see','Lisod'=>'Difficult to see','Dili'=>'Not visible at all'],
        'cc3'=>['Dako'=>'Helped very much','Gamay'=>'Somewhat helped','Walay'=>'Did not help at all'],
    ];
    return $maps[$field][$value] ?? $value;
}
function copyText($value): string { $value = (string) ($value ?? ''); return $value === '' ? 'Not provided' : $value; }
function copyHtml($value): string { return nl2br(htmlspecialchars(copyText($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')); }
function copySection(string $title, array $rows): string {
    $body = '';
    foreach ($rows as [$label, $value]) $body .= '<tr><th>'.htmlspecialchars($label, ENT_QUOTES, 'UTF-8').'</th><td>'.copyHtml($value).'</td></tr>';
    return '<h2>'.htmlspecialchars($title, ENT_QUOTES, 'UTF-8').'</h2><table>'.$body.'</table>';
}
function copyRatingRows(array $ratings, array $lang, bool $staff): array {
    $rows = [];
    foreach (FB2_RATING_KEY_MAP as $key => $langKey) {
        if (!array_key_exists($key, $ratings) || (bool) preg_match('/^st\d+$/', $key) !== $staff) continue;
        $raw = $ratings[$key];
        $display = is_string($raw) && strtoupper(trim($raw)) === 'N/A' ? 'N/A' : (is_numeric($raw) ? number_format((float) $raw, 1).' / 5.0' : null);
        if ($display !== null) $rows[] = [$lang[$langKey] ?? $key, $display];
    }
    return $rows;
}

try {
    $config = feedbackCopyMailConfig();
    if (!$config['configured']) jsonResponse(['success'=>false,'message'=>'Email copy service is not configured yet. Please contact the facility.'], 503);

    $pdo = getPdo();
    $stmt = $pdo->prepare('SELECT fb.reference_id,fb.is_anonymous,fb.respondent_type,fb.respondent_type_enc,fb.respondent_type_other,fb.respondent_type_other_enc,fb.age,fb.age_enc,fb.sex,fb.sex_enc,fb.gender_preference,fb.gender_preference_enc,fb.gender_preference_other,fb.gender_preference_other_enc,fb.religion,fb.religion_enc,fb.religion_other,fb.religion_other_enc,fb.education_level,fb.education_level_enc,fb.facility,fb.visit_date,fb.service,fb.service_other,fb.service_other_enc,fb.service_subtype_other,fb.service_subtype_other_enc,fb.visit_frequency,fb.visit_frequency_enc,fb.cc1,fb.cc2,fb.cc3,fb.feedback,fb.feedback_enc,fb.suggestions,fb.suggestions_enc,fb.appreciation,fb.appreciation_enc,fb.ratings_json,fb.rating,fb.sentiment_label,fb.sentiment_score,fe.status,ss.name AS subservice FROM feedbacks fb LEFT JOIN feedback_entries fe ON fe.reference_id=fb.reference_id LEFT JOIN service_subtypes ss ON ss.id=fb.service_subtype_id WHERE fb.reference_id=:reference_id LIMIT 1');
    $stmt->execute([':reference_id'=>$referenceId]);
    $row = $stmt->fetch();
    if (!is_array($row)) jsonResponse(['success'=>false,'message'=>'Feedback record not found.'], 404);
    $row = decryptFeedbackCopyRow($row, $referenceId);
    $analysisText = trim(implode(' ', array_filter([(string) ($row['feedback'] ?? ''), (string) ($row['suggestions'] ?? '')], static fn (string $text): bool => trim($text) !== '')));
    $storedSentiment = (string) ($row['sentiment_label'] ?? '');
    $row['sentiment_label'] = sentimentNormalizeLabel($storedSentiment, $analysisText);
    if (!in_array(strtolower(trim($storedSentiment)), ['positive', 'negative', 'neutral'], true)) {
        $row['sentiment_score'] = analyzeSentiment($analysisText)['score'];
    }

    $ratings = json_decode((string) ($row['ratings_json'] ?? ''), true);
    $ratings = is_array($ratings) ? $ratings : [];
    $lang = require __DIR__ . '/../lang/english.php';
    applyStaffRoleLabelOverrides($lang);
    $respondent = [
        ['Respondent Type',copyDisplayChoice('respondent',$row['respondent_type'])],['Age',copyText($row['age'])],['Sex',copyDisplayChoice('sex',$row['sex'])],['Gender Preference',copyDisplayChoice('gender',$row['gender_preference'])],['Religion',copyDisplayChoice('religion',$row['religion'])],['Education',copyDisplayChoice('education',$row['education_level'])],
    ];
    $otherDetail = $row['respondent_type_other'] ?: RespondentType::legacyOtherDetail($row['respondent_type']);
    if ($otherDetail) $respondent[] = ['Other respondent type', $otherDetail];
    if ((string)($row['gender_preference_other'] ?? '') !== '') $respondent[]=['Gender Preference (Other)',$row['gender_preference_other']];
    if ((string)($row['religion_other'] ?? '') !== '') $respondent[]=['Religion (Other)',$row['religion_other']];
    $visit = [['Facility',copyText($row['facility'])],['Visit Date',copyText($row['visit_date'])],['Service',copyText($row['service'])],['Sub-service',copyText($row['subservice'])],['Visit Frequency',copyDisplayChoice('frequency',$row['visit_frequency'])]];
    if ((string)($row['service_other'] ?? '') !== '') $visit[]=['Other Service',$row['service_other']];
    if ((string)($row['service_subtype_other'] ?? '') !== '') $visit[]=['Other Sub-service',$row['service_subtype_other']];
    $experience = copyRatingRows($ratings,$lang,false);
    $staff = copyRatingRows($ratings,$lang,true);
    $html = '<!doctype html><html><head><meta charset="UTF-8"><style>body{font-family:Arial,sans-serif;color:#1f2937;line-height:1.5}h1,h2{color:#047857}h2{font-size:16px;margin-top:24px}table{border-collapse:collapse;width:100%;max-width:760px}th,td{border:1px solid #d1d5db;padding:8px;text-align:left;vertical-align:top}th{width:32%;background:#ecfdf5}</style></head><body>';
    $html .= '<h1>SFMS — Feedback Copy</h1><p><strong>Feedback Reference:</strong> '.htmlspecialchars($referenceId,ENT_QUOTES,'UTF-8').'</p>';
    $html .= copySection('Respondent Information',$respondent).copySection('Visit / Service Information',$visit).copySection("Citizen's Charter",[['CC1',copyDisplayChoice('cc1',$row['cc1'])],['CC2',copyDisplayChoice('cc2',$row['cc2'])],['CC3',copyDisplayChoice('cc3',$row['cc3'])]]).copySection('Experience Ratings',$experience ?: [['Ratings','Not provided']]).copySection('Staff Role Ratings',$staff ?: [['Ratings','Not provided']]).copySection('Written Feedback',[['Suggestions',copyText($row['suggestions'])],['Appreciation / Recognition',copyText($row['appreciation'])]]).copySection('System Analysis',[['Average Rating',is_numeric($row['rating'])?number_format((float)$row['rating'],1).' / 5.0':'Not provided'],['Sentiment',copyText($row['sentiment_label'])],['Confidence',is_numeric($row['sentiment_score'])?number_format((float)$row['sentiment_score']*100,1).'%':'Not provided'],['Status',ucfirst(str_replace('_',' ',copyText($row['status'])))]]) . '</body></html>';

    $mail = new PHPMailer(true);
    $mail->isSMTP(); $mail->Host=$config['host']; $mail->Port=$config['port']; $mail->SMTPAuth=true; $mail->Username=$config['username']; $mail->Password=$config['password'];
    $mail->SMTPSecure=$config['encryption']==='ssl' ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->CharSet=PHPMailer::CHARSET_UTF8; $mail->setFrom($config['fromAddress'],$config['fromName']); $mail->addAddress($email); $mail->isHTML(true);
    $mail->Subject='SFMS Feedback Copy — '.$referenceId; $mail->Body=$html; $mail->AltBody=trim(html_entity_decode(strip_tags(str_replace(['</tr>','</h2>','</p>','<br>','<br/>','<br />'],"\n",$html)),ENT_QUOTES|ENT_HTML5,'UTF-8'));
    $mail->send();
    jsonResponse(['success'=>true,'message'=>'Feedback Copy Sent! A copy of your feedback has been sent to your email address.']);
} catch (Exception $e) {
    error_log('[send_feedback_copy] SMTP delivery failed for a feedback copy request.');
    jsonResponse(['success'=>false,'message'=>'Email copy could not be sent. Please verify the email address and try again later.'],502);
} catch (Throwable $e) {
    error_log('[send_feedback_copy] Unexpected delivery failure.');
    jsonResponse(['success'=>false,'message'=>'Email copy could not be sent. Please try again later.'],500);
}
