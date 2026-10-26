<?php
require_once __DIR__ . '/../includes/public_flow.php';
sfmsRequireFeedbackRespondent();

// $_SESSION['selected_language'] is the single source of truth for the
// active language across the whole project; see lang/resolve.php.
require __DIR__ . '/../lang/resolve.php';
require_once __DIR__ . '/../api/security/RespondentType.php';

// The respondent type can arrive pre-selected from
// modules/dashboard/user_selection.php via ?respondent=<key>. Only these
// exact existing #resp_type option values are ever accepted — any other
// (or missing) value falls back to the normal unselected placeholder, so
// this can never inject an arbitrary respondent type into the form or DB.
$respondentTypeWhitelist = [
    'patient' => RespondentType::PATIENT,
    'companion' => RespondentType::COMPANION,
    'business' => RespondentType::BUSINESS,
    'other' => RespondentType::OTHER,
];
$preselectedRespondentType = $respondentTypeWhitelist[sfmsSelectedFeedbackRespondent()] ?? '';

// Canonical, role-based identifiers for the Staff Ratings survey section.
// The stable st0-st9 keys never change (every historical rating is stored
// against them), but the *labels* and which roles are currently offered
// live in the `staff` table (managed via the admin dashboard's "Manage
// Staff Roles" — see api/staff_roles.php) so respondents and the Staff
// Ratings dashboard always show the same, current, official role names —
// never named employees, and never a second hard-coded copy of this list.
$staffRoleEmojis = [
    'st0' => '🩺', 'st1' => '🦷', 'st2' => '💉', 'st3' => '👶', 'st4' => '🔍',
    'st5' => '🏃', 'st6' => '🔬', 'st7' => '💊', 'st8' => '📂', 'st9' => '🚑',
];

// Fallback only — used if the `staff` table can't be reached (so the
// survey still renders). Whenever the database is reachable, it fully
// overrides this with the current, active-only labels/order.
$officialStaffRoleLabels = [
    'st0' => 'Doctor',
    'st1' => 'Dentist',
    'st2' => 'Nurse',
    'st3' => 'Midwife',
    'st4' => 'Sanitary Inspector',
    'st5' => 'Barangay Health Aide/Worker',
    'st6' => 'Medical Technologist or Laboratory Aide',
    'st7' => 'Pharmacist / Pharmacy Assistant',
    'st8' => 'Administrative Aide/Clerk',
    'st9' => 'Ambulance Driver',
];
$facilityName = $t['fb2_facilityBadge'];

try {
    require_once __DIR__ . '/../api/db.php';
    $pdo = getPdo();
    $staffRoleRows = $pdo
        ->query('SELECT role, full_name FROM staff WHERE is_active = 1 ORDER BY LENGTH(role), role')
        ->fetchAll();
    if ($staffRoleRows) {
        $officialStaffRoleLabels = [];
        foreach ($staffRoleRows as $staffRoleRow) {
            $officialStaffRoleLabels[$staffRoleRow['role']] = $staffRoleRow['full_name'];
        }
    }
    $facilityStmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'admin_facility_name' LIMIT 1");
    $facilityStmt->execute();
    $savedFacilityName = trim((string) $facilityStmt->fetchColumn());
    if ($savedFacilityName !== '') {
        $facilityName = $savedFacilityName;
    }
} catch (Throwable $e) {
    // Database unreachable — keep the hardcoded defaults above so the form
    // still renders; nothing here is user input, so it's safe to swallow.
}
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang) ?>" data-theme="light">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($t['fb2_pageTitle']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../assets/css/feedback-form.css?v=20261022-section6-restore">
</head>
<body>
<div class="bg-mesh"></div>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>
<div class="orb orb-3"></div>
<div id="toasts"></div>

<!-- ══ SUCCESS MODAL ══ -->
<div class="modal-backdrop" id="successModal">
  <div class="modal-card">
    <div class="modal-icon">✓</div>
    <h2 class="modal-title"><?= htmlspecialchars($t['fb2_modalTitle']) ?></h2>
    <p class="modal-body"><?= htmlspecialchars($t['fb2_modalBody']) ?></p>
    <div class="modal-ref" id="refCode">PCF-000000</div>
    <p class="modal-body" style="font-size:12px;margin-bottom:0;"><?= htmlspecialchars($t['fb2_modalPrivacy']) ?></p>
    <div class="modal-actions" style="margin-top:22px;">
      <button class="btn-ghost" onclick="resetSurvey()"><?= htmlspecialchars($t['fb2_modalNewSurvey']) ?></button>
      <button class="btn-submit" style="min-width:130px;" onclick="document.getElementById('successModal').classList.remove('open')">
        <span class="lbl"><?= htmlspecialchars($t['fb2_modalClose']) ?></span>
      </button>
    </div>
  </div>
</div>

<?php
include __DIR__ . '/../includes/header.php';
?>

<!-- ══ WIZARD BAR ══ -->
<div class="wizard-bar" role="navigation" aria-label="Survey progress">
  <div class="wizard-inner">
    <a class="wizard-step active" id="ws1" href="#s1" onclick="goToSec('s1',event)">
      <div class="ws-num" aria-hidden="true">1</div><div class="ws-label"><?= htmlspecialchars($t['fb2_wizardRespondent']) ?></div>
    </a>
    <div class="ws-sep" aria-hidden="true"><div class="ws-sep-line"></div></div>
    <a class="wizard-step" id="ws2" href="#s2" onclick="goToSec('s2',event)">
      <div class="ws-num" aria-hidden="true">2</div><div class="ws-label"><?= htmlspecialchars($t['fb2_wizardVisitInfo']) ?></div>
    </a>
    <div class="ws-sep" aria-hidden="true"><div class="ws-sep-line"></div></div>
    <a class="wizard-step" id="ws3" href="#s3" onclick="goToSec('s3',event)">
      <div class="ws-num" aria-hidden="true">3</div><div class="ws-label"><?= htmlspecialchars($t['fb2_wizardCc']) ?></div>
    </a>
    <div class="ws-sep" aria-hidden="true"><div class="ws-sep-line"></div></div>
    <a class="wizard-step" id="ws4" href="#s4" onclick="goToSec('s4',event)">
      <div class="ws-num" aria-hidden="true">4</div><div class="ws-label"><?= htmlspecialchars($t['fb2_wizardRatings']) ?></div>
    </a>
    <div class="ws-sep" aria-hidden="true"><div class="ws-sep-line"></div></div>
    <a class="wizard-step" id="ws5" href="#s5" onclick="goToSec('s5',event)">
      <div class="ws-num" aria-hidden="true">5</div><div class="ws-label"><?= htmlspecialchars($t['fb2_wizardStaff']) ?></div>
    </a>
    <div class="ws-sep" aria-hidden="true"><div class="ws-sep-line"></div></div>
    <a class="wizard-step" id="ws6" href="#s6" onclick="goToSec('s6',event)">
      <div class="ws-num" aria-hidden="true">6</div><div class="ws-label"><?= htmlspecialchars($t['fb2_wizardComments']) ?></div>
    </a>
  </div>
  <div class="prog-bar-wrap" role="progressbar" aria-label="Form completion" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" id="progBarWrap">
    <div class="prog-bar-fill" id="progFill" style="width:0%"></div>
  </div>
</div>

<!-- ══ HERO ══ -->
<header class="hero">
  <div class="hero-badge" aria-label="Facility name">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" aria-hidden="true"><path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z"/></svg>
    <?= htmlspecialchars($facilityName) ?>
  </div>
  <h1 class="hero-title"><?= $t['fb2_heroTitle'] ?></h1>
  <p class="hero-sub"><?= htmlspecialchars($t['fb2_heroSub']) ?></p>
</header>

<!-- ══ FORM ══ -->
<div class="form-wrap">
<form id="mainForm" novalidate aria-label="PCF-CES Survey">

<!-- ─── SECTION 1: RESPONDENT ─── -->
<section class="sec-card sec-green" id="s1" aria-labelledby="sec1-title">
  <div class="sec-head">
    <div class="sec-icon-wrap green" aria-hidden="true">👤</div>
    <div class="sec-meta">
      <h2 class="sec-title" id="sec1-title"><?= htmlspecialchars($t['fb2_sec1Title']) ?></h2>
      <p class="sec-sub"><?= htmlspecialchars($t['fb2_sec1Sub']) ?></p>
      <span class="sec-badge badge-green"><?= htmlspecialchars(sprintf($t['fb2_secBadge'], 1)) ?></span>
    </div>
  </div>

  <div class="form-grid demographic-grid">

    <!-- Respondent Type — now a SELECT -->
    <div class="field fg-full">
      <label class="field-label" for="resp_type">
        <?= htmlspecialchars($t['fb2_respTypeLabel']) ?> <span class="req" aria-label="required">*</span>
      </label>
      <select class="fselect" id="resp_type" name="resp_type" required aria-required="true" aria-describedby="resp_type_other_wrap">
        <option value=""<?= $preselectedRespondentType === '' ? ' selected' : '' ?>><?= htmlspecialchars($t['fb2_respTypeDefault']) ?></option>
        <option value="<?= htmlspecialchars(RespondentType::PATIENT, ENT_QUOTES) ?>"<?= $preselectedRespondentType === RespondentType::PATIENT ? ' selected' : '' ?>><?= htmlspecialchars($t['fb2_respTypePatient']) ?></option>
        <option value="<?= htmlspecialchars(RespondentType::COMPANION, ENT_QUOTES) ?>"<?= $preselectedRespondentType === RespondentType::COMPANION ? ' selected' : '' ?>><?= htmlspecialchars($t['fb2_respTypeCompanion']) ?></option>
        <option value="<?= htmlspecialchars(RespondentType::BUSINESS, ENT_QUOTES) ?>"<?= $preselectedRespondentType === RespondentType::BUSINESS ? ' selected' : '' ?>><?= htmlspecialchars($t['fb2_respTypeVisitor']) ?></option>
        <option value="<?= htmlspecialchars(RespondentType::OTHER, ENT_QUOTES) ?>"<?= $preselectedRespondentType === RespondentType::OTHER ? ' selected' : '' ?>><?= htmlspecialchars($t['fb2_optOther']) ?></option>
      </select>
      <!-- Smooth reveal for "Other" -->
      <div class="other-reveal<?= $preselectedRespondentType === RespondentType::OTHER ? ' open' : '' ?>" id="resp_type_other_wrap">
        <div class="other-reveal-inner">
          <input class="finput" type="text" id="resp_type_other" name="resp_type_other" maxlength="120"
            placeholder="<?= htmlspecialchars($t['fb2_respTypeOtherPlaceholder']) ?>" aria-label="Specify respondent type">
        </div>
      </div>
    </div>

    <!-- Age -->
    <div class="field demographic-compact">
      <label class="field-label" for="edad"><?= htmlspecialchars($t['fb2_ageLabel']) ?> <span class="req" aria-label="required">*</span></label>
      <div class="finput-wrap">
        <span class="finput-icon" aria-hidden="true">🎂</span>
        <input class="finput" type="number" id="edad" name="edad"
          placeholder="<?= htmlspecialchars($t['fb2_agePlaceholder']) ?>" min="1" max="99" step="1" inputmode="numeric" pattern="\\d{1,2}" maxlength="2" required aria-required="true">
      </div>
    </div>

    <!-- Kinatawhan / Sex -->
    <div class="field demographic-compact">
      <label class="field-label" for="sex"><?= htmlspecialchars($t['fb2_sexLabel']) ?> <span class="req" aria-label="required">*</span></label>
      <select class="fselect" id="sex" name="sex" required aria-required="true">
        <option value=""><?= htmlspecialchars($t['fb2_selectDefault']) ?></option>
        <option value="Babaye"><?= htmlspecialchars($t['fb2_sexFemale']) ?></option>
        <option value="Lalaki"><?= htmlspecialchars($t['fb2_sexMale']) ?></option>
      </select>
    </div>

    <!-- Gender Preference — now a SELECT -->
    <div class="field demographic-compact demographic-gender">
      <label class="field-label" for="gender_pref">
        <?= htmlspecialchars($t['fb2_genderPrefLabel']) ?> <span class="req" aria-hidden="true">*</span>
      </label>
      <select class="fselect" id="gender_pref" name="gender_pref" aria-describedby="gender_pref_other_wrap" required aria-required="true">
        <option value=""><?= htmlspecialchars($t['fb2_selectDefault']) ?></option>
        <option value="Babaye"><?= htmlspecialchars($t['fb2_genderPrefFemale']) ?></option>
        <option value="Lalaki"><?= htmlspecialchars($t['fb2_genderPrefMale']) ?></option>
        <option value="other"><?= htmlspecialchars($t['fb2_optOther']) ?></option>
      </select>
      <div class="other-reveal" id="gender_pref_other_wrap">
        <div class="other-reveal-inner">
          <input class="finput" type="text" id="gender_pref_other" name="gender_pref_other"
            placeholder="<?= htmlspecialchars($t['fb2_genderPrefOtherPlaceholder']) ?>" aria-label="Specify gender preference">
        </div>
      </div>
    </div>

    <!-- Religion -->
    <div class="field demographic-religion">
      <label class="field-label" for="relihiyon"><?= htmlspecialchars($t['fb2_religionLabel']) ?> <span class="req" aria-hidden="true">*</span></label>
      <select class="fselect" id="relihiyon" name="relihiyon" aria-describedby="relihiyon_other_wrap" required aria-required="true">
        <option value=""><?= htmlspecialchars($t['fb2_selectDefault']) ?></option>
        <option value="Christian"><?= htmlspecialchars($t['fb2_religionChristian']) ?></option>
        <option value="Iglesia Ni Cristo"><?= htmlspecialchars($t['fb2_religionINC']) ?></option>
        <option value="Islam"><?= htmlspecialchars($t['fb2_religionIslam']) ?></option>
        <option value="Roman Catholic"><?= htmlspecialchars($t['fb2_religionCatholic']) ?></option>
        <option value="None"><?= htmlspecialchars($t['fb2_religionNone']) ?></option>
        <option value="other"><?= htmlspecialchars($t['fb2_optOther']) ?></option>
      </select>
      <div class="other-reveal" id="relihiyon_other_wrap">
        <div class="other-reveal-inner">
          <input class="finput" type="text" id="relihiyonOther" name="relihiyon_other"
            placeholder="<?= htmlspecialchars($t['fb2_religionOtherPlaceholder']) ?>" aria-label="Specify religion">
        </div>
      </div>
    </div>

    <!-- Education -->
    <div class="field demographic-compact demographic-education">
      <label class="field-label" for="educ"><?= htmlspecialchars($t['fb2_educLabel']) ?> <span class="req" aria-hidden="true">*</span></label>
      <select class="fselect" id="educ" name="educ" required aria-required="true">
        <option value=""><?= htmlspecialchars($t['fb2_educDefault']) ?></option>
        <option value="Elementarya"><?= htmlspecialchars($t['fb2_educElementary']) ?></option>
        <option value="Highskol"><?= htmlspecialchars($t['fb2_educHighschool']) ?></option>
        <option value="Kolehiyo"><?= htmlspecialchars($t['fb2_educCollege']) ?></option>
        <option value="Bokasyonal"><?= htmlspecialchars($t['fb2_educVocational']) ?></option>
        <option value="Post-graduate"><?= htmlspecialchars($t['fb2_educPostgrad']) ?></option>
        <option value="No Formal Education"><?= htmlspecialchars($t['fb2_educNone']) ?></option>
      </select>
    </div>

  </div>
</section>

<!-- ─── SECTION 2: FACILITY & VISIT ─── -->
<section class="sec-card sec-blue" id="s2" aria-labelledby="sec2-title">
  <div class="sec-head">
    <div class="sec-icon-wrap blue" aria-hidden="true">🏥</div>
    <div class="sec-meta">
      <h2 class="sec-title" id="sec2-title"><?= htmlspecialchars($t['fb2_sec2Title']) ?></h2>
      <p class="sec-sub"><?= htmlspecialchars($t['fb2_sec2Sub']) ?></p>
      <span class="sec-badge badge-blue"><?= htmlspecialchars(sprintf($t['fb2_secBadge'], 2)) ?></span>
    </div>
  </div>

  <div class="form-grid">
    <div class="field">
      <label class="field-label" for="facility"><?= htmlspecialchars($t['fb2_facilityLabel']) ?></label>
      <div class="finput-wrap">
        <span class="finput-icon" aria-hidden="true">🏥</span>
        <input class="finput" type="text" id="facility" value="<?= htmlspecialchars($facilityName) ?>" readonly aria-readonly="true" tabindex="-1">
        <span class="field-lock" aria-hidden="true">🔒</span>
      </div>
    </div>

    <div class="field">
      <label class="field-label" for="petsa"><?= htmlspecialchars($t['fb2_dateLabel']) ?> <span class="req">*</span></label>
      <div class="finput-wrap">
        <span class="finput-icon" aria-hidden="true">📅</span>
        <input class="finput" type="date" id="petsa" name="petsa" required aria-required="true">
      </div>
    </div>

    <div class="field">
      <label class="field-label" for="serbisyo"><?= htmlspecialchars($t['fb2_serviceLabel']) ?> <span class="req">*</span></label>
      <select class="fselect" id="serbisyo" name="serbisyo" required aria-required="true" aria-describedby="serbisyo_other_wrap">
        <option value=""><?= htmlspecialchars($t['fb2_serviceDefault']) ?></option>
      </select>
      <div class="field-subservice" id="serbisyo_subservice_wrap" hidden>
        <label class="field-label" for="serbisyo_subservice">Specific service</label>
        <select class="fselect" id="serbisyo_subservice" name="serbisyo_subservice">
          <option value="">— Select specific service —</option>
        </select>
        <div class="other-reveal" id="serbisyo_subservice_other_wrap" hidden>
          <div class="other-reveal-inner">
            <input class="finput" type="text" id="serbisyo_subservice_other" name="serbisyo_subservice_other" placeholder="Specify other service" aria-label="Specify other specific service">
          </div>
        </div>
      </div>
      <div class="other-reveal" id="serbisyo_other_wrap">
        <div class="other-reveal-inner">
          <input class="finput" type="text" id="serbisyo_other" name="serbisyo_other" placeholder="<?= htmlspecialchars($t['fb2_serviceOtherPlaceholder']) ?>" aria-label="Specify other service">
        </div>
      </div>
    </div>

    <div class="field">
      <label class="field-label" for="freq"><?= htmlspecialchars($t['fb2_freqLabel']) ?> <span class="req">*</span></label>
      <select class="fselect" id="freq" name="freq" required aria-required="true">
        <option value=""><?= htmlspecialchars($t['fb2_selectDefault']) ?></option>
        <option value="Never"><?= htmlspecialchars($t['fb2_freqNever']) ?></option>
        <option value="1-3x"><?= htmlspecialchars($t['fb2_freq1to3']) ?></option>
        <option value="4-6x"><?= htmlspecialchars($t['fb2_freq4to6']) ?></option>
        <option value="7-12x"><?= htmlspecialchars($t['fb2_freq7to12']) ?></option>
        <option value=">12x"><?= htmlspecialchars($t['fb2_freqOver12']) ?></option>
      </select>
    </div>
  </div>
</section>

<!-- ─── SECTION 3: CITIZEN'S CHARTER ─── -->
<section class="sec-card sec-teal" id="s3" aria-labelledby="sec3-title">
  <div class="sec-head">
    <div class="sec-icon-wrap teal" aria-hidden="true">📋</div>
    <div class="sec-meta">
      <div class="charter-title-wrap">
        <h2 class="sec-title" id="sec3-title"><?= htmlspecialchars($t['fb2_sec3Title']) ?></h2>
        <button type="button" class="charter-info-btn" id="charterInfoBtn" aria-label="More information about the Citizen's Charter" aria-expanded="false" aria-controls="charterInfoPopover">ℹ️</button>
        <div class="charter-info-popover" id="charterInfoPopover" role="dialog" aria-labelledby="sec3-title" hidden>
          <?= $t['fb2_charterInfo'] ?>
        </div>
      </div>
      <p class="sec-sub"><?= htmlspecialchars($t['fb2_sec3Sub']) ?></p>
      <span class="sec-badge badge-green"><?= htmlspecialchars(sprintf($t['fb2_secBadge'], 3)) ?></span>
    </div>
  </div>
  <div class="instr-pill" role="note"><?= $t['fb2_charterInstr'] ?></div>

  <div class="field">
    <div class="field-label" id="cc1-label">
      <span class="qnum-tag" aria-hidden="true">CC1</span>
      <?= htmlspecialchars($t['fb2_cc1Question']) ?>
      <span class="req">*</span>
    </div>
    <div class="pill-group" style="flex-direction:column;gap:9px;" role="group" aria-labelledby="cc1-label">
      <label class="pill-opt" style="align-items:flex-start;padding:12px 16px;">
        <input type="radio" name="cc1" value="1" required style="margin-top:2px;">
        <span>1 — <?= htmlspecialchars($t['fb2_cc1Opt1']) ?></span>
      </label>
      <label class="pill-opt" style="align-items:flex-start;padding:12px 16px;">
        <input type="radio" name="cc1" value="2" style="margin-top:2px;">
        <span>2 — <?= htmlspecialchars($t['fb2_cc1Opt2']) ?></span>
      </label>
      <label class="pill-opt" style="align-items:flex-start;padding:12px 16px;">
        <input type="radio" name="cc1" value="3" style="margin-top:2px;">
        <span>3 — <?= htmlspecialchars($t['fb2_cc1Opt3']) ?></span>
      </label>
      <label class="pill-opt" style="align-items:flex-start;padding:12px 16px;">
        <input type="radio" name="cc1" value="4" style="margin-top:2px;">
        <span>4 — <?= htmlspecialchars($t['fb2_cc1Opt4']) ?></span>
      </label>
    </div>
    <div class="note-callout" role="note">
      ⚠️ <?= $t['fb2_cc1Note'] ?>
    </div>
  </div>

  <hr class="fdivider">
  <div class="field">
    <div class="field-label" id="cc2-label">
      <span class="qnum-tag" aria-hidden="true">CC2</span>
      <?= htmlspecialchars($t['fb2_cc2Question']) ?>
      <span class="req" aria-hidden="true">*</span>
    </div>
    <div class="pill-group" role="group" aria-labelledby="cc2-label">
      <label class="pill-opt"><input type="radio" name="cc2" value="Sayon"> <?= htmlspecialchars($t['fb2_cc2Easy']) ?></label>
      <label class="pill-opt"><input type="radio" name="cc2" value="Murag"> <?= htmlspecialchars($t['fb2_cc2Somewhat']) ?></label>
      <label class="pill-opt"><input type="radio" name="cc2" value="Lisod"> <?= htmlspecialchars($t['fb2_cc2Difficult']) ?></label>
      <label class="pill-opt"><input type="radio" name="cc2" value="Dili"> <?= htmlspecialchars($t['fb2_cc2Not']) ?></label>
      <label class="pill-opt"><input type="radio" name="cc2" value="N/A"> <?= htmlspecialchars($t['fb2_naOption']) ?></label>
    </div>
  </div>

  <hr class="fdivider">
  <div class="field">
    <div class="field-label" id="cc3-label">
      <span class="qnum-tag" aria-hidden="true">CC3</span>
      <?= htmlspecialchars($t['fb2_cc3Question']) ?>
      <span class="req" aria-hidden="true">*</span>
    </div>
    <div class="pill-group" role="group" aria-labelledby="cc3-label">
      <label class="pill-opt"><input type="radio" name="cc3" value="Dako"> <?= htmlspecialchars($t['fb2_cc3VeryMuch']) ?></label>
      <label class="pill-opt"><input type="radio" name="cc3" value="Gamay"> <?= htmlspecialchars($t['fb2_cc3Somewhat']) ?></label>
      <label class="pill-opt"><input type="radio" name="cc3" value="Walay"> <?= htmlspecialchars($t['fb2_cc3NoHelp']) ?></label>
      <label class="pill-opt"><input type="radio" name="cc3" value="N/A"> <?= htmlspecialchars($t['fb2_naOption']) ?></label>
    </div>
  </div>
</section>

<!-- ─── SECTION 4: EXPERIENCE RATINGS ─── -->
<section class="sec-card sec-green" id="s4" aria-labelledby="sec4-title">
  <div class="sec-head">
    <div class="sec-icon-wrap green" aria-hidden="true">⭐</div>
    <div class="sec-meta">
      <h2 class="sec-title" id="sec4-title"><?= htmlspecialchars($t['fb2_sec4Title']) ?></h2>
      <p class="sec-sub"><?= htmlspecialchars($t['fb2_sec4Sub']) ?></p>
      <span class="sec-badge badge-green"><?= htmlspecialchars(sprintf($t['fb2_secBadge'], 4)) ?></span>
    </div>
  </div>
  <div class="instr-pill" role="note"><?= htmlspecialchars($t['fb2_ratingInstr']) ?></div>

  <div class="subsec" aria-label="Subsection A"><?= htmlspecialchars($t['fb2_subsecA']) ?> <span class="req" aria-hidden="true">*</span></div>
  <div class="rating-scroll" role="region" aria-label="Infrastructure and Process ratings" aria-describedby="ratA-summary">
    <table class="rating-tbl" aria-describedby="ratA-summary">
      <thead>
        <tr>
          <th scope="col"><?= htmlspecialchars($t['fb2_colStatement']) ?></th>
          <th scope="col"><span class="rating-pill rating-pill-5"><span class="rating-face" aria-hidden="true">😊</span><span>5 — Strongly Agree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-4"><span class="rating-face" aria-hidden="true">🙂</span><span>4 — Agree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-3"><span class="rating-face" aria-hidden="true">😐</span><span>3 — Neutral</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-2"><span class="rating-face" aria-hidden="true">🙁</span><span>2 — Disagree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-1"><span class="rating-face" aria-hidden="true">☹️</span><span>1 — Strongly Disagree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-na"><span class="rating-face" aria-hidden="true">—</span><span>N/A</span></span></th>
        </tr>
      </thead>
      <tbody id="ratA"></tbody>
    </table>
  </div>
  <div class="rating-validation-summary" id="ratA-summary" role="status" aria-live="polite" aria-atomic="true" hidden></div>

  <div class="subsec" style="margin-top:30px;" aria-label="Subsection B"><?= htmlspecialchars($t['fb2_subsecB']) ?> <span class="req" aria-hidden="true">*</span></div>
  <div class="rating-scroll" role="region" aria-label="Client engagement ratings" aria-describedby="ratB-summary">
    <table class="rating-tbl" aria-describedby="ratB-summary">
      <thead>
        <tr>
          <th scope="col"><?= htmlspecialchars($t['fb2_colStatement']) ?></th>
          <th scope="col"><span class="rating-pill rating-pill-5"><span class="rating-face" aria-hidden="true">😊</span><span>5 — Strongly Agree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-4"><span class="rating-face" aria-hidden="true">🙂</span><span>4 — Agree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-3"><span class="rating-face" aria-hidden="true">😐</span><span>3 — Neutral</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-2"><span class="rating-face" aria-hidden="true">🙁</span><span>2 — Disagree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-1"><span class="rating-face" aria-hidden="true">☹️</span><span>1 — Strongly Disagree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-na"><span class="rating-face" aria-hidden="true">—</span><span>N/A</span></span></th>
        </tr>
      </thead>
      <tbody id="ratB"></tbody>
    </table>
  </div>
  <div class="rating-validation-summary" id="ratB-summary" role="status" aria-live="polite" aria-atomic="true" hidden></div>
</section>

<!-- ─── SECTION 5: STAFF EVALUATION ─── -->
<section class="sec-card sec-teal" id="s5" aria-labelledby="sec5-title">
  <div class="sec-head">
    <div class="sec-icon-wrap teal" aria-hidden="true">👩‍⚕️</div>
    <div class="sec-meta">
      <h2 class="sec-title" id="sec5-title"><?= htmlspecialchars($t['fb2_sec5Title']) ?></h2>
      <p class="sec-sub"><?= htmlspecialchars($t['fb2_sec5Sub']) ?></p>
      <span class="sec-badge badge-green"><?= htmlspecialchars(sprintf($t['fb2_secBadge'], 5)) ?></span>
    </div>
  </div>
  <div class="instr-pill" role="note"><?= htmlspecialchars($t['fb2_staffInstr']) ?></div>

  <div class="subsec" aria-label="Healthcare staff ratings"><?= htmlspecialchars($t['fb2_subsecStaff']) ?> <span class="req" aria-hidden="true">*</span></div>
  <div class="rating-scroll" role="region" aria-label="Staff courtesy ratings" aria-describedby="ratStaff-summary">
    <table class="rating-tbl" aria-describedby="ratStaff-summary">
      <thead>
        <tr>
          <th scope="col"><?= htmlspecialchars($t['fb2_colStaff']) ?></th>
          <th scope="col"><span class="rating-pill rating-pill-5"><span class="rating-face" aria-hidden="true">😊</span><span>5 — Strongly Agree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-4"><span class="rating-face" aria-hidden="true">🙂</span><span>4 — Agree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-3"><span class="rating-face" aria-hidden="true">😐</span><span>3 — Neutral</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-2"><span class="rating-face" aria-hidden="true">🙁</span><span>2 — Disagree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-1"><span class="rating-face" aria-hidden="true">☹️</span><span>1 — Strongly Disagree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-na"><span class="rating-face" aria-hidden="true">—</span><span>N/A</span></span></th>
        </tr>
      </thead>
      <tbody id="ratStaff"></tbody>
    </table>
  </div>
  <div class="rating-validation-summary" id="ratStaff-summary" role="status" aria-live="polite" aria-atomic="true" hidden></div>

  <div class="subsec" style="margin-top:26px;" aria-label="Subsection C"><?= htmlspecialchars($t['fb2_subsecC']) ?> <span class="req" aria-hidden="true">*</span></div>
  <div class="rating-scroll" role="region" aria-label="Response culture ratings" aria-describedby="ratC-summary">
    <table class="rating-tbl" aria-describedby="ratC-summary">
      <thead>
        <tr>
          <th scope="col"><?= htmlspecialchars($t['fb2_colStatement']) ?></th>
          <th scope="col"><span class="rating-pill rating-pill-5"><span class="rating-face" aria-hidden="true">😊</span><span>5 — Strongly Agree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-4"><span class="rating-face" aria-hidden="true">🙂</span><span>4 — Agree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-3"><span class="rating-face" aria-hidden="true">😐</span><span>3 — Neutral</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-2"><span class="rating-face" aria-hidden="true">🙁</span><span>2 — Disagree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-1"><span class="rating-face" aria-hidden="true">☹️</span><span>1 — Strongly Disagree</span></span></th>
          <th scope="col"><span class="rating-pill rating-pill-na"><span class="rating-face" aria-hidden="true">—</span><span>N/A</span></span></th>
        </tr>
      </thead>
      <tbody id="ratC"></tbody>
    </table>
  </div>
  <div class="rating-validation-summary" id="ratC-summary" role="status" aria-live="polite" aria-atomic="true" hidden></div>
</section>

<!-- ─── SECTION 6: COMMENTS & SUGGESTIONS ─── -->
<section class="sec-card sec-green section-comments" id="s6" aria-labelledby="sec6-title">
  <div class="sec-head">
    <div class="sec-icon-wrap green" aria-hidden="true">💬</div>
    <div class="sec-meta">
      <h2 class="sec-title" id="sec6-title"><?= htmlspecialchars($t['fb2_sec6Title']) ?></h2>
      <p class="sec-sub"><?= htmlspecialchars($t['fb2_sec6Sub']) ?></p>
      <span class="sec-badge badge-green"><?= htmlspecialchars(sprintf($t['fb2_secBadge'], 6)) ?></span>
    </div>
  </div>

  <div class="form-grid g1">
    <div class="field">
      <label class="field-label" for="commentText"><?= htmlspecialchars($t['fb2_commentLabel']) ?></label>
      <textarea class="ftextarea section-comments-textarea" id="commentText" name="comment" maxlength="300"
        placeholder="<?= htmlspecialchars($t['fb2_commentPlaceholder']) ?>"
        aria-describedby="commentText-count"></textarea>
      <div class="fchar" id="commentText-count" aria-live="polite"><span id="commentTextCount">0</span>/300 characters</div>
    </div>
    <div class="field">
      <label class="field-label" for="staffToThank"><?= htmlspecialchars($t['fb2_staffToThankLabel']) ?></label>
      <textarea class="ftextarea section-staff-textarea" id="staffToThank" name="staff_to_thank" maxlength="150"
        placeholder="<?= htmlspecialchars($t['fb2_staffToThankPlaceholder']) ?>"
        aria-describedby="staffToThank-count"></textarea>
      <div class="fchar" id="staffToThank-count" aria-live="polite"><span id="staffToThankCount">0</span>/150 characters</div>
    </div>
  </div>

  <!-- ══ SUBMIT DOCK ══ -->
  <div class="submit-dock" role="region" aria-label="Form submission">
    <div class="submit-inner">
      <label class="consent-row">
        <input type="checkbox" id="consentChk" required aria-required="true">
        <span class="consent-text">
          ✅ <?= $t['fb2_consentText'] ?>
        </span>
      </label>
      <div class="submit-btns">
        <button type="button" class="btn-clear" onclick="resetSurvey()" aria-label="Clear all survey fields">
          <?= htmlspecialchars($t['fb2_btnClear']) ?>
        </button>
        <button type="submit" class="btn-submit" id="submitBtn" aria-label="Submit survey">
          <div class="spin" aria-hidden="true"></div>
          <span class="lbl"><?= htmlspecialchars($t['fb2_btnSubmit']) ?></span>
        </button>
      </div>
    </div>
  </div>
</section>

</form>

</div><!-- /form-wrap -->

<script>
/* ═══════════════════════════════════════
   TRANSLATIONS — injected from PHP via json_encode().
   Only the visible question/role text lives here; every id/name/value
   used in the form itself stays a plain JS string literal below.
═══════════════════════════════════════ */
const FB2_I18N = <?= json_encode([
  'ratA' => [
    'sqd4' => $t['fb2_ratA_sqd4'], 'a2' => $t['fb2_ratA_a2'], 'a3' => $t['fb2_ratA_a3'], 'a4' => $t['fb2_ratA_a4'],
    'sqd3' => $t['fb2_ratA_sqd3'], 'sqd2' => $t['fb2_ratA_sqd2'], 'a7' => $t['fb2_ratA_a7'], 'sqd1' => $t['fb2_ratA_sqd1'],
  ],
  'ratB' => [
    'b9' => $t['fb2_ratB_b9'], 'b10' => $t['fb2_ratB_b10'], 'b11' => $t['fb2_ratB_b11'], 'b12' => $t['fb2_ratB_b12'],
    'b13' => $t['fb2_ratB_b13'], 'sqd8' => $t['fb2_ratB_sqd8'], 'sqd5' => $t['fb2_ratB_sqd5'],
  ],
  'staff' => $officialStaffRoleLabels,
  'ratC' => [
    'c17' => $t['fb2_ratC_c17'], 'c18' => $t['fb2_ratC_c18'], 'sqd6' => $t['fb2_ratC_sqd6'], 'sqd0' => $t['fb2_ratC_sqd0'],
  ],
  'msgs' => [
    'consentRequired' => $t['fb2_msgConsentRequired'], 'completeRequired' => $t['fb2_msgCompleteRequired'],
    'fieldRequired' => $t['fb2_msgFieldRequired'], 'completeField' => $t['fb2_msgCompleteField'],
    'commentTooLong' => $t['fb2_section6ErrCommentTooLong'],
    'staffTooLong' => $t['fb2_section6ErrStaffTooLong'],
    'ratingRemainingOne' => $t['fb2_msgRatingRemainingOne'], 'ratingRemainingMany' => $t['fb2_msgRatingRemainingMany'],
    'networkError' => $t['fb2_msgNetworkError'], 'resetDone' => $t['fb2_msgResetDone'],
    'submitting' => $t['fb2_btnSubmitting'], 'submitLabel' => $t['fb2_btnSubmit'],
  ],
], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;

/* The public API is the sole catalog source. Option values are database IDs. */
let serviceCatalog = [];

function renderServiceSubtypes() {
  const mainSelect = document.getElementById('serbisyo');
  const subtypeWrap = document.getElementById('serbisyo_subservice_wrap');
  const subtypeSelect = document.getElementById('serbisyo_subservice');
  if (!mainSelect || !subtypeWrap || !subtypeSelect) return;

  const service = serviceCatalog.find(item => String(item.id) === mainSelect.value);
  const subservices = service?.subservices || [];
  subtypeSelect.innerHTML = '<option value="">— Select specific service —</option>'
    + subservices.map(item => `<option value="${item.id}">${item.name}</option>`).join('');
  subtypeWrap.hidden = subservices.length === 0;
  subtypeSelect.required = subservices.length > 0;
  if (!subservices.length) {
    subtypeSelect.value = '';
    clearValidationState(subtypeSelect);
  }
  updateOtherSubtypeInput();
}

function updateOtherSubtypeInput() {
  const mainSelect = document.getElementById('serbisyo');
  const subtypeSelect = document.getElementById('serbisyo_subservice');
  const otherWrap = document.getElementById('serbisyo_subservice_other_wrap');
  const otherInput = document.getElementById('serbisyo_subservice_other');
  if (!mainSelect || !subtypeSelect || !otherWrap || !otherInput) return;

  const service = serviceCatalog.find(item => String(item.id) === mainSelect.value);
  const subservices = service?.subservices || [];
  const selectedSubtype = subservices.find(item => String(item.id) === subtypeSelect.value);
  const isOther = selectedSubtype?.allowsCustomText === true;
  otherWrap.hidden = !isOther;
  otherWrap.classList.toggle('open', isOther);
  otherInput.required = isOther;
  if (!isOther) {
    otherInput.value = '';
    clearValidationState(otherInput);
  }
}

async function syncServiceOptions() {
  const select = document.getElementById('serbisyo');
  if (!select) return;

  select.disabled = true;

  try {
    const response = await fetch('/Feedback/api/public_catalog.php', {
      headers: { Accept: 'application/json' },
    });
    const payload = await response.json();
    const services = payload.success && Array.isArray(payload.data?.services)
      ? payload.data.services
      : [];
    if (!services.length) throw new Error('No active services available');
    serviceCatalog = services;

    const selectedValue = select.value;
    select.replaceChildren(select.options[0]);
    services.forEach(service => {
      const option = new Option(
        service.name,
        String(service.id)
      );
      select.add(option);
    });
    if (services.some(service => String(service.id) === selectedValue)) {
      select.value = selectedValue;
    }
    renderServiceSubtypes();
  } catch (error) {
    console.error('Service catalog unavailable.', error);
    select.setCustomValidity('Service catalog is unavailable. Please reload and try again.');
  } finally {
    select.disabled = serviceCatalog.length === 0;
  }
}

document.getElementById('serbisyo')?.addEventListener('change', renderServiceSubtypes);
document.getElementById('serbisyo_subservice')?.addEventListener('change', updateOtherSubtypeInput);
syncServiceOptions();

const charterInfoBtn=document.getElementById('charterInfoBtn');
const charterInfoPopover=document.getElementById('charterInfoPopover');
if(charterInfoBtn && charterInfoPopover){
  charterInfoBtn.addEventListener('click',(event)=>{
    event.stopPropagation();
    const isOpen=!charterInfoPopover.hidden;
    charterInfoPopover.hidden=isOpen;
    charterInfoBtn.setAttribute('aria-expanded',String(!isOpen));
  });
  document.addEventListener('click',(event)=>{
    if(charterInfoPopover.hidden || event.target.closest('#charterInfoPopover, #charterInfoBtn')) return;
    charterInfoPopover.hidden=true;
    charterInfoBtn.setAttribute('aria-expanded','false');
  });
}

/* ═══════════════════════════════════════
   RATING DATA
═══════════════════════════════════════ */
const ratingsA=[
  {id:'sqd4',n:'SQD4',text:FB2_I18N.ratA.sqd4},
  {id:'a2',n:'Q2',text:FB2_I18N.ratA.a2},
  {id:'a3',n:'Q3',text:FB2_I18N.ratA.a3},
  {id:'a4',n:'Q4',text:FB2_I18N.ratA.a4},
  {id:'sqd3',n:'SQD3',text:FB2_I18N.ratA.sqd3},
  {id:'sqd2',n:'SQD2',text:FB2_I18N.ratA.sqd2},
  {id:'a7',n:'Q7',text:FB2_I18N.ratA.a7},
  {id:'sqd1',n:'SQD1',text:FB2_I18N.ratA.sqd1},
];
const ratingsB=[
  {id:'b9',n:'Q9',text:FB2_I18N.ratB.b9},
  {id:'b10',n:'Q10',text:FB2_I18N.ratB.b10},
  {id:'b11',n:'Q11',text:FB2_I18N.ratB.b11},
  {id:'b12',n:'Q12',text:FB2_I18N.ratB.b12},
  {id:'b13',n:'Q13',text:FB2_I18N.ratB.b13},
  {id:'sqd8',n:'SQD8',text:FB2_I18N.ratB.sqd8},
  {id:'sqd5',n:'SQD5',text:FB2_I18N.ratB.sqd5},
];
// Built server-side from the active-only $officialStaffRoleLabels (already
// sourced from the `staff` table above, with a hardcoded fallback if the
// database was unreachable) — an inactive role is simply absent here, so
// it's neither rendered nor required on NEW submissions. Historical
// feedback already recorded under its st-key is untouched either way.
const staffList=<?= json_encode(array_map(
    static fn (string $key): array => [
        'id' => $key,
        'role' => $officialStaffRoleLabels[$key],
        'emoji' => $staffRoleEmojis[$key] ?? '🩺',
    ],
    array_keys($officialStaffRoleLabels)
), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS) ?>;
const ratingsC=[
  {id:'c17',n:'Q17',text:FB2_I18N.ratC.c17},
  {id:'c18',n:'Q18',text:FB2_I18N.ratC.c18},
  {id:'sqd6',n:'SQD6',text:FB2_I18N.ratC.sqd6},
  {id:'sqd0',n:'SQD0',text:FB2_I18N.ratC.sqd0},
];
const emojis=['😊','🙂','😐','🙁','☹️','—'];
const vals=['5','4','3','2','1','N/A'];

function buildRows(data,tbodyId){
  const tb=document.getElementById(tbodyId);
  if(!tb)return;
  data.forEach(item=>{
    const tr=document.createElement('tr');
    tr.dataset.ratingGroup=item.id;
    const tag=item.n?`<span class="qnum-tag" aria-hidden="true">${item.n}</span>`:'';
    tr.innerHTML=`<td>${tag}${item.text}</td>`+
      vals.map((v,i)=>`<td>
        <input class="r-radio" type="radio" name="${item.id}" id="${item.id}_${v}" value="${v}" aria-label="${item.text} — ${v}">
        <label class="r-label" for="${item.id}_${v}" aria-hidden="true">
          <span class="r-emoji">${emojis[i]}</span>
          <span class="r-val">${v}</span>
        </label></td>`).join('');
    tb.appendChild(tr);
  });
}
function buildStaffRows(){
  const tb=document.getElementById('ratStaff');
  if(!tb)return;
  staffList.forEach(s=>{
    const tr=document.createElement('tr');
    tr.dataset.ratingGroup=s.id;
    tr.innerHTML=`<td>${s.emoji} ${s.role}</td>`+
      vals.map((v,i)=>`<td>
        <input class="r-radio" type="radio" name="${s.id}" id="${s.id}_${v}" value="${v}" aria-label="${s.role} — ${v}">
        <label class="r-label" for="${s.id}_${v}" aria-hidden="true">
          <span class="r-emoji">${emojis[i]}</span>
          <span class="r-val">${v}</span>
        </label></td>`).join('');
    tb.appendChild(tr);
  });
}
buildRows(ratingsA,'ratA');
buildRows(ratingsB,'ratB');
buildStaffRows();
buildRows(ratingsC,'ratC');

const requiredRatingGroups=[...ratingsA.map(i=>i.id),...ratingsB.map(i=>i.id),...staffList.map(s=>s.id),...ratingsC.map(i=>i.id)];
const ratingSections=[
  {tbodyId:'ratA',summaryId:'ratA-summary',groups:ratingsA.map(item=>item.id)},
  {tbodyId:'ratB',summaryId:'ratB-summary',groups:ratingsB.map(item=>item.id)},
  {tbodyId:'ratStaff',summaryId:'ratStaff-summary',groups:staffList.map(item=>item.id)},
  {tbodyId:'ratC',summaryId:'ratC-summary',groups:ratingsC.map(item=>item.id)},
];

function getRatingSection(groupName){
  return ratingSections.find(section=>section.groups.includes(groupName)) || null;
}
function ratingGroupIsAnswered(groupName){
  return Array.from(document.querySelectorAll(`[name="${groupName}"]`)).some(radio=>radio.checked);
}
function updateRatingSectionValidation(section,showSummary=false){
  if(!section) return 0;
  const tbody=document.getElementById(section.tbodyId);
  const summary=document.getElementById(section.summaryId);
  const remaining=section.groups.filter(groupName=>!ratingGroupIsAnswered(groupName));

  section.groups.forEach(groupName=>{
    const row=tbody?.querySelector(`[data-rating-group="${groupName}"]`);
    const unanswered=remaining.includes(groupName);
    row?.classList.toggle('rating-row-invalid',unanswered && showSummary);
    if(row){
      if(unanswered && showSummary){
        row.setAttribute('aria-invalid','true');
        row.setAttribute('tabindex','-1');
      }else{
        row.removeAttribute('aria-invalid');
        row.removeAttribute('tabindex');
      }
    }
  });

  if(summary){
    const active=showSummary || summary.dataset.validationActive==='true';
    summary.dataset.validationActive=active && remaining.length ? 'true' : 'false';
    summary.hidden=!active || remaining.length===0;
    if(!summary.hidden){
      const template=remaining.length===1 ? FB2_I18N.msgs.ratingRemainingOne : FB2_I18N.msgs.ratingRemainingMany;
      summary.textContent=template.replace('%d',String(remaining.length));
    }else{
      summary.textContent='';
    }
  }
  return remaining.length;
}
function clearRatingValidation(){
  ratingSections.forEach(section=>{
    const summary=document.getElementById(section.summaryId);
    if(summary){
      summary.hidden=true;
      summary.textContent='';
      summary.dataset.validationActive='false';
    }
    document.getElementById(section.tbodyId)?.querySelectorAll('.rating-row-invalid').forEach(row=>{
      row.classList.remove('rating-row-invalid');
      row.removeAttribute('aria-invalid');
      row.removeAttribute('tabindex');
    });
  });
}

function getValidationContainer(el){
  if(!el) return null;
  if(el.closest('.other-reveal-inner')) return el.closest('.other-reveal-inner');
  if(el.id==='serbisyo_subservice') return el.closest('.field-subservice');
  return el.closest('.field') || el.closest('.rating-scroll') || null;
}
function getValidationLabel(el){
  if(!el) return null;
  if(el.id==='serbisyo_subservice') return el.closest('.field-subservice')?.querySelector('.field-label') || null;
  if(el.closest('.other-reveal-inner')) return null;
  const field=el.closest('.field');
  if(field){
    return field.querySelector('.field-label');
  }
  const ratingScroll=el.closest('.rating-scroll');
  if(ratingScroll){
    const prev=ratingScroll.previousElementSibling;
    if(prev && prev.classList.contains('subsec')) return prev;
  }
  return null;
}
function clearValidationState(el){
  const container=getValidationContainer(el);
  if(container){
    const err=Array.from(container.children).find(child=>child.classList?.contains('field-error') && child.dataset.validationFor===el.id);
    if(err) err.remove();
    const hasErrors=Array.from(container.children).some(child=>child.classList?.contains('field-error'));
    if(!hasErrors) container.classList.remove('invalid');
  }
  const label=getValidationLabel(el);
  if(label) label.classList.remove('invalid');
  if(el && typeof el.classList !== 'undefined') {
    el.classList.remove('invalid');
    el.classList.remove('valid');
  }
}
function setValidationState(el,message){
  const container=getValidationContainer(el);
  const label=getValidationLabel(el);
  if(label) label.classList.add('invalid');
  if(container){
    container.classList.add('invalid');
    let err=Array.from(container.children).find(child=>child.classList?.contains('field-error') && child.dataset.validationFor===el.id);
    if(!err){
      err=document.createElement('div');
      err.className='field-error';
      err.dataset.validationFor=el.id;
      err.setAttribute('role','alert');
      container.appendChild(err);
    }
    err.textContent=message;
  }
  if(el && typeof el.classList !== 'undefined') {
    el.classList.add('invalid');
    el.classList.remove('valid');
  }
}
function clearAllValidation(){
  document.querySelectorAll('.field, .rating-scroll, .subsec, .field-subservice, .other-reveal-inner').forEach(node=>{
    node.classList.remove('invalid');
  });
  document.querySelectorAll('.field-error').forEach(err=>err.remove());
  document.querySelectorAll('.field-label, .subsec').forEach(label=>label.classList.remove('invalid'));
  document.querySelectorAll('input.invalid, select.invalid, textarea.invalid').forEach(el=>el.classList.remove('invalid'));
  clearRatingValidation();
}
function isActiveValidationField(el){
  if(!el || el.disabled || el.closest('[hidden]')) return false;
  const reveal=el.closest('.other-reveal');
  return !reveal || reveal.classList.contains('open');
}
function conditionalOtherIsRequired(el){
  if(el.id==='resp_type_other') return document.getElementById('resp_type')?.value==='Other';
  if(el.id==='gender_pref_other') return document.getElementById('gender_pref')?.value==='other';
  if(el.id==='relihiyonOther') return document.getElementById('relihiyon')?.value==='other';
  if(el.id==='serbisyo_other'){
    const selected=serviceCatalog.find(service=>String(service.id)===document.getElementById('serbisyo')?.value);
    return selected?.allowsCustomText===true;
  }
  if(el.id==='serbisyo_subservice_other'){
    const service=serviceCatalog.find(item=>String(item.id)===document.getElementById('serbisyo')?.value);
    const subtype=service?.subservices?.find(item=>String(item.id)===document.getElementById('serbisyo_subservice')?.value);
    return subtype?.allowsCustomText===true;
  }
  return false;
}
function validateRadioGroup(groupName,showError=true){
  const radios=document.querySelectorAll(`[name="${groupName}"]`);
  if(!radios.length) return true;
  const isValid=Array.from(radios).some(r=>r.checked);
  const ratingSection=getRatingSection(groupName);
  if(ratingSection){
    const summary=document.getElementById(ratingSection.summaryId);
    updateRatingSectionValidation(ratingSection,showError && summary?.dataset.validationActive==='true');
    return isValid;
  }
  if(!isValid){
    if(showError) setValidationState(radios[0],FB2_I18N.msgs.completeField);
    return false;
  }
  clearValidationState(radios[0]);
  return true;
}
function validateField(el,showError=true){
  if(!el) return true;
  if(el.type==='radio') return validateRadioGroup(el.name,showError);
  if(!isActiveValidationField(el)){
    clearValidationState(el);
    return true;
  }
  if(el.type==='checkbox'){ 
    const isValid=el.checked;
    if(!isValid){
      if(showError) setValidationState(el,FB2_I18N.msgs.fieldRequired);
      return false;
    }
    clearValidationState(el);
    return true;
  }
  const section6Rules = {
    commentText: { required: false, maxLength: 300, lengthMessage: FB2_I18N.msgs.commentTooLong },
    staffToThank: { required: false, maxLength: 150, lengthMessage: FB2_I18N.msgs.staffTooLong },
  };
  const section6Rule = section6Rules[el.id];
  if(section6Rule){
    const isFilled=Boolean(el.value.trim());
    const length=Array.from(el.value).length;
    if(section6Rule.required && !isFilled){
      if(showError) setValidationState(el,section6Rule.requiredMessage);
      return false;
    }
    if(length>section6Rule.maxLength){
      if(showError) setValidationState(el,section6Rule.lengthMessage);
      return false;
    }
    clearValidationState(el);
    return true;
  }
  if(['resp_type_other','gender_pref_other','relihiyonOther','serbisyo_other','serbisyo_subservice_other'].includes(el.id)){
    const shouldRequire=conditionalOtherIsRequired(el);
    if(!shouldRequire){
      clearValidationState(el);
      return true;
    }
    const isValid=Boolean(el.value.trim());
    if(!isValid){
      if(showError) setValidationState(el,FB2_I18N.msgs.fieldRequired);
      return false;
    }
    clearValidationState(el);
    return true;
  }
  if(el.id==='edad'){
    const raw=(el.value||'').trim();
    const isValid=raw!=='' && /^\d{1,2}$/.test(raw) && Number(raw)>=1 && Number(raw)<=99;
    if(!isValid){
      if(showError) setValidationState(el,FB2_I18N.msgs.completeField);
      return false;
    }
    clearValidationState(el);
    return true;
  }
  if(el.hasAttribute('required')){
    const isValid=Boolean(el.value.trim());
    if(!isValid){
      if(showError) setValidationState(el,FB2_I18N.msgs.fieldRequired);
      return false;
    }
    clearValidationState(el);
    return true;
  }
  if(el.name && requiredRatingGroups.includes(el.name)){
    return validateRadioGroup(el.name,showError);
  }
  clearValidationState(el);
  return true;
}
function validateForm(){
  clearAllValidation();

  const requiredFields=[
    document.getElementById('resp_type'),
    document.getElementById('edad'),
    document.getElementById('sex'),
    document.getElementById('petsa'),
    document.getElementById('serbisyo'),
    document.getElementById('freq'),
    document.getElementById('gender_pref'),
    document.getElementById('relihiyon'),
    document.getElementById('educ'),
    document.getElementById('serbisyo_subservice'),
    document.querySelector('[name="cc1"]'),
    document.getElementById('commentText'),
    document.getElementById('staffToThank'),
    document.getElementById('consentChk')
  ];
  const otherInputs=[document.getElementById('resp_type_other'),document.getElementById('gender_pref_other'),document.getElementById('relihiyonOther'),document.getElementById('serbisyo_other'),document.getElementById('serbisyo_subservice_other')];
  const invalidCandidates=[...requiredFields,...otherInputs];
  let firstInvalid=null;
  for(const candidate of invalidCandidates){
    if(!candidate) continue;
    if(candidate.type==='radio' || candidate.name==='cc1'){
      const ok=validateRadioGroup(candidate.name,true);
      if(!ok && !firstInvalid) firstInvalid=candidate;
      continue;
    }
    if(candidate.type==='checkbox'){
      const ok=validateField(candidate,true);
      if(!ok && !firstInvalid) firstInvalid=candidate;
      continue;
    }
    const ok=validateField(candidate,true);
    if(!ok && !firstInvalid) firstInvalid=candidate;
  }
  const cc2Locked=document.querySelector('[name="cc1"]:checked')?.value==='4';
  if(!cc2Locked){
    const cc2Ok=validateRadioGroup('cc2',true);
    if(!cc2Ok && !firstInvalid){ firstInvalid=document.querySelector('[name="cc2"]'); }
  }
  if(!cc2Locked){
    const cc3Ok=validateRadioGroup('cc3',true);
    if(!cc3Ok && !firstInvalid){ firstInvalid=document.querySelector('[name="cc3"]'); }
  }
  for(const section of ratingSections){
    updateRatingSectionValidation(section,true);
    if(firstInvalid) continue;
    const firstMissingGroup=section.groups.find(groupName=>!ratingGroupIsAnswered(groupName));
    if(firstMissingGroup){
      const firstRadio=document.querySelector(`[name="${firstMissingGroup}"]`);
      firstInvalid=firstRadio?.closest('tr') || firstRadio;
    }
  }
  if(firstInvalid){
    const focusTarget=firstInvalid;
    if(focusTarget){
      focusTarget.scrollIntoView({behavior:'smooth',block:'center'});
      setTimeout(()=>focusTarget.focus?.(),320);
    }
    return false;
  }
  return true;
}
function attachValidationEvents(){
  const form=document.getElementById('mainForm');
  if(!form) return;
  const handleFieldEvent=(event)=>{
    const target=event.target;
    if(!target || !target.matches('input, select, textarea')) return;
    target.dataset.touched='true';
    if(target.type==='radio'){ validateRadioGroup(target.name,true); return; }
    if(target.type==='checkbox'){ validateField(target,true); return; }
    validateField(target,true);
  };
  form.addEventListener('blur',handleFieldEvent,false);
  form.addEventListener('change',handleFieldEvent,false);
  form.addEventListener('input',handleFieldEvent,false);
}
attachValidationEvents();

/* ═══════════════════════════════════════
   SMOOTH "OTHER" REVEAL — Generic Helper
═══════════════════════════════════════ */
function setupOtherReveal(selectId,revealId,otherInputId){
  const sel=document.getElementById(selectId);
  const wrap=document.getElementById(revealId);
  const inp=document.getElementById(otherInputId);
  if(!sel||!wrap)return;
  function sync(userTriggered){
    const catalogService = selectId === 'serbisyo'
      ? serviceCatalog.find(service => String(service.id) === sel.value)
      : null;
    const show=selectId==='serbisyo' ? catalogService?.allowsCustomText === true : sel.value===(selectId==='resp_type' ? 'Other' : 'other');
    wrap.classList.toggle('open',show);
    if(inp){
      inp.required=show;
      if(!show){
        inp.value='';
        clearValidationState(inp);
      } else if(userTriggered){
        setTimeout(()=>inp.focus(),320);
      }
    }
  }
  sel.addEventListener('change',function(){ sync(true); });
  sync(false);
}
setupOtherReveal('resp_type','resp_type_other_wrap','resp_type_other');
setupOtherReveal('gender_pref','gender_pref_other_wrap','gender_pref_other');
setupOtherReveal('relihiyon','relihiyon_other_wrap','relihiyonOther');
setupOtherReveal('serbisyo','serbisyo_other_wrap','serbisyo_other');

/* ═══════════════════════════════════════
   CC AUTO-LOCK BEHAVIOR
═══════════════════════════════════════ */
function setRadioGroupValue(name,value){
  const radios=document.querySelectorAll(`[name="${name}"]`);
  radios.forEach(r=>{
    r.checked=r.value===value;
  });
  syncRadioGroupSelection(name);
}
function clearRadioGroup(name){
  document.querySelectorAll(`[name="${name}"]`).forEach(r=>{r.checked=false;});
  syncRadioGroupSelection(name);
}
function setRadioGroupDisabled(name,disabled){
  document.querySelectorAll(`[name="${name}"]`).forEach(r=>{r.disabled=disabled;});
}
function syncRadioGroupSelection(name){
  document.querySelectorAll(`[name="${name}"]`).forEach(r=>{
    r.closest('.pill-opt')?.classList.toggle('selected',r.checked);
  });
}
function updateCCLockState(){
  const cc1Checked=document.querySelector('[name="cc1"]:checked');
  const isLocked=cc1Checked?.value==='4';
  const cc2Field=document.getElementById('cc2-label')?.closest('.field');
  const cc3Field=document.getElementById('cc3-label')?.closest('.field');
  [cc2Field,cc3Field].forEach(field=>field?.classList.toggle('cc-auto-locked',isLocked));
  setRadioGroupDisabled('cc2',isLocked);
  setRadioGroupDisabled('cc3',isLocked);
  const cc2Note=document.getElementById('cc2AutoNote');
  const cc3Note=document.getElementById('cc3AutoNote');
  if(cc2Note) cc2Note.hidden=!isLocked;
  if(cc3Note) cc3Note.hidden=!isLocked;
  if(isLocked){
    setRadioGroupValue('cc2','N/A');
    setRadioGroupValue('cc3','N/A');
  }
  document.querySelectorAll('[name="cc2"], [name="cc3"]').forEach(r=>{
    const opt=r.closest('.pill-opt');
    if(opt){
      opt.style.pointerEvents=isLocked?'none':'';
      opt.style.opacity=isLocked && !r.checked ? '.55' : '';
    }
  });
  updateProgress();
}

document.querySelectorAll('[name="cc1"]').forEach(radio=>{
  radio.addEventListener('change',updateCCLockState);
});
updateCCLockState();

/* ═══════════════════════════════════════
   PILL RADIO INTERACTIVITY
═══════════════════════════════════════ */
document.querySelectorAll('.pill-group').forEach(grp=>{
  grp.querySelectorAll('.pill-opt').forEach(opt=>{
    const inp=opt.querySelector('input');
    if(!inp||inp.type!=='radio')return;
    inp.addEventListener('change',()=>{
      if(inp.name==='cc2' || inp.name==='cc3'){
        const cc1Checked=document.querySelector('[name="cc1"]:checked');
        if(cc1Checked?.value==='4') return;
      }
      document.querySelectorAll(`[name="${inp.name}"]`).forEach(r=>{
        r.closest('.pill-opt')?.classList.remove('selected');
      });
      opt.classList.add('selected');
    });
  });
});
document.querySelectorAll('.chk-opt').forEach(opt=>{
  const inp=opt.querySelector('input[type=checkbox]');
  if(!inp)return;
  inp.addEventListener('change',()=>opt.classList.toggle('checked',inp.checked));
});

/* ═══════════════════════════════════════
   PROGRESS
═══════════════════════════════════════ */
function updateProgress(){
  const required=['resp_type','sex','edad','petsa','freq','cc1'];
  const serbEl=document.querySelector('[name="serbisyo"]');
  let serbOk=false;
  if(serbEl){
    if(serbEl.tagName==='SELECT'){
      serbOk = serbEl.value && serbEl.value !== '';
    } else {
      serbOk = document.querySelectorAll('[name=serbisyo]:checked').length>0;
    }
  }
  let done=0;
  required.forEach(n=>{
    const el=document.querySelector(`[name="${n}"]`);
    if(!el)return;
    if(el.type==='radio'){if(document.querySelector(`[name="${n}"]:checked`))done++;}
    else if(el.tagName==='SELECT'){if(el.value&&el.value!=='')done++;}
    else if(el.value.trim())done++;
  });
  if(serbOk)done++;
  const total=required.length+1;
  const pct=Math.min(100,Math.round((done/total)*100));
  document.getElementById('progFill').style.width=pct+'%';
  const w=document.getElementById('progBarWrap');
  if(w)w.setAttribute('aria-valuenow',pct);
}
document.getElementById('mainForm').addEventListener('change',updateProgress);
document.getElementById('mainForm').addEventListener('input',updateProgress);

/* ═══════════════════════════════════════
   WIZARD SCROLL TRACKING
═══════════════════════════════════════ */
const sections=['s1','s2','s3','s4','s5','s6'];
const wsIds=['ws1','ws2','ws3','ws4','ws5','ws6'];
function goToSec(id,e){
  if(e)e.preventDefault();
  const el=document.getElementById(id);
  if(el)el.scrollIntoView({behavior:'smooth',block:'start'});
}
// Reference line = same header-offset used by the original rootMargin below.
// intersectionRatio is normalized against each section's OWN bounding-box
// area, so a section many times taller than the viewport (Ratings: 15 rows,
// Staff: 14 rows) can never reach a fixed ratio threshold like the old
// `.2` — that's why the wizard step froze on whatever section last crossed
// it. Picking the active step by scroll POSITION instead (the last section
// whose top has scrolled above the reference line) works regardless of a
// section's height. IntersectionObserver still drives it (unchanged
// sections/wsIds/goToSec/section IDs/wizard HTML/CSS), refined with a
// widened threshold list plus a light scroll listener so the position is
// re-checked continuously while scrolling through a tall section, not only
// at its enter/leave edges.
const WIZARD_REF_LINE=72+58;
function updateActiveWizardStep(){
  let activeIndex=0;
  for(let i=0;i<sections.length;i++){
    const el=document.getElementById(sections[i]);
    if(!el)continue;
    if(el.getBoundingClientRect().top<=WIZARD_REF_LINE) activeIndex=i;
  }
  wsIds.forEach((w,j)=>{
    const el=document.getElementById(w);
    if(!el)return;
    el.classList.toggle('active',j===activeIndex);
    if(j<activeIndex)el.classList.add('done');else el.classList.remove('done');
  });
}
let wizardTicking=false;
function scheduleWizardUpdate(){
  if(wizardTicking)return;
  wizardTicking=true;
  requestAnimationFrame(()=>{
    updateActiveWizardStep();
    wizardTicking=false;
  });
}
const io=new IntersectionObserver(scheduleWizardUpdate,{
  threshold:[0,.1,.2,.3,.4,.5,.6,.7,.8,.9,1],
  rootMargin:`-${72+58}px 0px -50% 0px`
});
sections.forEach(s=>{const el=document.getElementById(s);if(el)io.observe(el);});
window.addEventListener('scroll',scheduleWizardUpdate,{passive:true});
updateActiveWizardStep();

/* ═══════════════════════════════════════
   CHARACTER COUNTERS
═══════════════════════════════════════ */
[['commentText','commentTextCount'],['staffToThank','staffToThankCount']].forEach(([id,cid])=>{
  const el=document.getElementById(id);
  const c=document.getElementById(cid);
  if(el&&c)el.addEventListener('input',()=>c.textContent=Array.from(el.value).length);
});

/* Draft/autosave removed per user request */

// ═══════════════════════════════════════
// EDAD INPUT ENFORCEMENT — accept up to 2 digits, disallow 0/00, clamp at 99
// ═══════════════════════════════════════
(function(){
  const edadEl=document.getElementById('edad');
  if(!edadEl) return;
  let edadTimer;
  edadEl.addEventListener('input',function(){
    let v=(this.value||'')+'';
    v=v.replace(/\D/g,'');
    if(v.length>2) v=v.slice(0,2);
    if(v==='00' || v==='0') v='';
    if(v !== ''){
      const n=Number(v);
      if(n>99) v='99';
    }
    if(this.value!==v) this.value=v;
    try{ updateProgress(); }catch(e){}
    clearTimeout(edadTimer); edadTimer=setTimeout(()=>{},300);
  });
  edadEl.addEventListener('blur',function(){
    if(this.value==='' || this.value==null) return;
    let n=Number(this.value)||0;
    if(n<=0) this.value='';
    else if(n>99) this.value='99';
  });
})();

/* ═══════════════════════════════════════
   FORM SUBMIT
═══════════════════════════════════════ */
async function submitForm(e){
  e.preventDefault();

  const consent=document.getElementById('consentChk');
  if(!consent || !consent.checked){
    validateField(consent,true);
    consent?.focus();
    toast(FB2_I18N.msgs.consentRequired,'error');
    return;
  }

  const formIsValid=validateForm();
  if(!formIsValid){
    toast(FB2_I18N.msgs.completeRequired,'error');
    return;
  }

  const btn=document.getElementById('submitBtn');
  btn.classList.add('loading');
  btn.disabled=true;
  btn.querySelector('.lbl').textContent=FB2_I18N.msgs.submitting;

  try{
    const payload={};
    const respTypeVal=document.getElementById('resp_type')?.value||'';
    payload.source=(respTypeVal==='Business (visited for business purposes)')?'visitor':'patient';
    payload.respondentType=respTypeVal;
    payload.resp_type_other=document.getElementById('resp_type_other')?.value?.trim()||'';
    // Active SFMS workflow does not use an Anonymous submission mode.
    // The current form and server do not ask for respondent names, so the
    // submission payload must not carry any anonymous flag.
    // The original PCF-CES survey has no Department field/question — only
    // Service. api/feedback_submit.php no longer reads a department value
    // at all, so nothing is sent here.
    payload.service_id=document.getElementById('serbisyo')?.value||'';
    const selectedService = serviceCatalog.find(service => String(service.id) === payload.service_id);
    payload.service=selectedService?.name || '';
    payload.service_subtype_id=document.getElementById('serbisyo_subservice')?.value||'';
    payload.service_subtype_other=document.getElementById('serbisyo_subservice_other')?.value||'';
    payload.service_other=document.getElementById('serbisyo_other')?.value||'';
    payload.consultation_date=document.getElementById('petsa')?.value||'';
    // Demographic fields (Section 1) — validated as required above but
    // previously never left this function, so api/feedback_submit.php
    // never received them. gender_pref/relihiyon carry their "_other"
    // free-text companion the same way serbisyo/serbisyo_other already do.
    payload.age=document.getElementById('edad')?.value||'';
    payload.sex=document.getElementById('sex')?.value||'';
    payload.gender_preference=document.getElementById('gender_pref')?.value||'';
    payload.gender_preference_other=document.getElementById('gender_pref_other')?.value||'';
    payload.religion=document.getElementById('relihiyon')?.value||'';
    payload.religion_other=document.getElementById('relihiyonOther')?.value||'';
    payload.visit_frequency=document.getElementById('freq')?.value||'';
    // Education ("educ") — same previously-missing-from-payload bug as the
    // five fields above; no "other" option on this select, so no companion
    // free-text field is needed.
    payload.education_level=document.getElementById('educ')?.value||'';
    payload.facility=document.getElementById('facility')?.value||'';
    payload.cc1=document.querySelector('[name="cc1"]:checked')?.value||'';
    payload.cc2=document.querySelector('[name="cc2"]:checked')?.value||'';
    payload.cc3=document.querySelector('[name="cc3"]:checked')?.value||'';
    payload.feedback=document.getElementById('commentText')?.value?.trim()||'';
    // Retain the established payload key for older endpoint/reporting clients.
    // The restored form has one combined Comments / Suggestions field, stored
    // through the canonical feedback field as it was before the split layout.
    payload.suggestions='';
    const staffRecognitionValue=document.getElementById('staffToThank')?.value?.trim()||'';
    payload.staff_recognition=staffRecognitionValue;
    payload.recognition=staffRecognitionValue;
    payload.appreciation=staffRecognitionValue;
    payload.consent=document.getElementById('consentChk')?.checked?1:0;

    const ratings={};
    document.querySelectorAll('.r-radio').forEach(r=>{
      if(r.checked){
        ratings[r.name]=r.value;
      }
    });
    payload.ratings=ratings;
    // Tell api/feedback_submit.php exactly which rating keys this form
    // requires (it already supports this field, api/feedback_submit.php:274-276) —
    // its own key-prefix inference doesn't recognize this form's key names,
    // so without this the server only checked "ratings is non-empty".
    payload.required_rating_keys=requiredRatingGroups;

    const resp=await fetch('/Feedback/api/feedback_submit.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    const data=await resp.json();

    btn.classList.remove('loading');
    btn.disabled=false;
    btn.querySelector('.lbl').textContent=FB2_I18N.msgs.submitLabel;

    if(data && data.success && data.data?.referenceId){
      const ref=data.data.referenceId;
      window.location.assign(`/Feedback/feedback_submitted.php?ref=${encodeURIComponent(ref)}`);
      return;
    }

    const msg=(data && data.message)?data.message:'Unable to submit form';
    toast(msg,'error');
  }catch(ex){
    btn.classList.remove('loading');
    btn.disabled=false;
    btn.querySelector('.lbl').textContent=FB2_I18N.msgs.submitLabel;
    console.error('submit error',ex);
    toast(FB2_I18N.msgs.networkError,'error');
  }
}

const surveyForm=document.getElementById('mainForm');
if(surveyForm){
  surveyForm.addEventListener('submit', submitForm);
}

/* ═══════════════════════════════════════
   RESET
═══════════════════════════════════════ */
function resetSurvey(){
  const form=document.getElementById('mainForm');
  if(form) form.reset();
  renderServiceSubtypes();

  clearAllValidation();
  document.querySelectorAll('.pill-opt').forEach(el=>el.classList.remove('selected'));
  document.querySelectorAll('.chk-opt').forEach(el=>el.classList.remove('checked'));
  document.querySelectorAll('.other-reveal').forEach(el=>el.classList.remove('open'));
  document.querySelectorAll('[name="cc2"], [name="cc3"]').forEach(r=>{
    r.disabled=false;
    const opt=r.closest('.pill-opt');
    if(opt){
      opt.style.removeProperty('pointer-events');
      opt.style.removeProperty('opacity');
    }
  });
  document.querySelectorAll('input[id$="_other"], input[id="relihiyonOther"]').forEach(inp=>{
    inp.required=false;
    inp.value='';
  });

  const modal=document.getElementById('successModal');
  if(modal) modal.classList.remove('open');
  const prog=document.getElementById('progFill');
  if(prog) prog.style.width='0%';
  ['commentTextCount','staffToThankCount'].forEach(id=>{
    const counter=document.getElementById(id);
    if(counter) counter.textContent='0';
  });
  try{ updateCCLockState(); updateProgress(); }catch(e){}
  window.scrollTo({top:0,behavior:'smooth'});
  toast(FB2_I18N.msgs.resetDone,'info');
}

/* ═══════════════════════════════════════
   DARK MODE
═══════════════════════════════════════ */
function toggleTheme(){
  const html=document.documentElement;
  const isDark=html.dataset.theme==='dark';
  html.dataset.theme=isDark?'light':'dark';
  const btn=document.getElementById('themeToggle');
  if(btn)btn.textContent=isDark?'🌙':'☀️';
  localStorage.setItem('sfms_theme_v3',isDark?'light':'dark');
}
(()=>{
  const t=localStorage.getItem('sfms_theme_v3');
  if(t==='dark'){
    document.documentElement.dataset.theme='dark';
    const btn=document.getElementById('themeToggle');
    if(btn)btn.textContent='☀️';
  }
})();

/* ═══════════════════════════════════════
   TOAST
═══════════════════════════════════════ */
function toast(msg,type='info'){
  const icons={success:'✅',error:'❌',info:'ℹ️'};
  const el=document.createElement('div');
  el.className=`toast ${type}`;
  el.setAttribute('role','alert');
  el.setAttribute('aria-live','assertive');
  el.innerHTML=`<span class="toast-icon" aria-hidden="true">${icons[type]||'ℹ️'}</span><span>${msg}</span>`;
  const container=document.getElementById('toasts');
  if(container)container.appendChild(el);
  setTimeout(()=>{
    el.style.animation='toastOut .28s ease forwards';
    setTimeout(()=>el.remove(),280);
  },3800);
}

updateProgress();
</script>
<script src="/Feedback/assets/js/language.js?v=20260826b"></script>
</body>
</html>
