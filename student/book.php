<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('student');
$user = get_logged_user();
$db = getDB();
$studentId = $user['student_id'] ?? 0;

$error = '';

// Check active requests limit (settings)
$maxRequests = (int)get_setting('max_active_requests_per_student', 5);
$activeStmt = $db->prepare("SELECT COUNT(*) FROM appointments WHERE student_id = ? AND status IN ('pending', 'slots_proposed')");
$activeStmt->execute([$studentId]);
$currentActive = (int)$activeStmt->fetchColumn();

// Fetch staff members grouped by department
$staffStmt = $db->query("
    SELECT sp.staff_id, sp.title, sp.designation, sp.office_location, u.full_name, u.email_id, d.name as dept_name, d.department_id
    FROM staff_profiles sp
    JOIN users u ON sp.user_id = u.user_id
    LEFT JOIN departments d ON u.department_id = d.department_id
    WHERE u.status = 'active'
    ORDER BY d.name ASC, u.full_name ASC
");
$allStaff = $staffStmt->fetchAll();

// Group staff by department name
$staffByDept = [];
foreach ($allStaff as $s) {
    $dept = $s['dept_name'] ?: 'General Faculty';
    $staffByDept[$dept][] = $s;
}

// Fetch staff unavailability records
$unavailStmt = $db->query("
    SELECT su.*, sp.staff_id, u.full_name, sp.title
    FROM staff_unavailability su
    JOIN staff_profiles sp ON su.staff_id = sp.staff_id
    JOIN users u ON sp.user_id = u.user_id
    WHERE su.end_date >= CURRENT_DATE
    ORDER BY su.start_date ASC
");
$unavailabilityList = $unavailStmt->fetchAll();

// Handle Form Submission
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $staff_id = (int)($_POST['staff_id'] ?? 0);
    $title = trim($_POST['title'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $is_urgent = isset($_POST['is_urgent']) ? 1 : 0;

    if ($currentActive >= $maxRequests) {
        $error = "You have reached the maximum allowed active requests ($maxRequests). Please wait for staff to process your open requests.";
    } elseif (empty($staff_id)) {
        $error = "Please select a staff member.";
    } elseif (empty($title)) {
        $error = "Please provide the reason / subject for this appointment.";
    } elseif (empty($description)) {
        $error = "Please provide detailed description regarding what you need to discuss.";
    } else {
        try {
            $stmt = $db->prepare("
                INSERT INTO appointments (student_id, staff_id, title, description, is_urgent, status, created_at)
                VALUES (?, ?, ?, ?, ?, 'pending', " . (getDBDriver() === 'mysql' ? "NOW()" : "datetime('now')") . ")
            ");
            $stmt->execute([$studentId, $staff_id, $title, $description, $is_urgent]);
            $apptId = $db->lastInsertId();

            // Find staff user_id for notification
            $stUserStmt = $db->prepare("SELECT user_id, full_name, email_id FROM staff_profiles sp JOIN users u ON sp.user_id = u.user_id WHERE sp.staff_id = ?");
            $stUserStmt->execute([$staff_id]);
            $staffUser = $stUserStmt->fetch();

            if ($staffUser) {
                $urgencyText = $is_urgent ? '[URGENT] ' : '';
                $notifMsg = "{$urgencyText}New appointment requested by {$user['full_name']} ({$user['profile']['registration_no']}): {$title}";
                send_notification($staffUser['user_id'], 'new_request', $notifMsg, $apptId);

                // Log email to staff
                log_email(
                    $staffUser['user_id'], 
                    'new_appointment_request', 
                    "{$urgencyText}CampusMeet: New Appointment Request from {$user['full_name']}", 
                    $apptId
                );
            }

            log_audit($user['user_id'], "Submitted appointment request ID #{$apptId}", 'appointments', $apptId);

            set_flash('success', '<i class="bi bi-check-circle-fill me-1"></i> Your appointment request has been submitted to the staff member successfully!');
            header("Location: " . BASE_URL . "/student/appointments.php");
            exit;
        } catch (Exception $e) {
            $error = "Failed to submit request: " . $e->getMessage();
        }
    }
}

$page_title = 'Book Appointment';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="card card-custom p-4 shadow-sm border-0">
            <div class="d-flex align-items-center mb-4">
                <div class="stat-icon primary me-3">
                    <i class="bi bi-calendar-plus-fill"></i>
                </div>
                <div>
                    <h4 class="fw-bold text-navy mb-0">Book Academic Appointment</h4>
                    <small class="text-muted">Select an academic staff member and specify your requirements</small>
                </div>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($currentActive >= $maxRequests): ?>
                <div class="alert alert-warning mb-4">
                    <i class="bi bi-info-circle-fill me-2"></i> You currently have <?= $currentActive ?> active requests pending response (system maximum is <?= $maxRequests ?>).
                </div>
            <?php endif; ?>

            <form method="POST">
                <!-- Staff Selection Grouped by Faculty (PDF Page 6, 9) -->
                <div class="mb-4">
                    <label class="form-label fw-semibold text-navy">
                        Select Academic Staff Member <span class="text-danger">*</span>
                    </label>
                    <select name="staff_id" id="staffSelect" class="form-select form-select-lg" required>
                        <option value="">-- Choose Staff Member (Grouped by Faculty) --</option>
                        <?php foreach ($staffByDept as $deptName => $staffList): ?>
                            <optgroup label="🏛️ <?= htmlspecialchars($deptName) ?>">
                                <?php foreach ($staffList as $s): ?>
                                    <option value="<?= $s['staff_id'] ?>">
                                        <?= htmlspecialchars($s['title'] . ' ' . $s['full_name']) ?> — <?= htmlspecialchars($s['designation']) ?> (<?= htmlspecialchars($s['office_location']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text small">Staff members are arranged by their university faculties.</div>
                </div>

                <!-- Unavailable Dates Notice Box (PDF Page 6, 9: "Staff unavailable dates are not selectable") -->
                <?php if (!empty($unavailabilityList)): ?>
                    <div class="card bg-light border-0 mb-4 p-3 rounded-3">
                        <div class="fw-semibold text-navy small mb-2">
                            <i class="bi bi-calendar-x text-warning me-1"></i> Current Staff Unavailable Dates / Scheduled Leave:
                        </div>
                        <ul class="list-unstyled mb-0 small text-muted">
                            <?php foreach ($unavailabilityList as $un): ?>
                                <li class="mb-1">
                                    <i class="bi bi-dot text-danger"></i>
                                    <strong><?= htmlspecialchars($un['title'] . ' ' . $un['full_name']) ?>:</strong> 
                                    <?= format_date($un['start_date']) ?> to <?= format_date($un['end_date']) ?>
                                    <span class="badge bg-secondary-subtle text-secondary ms-1"><?= htmlspecialchars($un['reason']) ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <!-- Reason / Title -->
                <div class="mb-3">
                    <label class="form-label fw-semibold text-navy">
                        Reason for Appointment <span class="text-danger">*</span>
                    </label>
                    <input type="text" name="title" class="form-control" placeholder="e.g. FYP Proposal Endorsement, Academic Counseling, Exam Clarification" required maxlength="255">
                </div>

                <!-- Detailed Description -->
                <div class="mb-3">
                    <label class="form-label fw-semibold text-navy">
                        Detailed Description & Background <span class="text-danger">*</span>
                    </label>
                    <textarea name="description" class="form-control" rows="4" placeholder="Explain the context of your request, documents you will bring, or specific questions so the lecturer can prepare." required></textarea>
                </div>

                <!-- Urgency Checkbox (PDF Page 4, 6, 9: "optional urgent High Priority checkbox") -->
                <div class="card border-danger-subtle bg-danger-subtle bg-opacity-10 p-3 mb-4 rounded-3">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_urgent" id="urgentCheck" value="1">
                        <label class="form-check-label fw-bold text-danger" for="urgentCheck">
                            <i class="bi bi-fire me-1"></i> Mark as High Priority / Urgent
                        </label>
                    </div>
                    <small class="text-muted ms-4">
                        Check this only if you need immediate signature or counseling for an impending submission deadline. Urgent requests are highlighted with top priority on the staff dashboard.
                    </small>
                </div>

                <!-- Working hours notice -->
                <div class="p-3 bg-light rounded-3 mb-4 text-muted small d-flex align-items-center">
                    <i class="bi bi-clock-history text-primary fs-5 me-2"></i>
                    <div>
                        <strong>Standard University Working Hours:</strong> 08:00 AM to 05:00 PM, Monday to Friday. Staff will offer at least three suitable time slots with mandatory buffer times between slots.
                    </div>
                </div>

                <!-- Submit Button -->
                <div class="d-flex justify-content-between align-items-center">
                    <a href="<?= BASE_URL ?>/student/dashboard.php" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary-custom px-4 py-2" <?= ($currentActive >= $maxRequests) ? 'disabled' : '' ?>>
                        <i class="bi bi-send-fill me-1"></i> Submit Appointment Request
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
