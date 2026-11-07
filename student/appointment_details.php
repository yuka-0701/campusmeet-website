<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('student');
$user = get_logged_user();
$db = getDB();
$studentId = $user['student_id'] ?? 0;

$apptId = (int)($_GET['id'] ?? 0);

// Fetch appointment details
$stmt = $db->prepare("
    SELECT a.*, u.full_name as staff_name, u.email_id as staff_email, sp.title as staff_title, 
           sp.designation, sp.office_location, d.name as dept_name, sp.user_id as staff_user_id
    FROM appointments a
    JOIN staff_profiles sp ON a.staff_id = sp.staff_id
    JOIN users u ON sp.user_id = u.user_id
    LEFT JOIN departments d ON u.department_id = d.department_id
    WHERE a.appointment_id = ? AND a.student_id = ?
");
$stmt->execute([$apptId, $studentId]);
$appt = $stmt->fetch();

if (!$appt) {
    header("Location: " . BASE_URL . "/error.php?code=404");
    exit;
}

$error = '';

// Handle Slot Confirmation Submission (PDF Page 6, 9)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm_slot') {
    $selectedSlotId = (int)($_POST['selected_slot_id'] ?? 0);

    if (empty($selectedSlotId)) {
        $error = "Please select one of the proposed time slots to confirm your appointment.";
    } else {
        // Verify slot belongs to this appointment
        $slotCheck = $db->prepare("SELECT * FROM appointment_slots WHERE slot_id = ? AND appointment_id = ?");
        $slotCheck->execute([$selectedSlotId, $apptId]);
        $chosenSlot = $slotCheck->fetch();

        if ($chosenSlot) {
            // Reset other slots for this appointment, set selected
            $db->prepare("UPDATE appointment_slots SET is_selected = 0 WHERE appointment_id = ?")->execute([$apptId]);
            $db->prepare("UPDATE appointment_slots SET is_selected = 1 WHERE slot_id = ?")->execute([$selectedSlotId]);

            // Update appointment status to confirmed
            $upd = $db->prepare("UPDATE appointments SET status = 'confirmed', selected_slot_id = ? WHERE appointment_id = ?");
            $upd->execute([$selectedSlotId, $apptId]);

            // Notify staff member
            $slotFormatted = format_datetime($chosenSlot['slot_start']);
            $notifMsg = "Student {$user['full_name']} confirmed appointment slot for: {$slotFormatted} ({$appt['title']})";
            send_notification($appt['staff_user_id'], 'confirmed', $notifMsg, $apptId);

            // Log email to staff and student
            log_email($appt['staff_user_id'], 'appointment_confirmed', "CampusMeet: Appointment Confirmed with {$user['full_name']}", $apptId);
            log_email($user['user_id'], 'appointment_confirmed', "CampusMeet: Appointment Confirmed with {$appt['staff_title']} {$appt['staff_name']}", $apptId);

            log_audit($user['user_id'], "Confirmed slot #{$selectedSlotId} for appointment #{$apptId}", 'appointments', $apptId);

            set_flash('success', '<i class="bi bi-check2-circle me-1"></i> Your appointment has been successfully confirmed for ' . $slotFormatted . '!');
            header("Location: " . BASE_URL . "/student/appointment_details.php?id=" . $apptId);
            exit;
        } else {
            $error = "Selected slot is invalid.";
        }
    }
}

// Fetch proposed slots for this appointment
$slotsStmt = $db->prepare("SELECT * FROM appointment_slots WHERE appointment_id = ? ORDER BY slot_start ASC");
$slotsStmt->execute([$apptId]);
$slots = $slotsStmt->fetchAll();

// Find selected slot if confirmed
$selectedSlot = null;
if (!empty($appt['selected_slot_id'])) {
    foreach ($slots as $s) {
        if ($s['slot_id'] == $appt['selected_slot_id']) {
            $selectedSlot = $s;
            break;
        }
    }
}

$page_title = 'Appointment Details #' . $appt['appointment_id'];
require_once __DIR__ . '/../includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-9">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <a href="<?= BASE_URL ?>/student/appointments.php" class="text-decoration-none small text-muted">
                    <i class="bi bi-arrow-left me-1"></i> Back to Appointments
                </a>
                <h3 class="fw-bold text-navy mt-1 mb-0">Appointment #<?= $appt['appointment_id'] ?>: <?= htmlspecialchars($appt['title']) ?></h3>
            </div>
            <div>
                <?= get_status_badge($appt['status'], $appt['is_urgent']) ?>
            </div>
        </div>

        <?php if (!empty($error)): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-4" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i><?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Confirmed Meeting Banner (if confirmed) -->
        <?php if ($appt['status'] === 'confirmed' && $selectedSlot): ?>
            <div class="card border-success shadow-sm mb-4 bg-success-subtle bg-opacity-25">
                <div class="card-body p-4 text-center">
                    <div class="display-6 text-success mb-2"><i class="bi bi-calendar2-check-fill"></i></div>
                    <h4 class="fw-bold text-success mb-1">Appointment Confirmed!</h4>
                    <p class="text-muted mb-3">Both you and the lecturer have agreed on the meeting schedule below.</p>
                    
                    <div class="p-3 bg-white rounded-3 shadow-2xs d-inline-block text-start mb-3 border">
                        <div class="row g-3">
                            <div class="col-auto">
                                <div class="stat-icon success">
                                    <i class="bi bi-clock-history"></i>
                                </div>
                            </div>
                            <div class="col">
                                <div class="fw-bold text-navy fs-5"><?= format_datetime($selectedSlot['slot_start']) ?> – <?= format_time($selectedSlot['slot_end']) ?></div>
                                <div class="text-muted"><i class="bi bi-geo-alt-fill text-danger me-1"></i> Location: <strong><?= htmlspecialchars($appt['location'] ?? $appt['office_location']) ?></strong></div>
                            </div>
                        </div>
                    </div>

                    <div>
                        <?php 
                        $gCalUrl = generate_google_calendar_url(
                            $appt['title'], 
                            $selectedSlot['slot_start'], 
                            $selectedSlot['slot_end'], 
                            "Meeting with " . $appt['staff_title'] . ' ' . $appt['staff_name'] . "\n" . $appt['description'], 
                            $appt['location'] ?? $appt['office_location']
                        );
                        ?>
                        <a href="<?= htmlspecialchars($gCalUrl) ?>" target="_blank" class="btn btn-success px-4 py-2 shadow-sm">
                            <i class="bi bi-google me-2"></i> Add to My Google Calendar
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Slot Selection Section (PDF Page 6, 9: "View the proposed time slots, then select one") -->
        <?php if (in_array($appt['status'], ['slots_proposed', 'postponed']) && !empty($slots)): ?>
            <div class="card card-custom mb-4 border-primary">
                <div class="card-header bg-primary text-white py-3">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-calendar2-range me-2"></i>Staff Proposed Meeting Slots (Please Choose One)</h5>
                </div>
                <div class="card-body p-4">
                    <p class="text-muted small mb-3">
                        The lecturer has reviewed your request and proposed the following time options. Meeting location is set to: 
                        <strong class="text-navy"><?= htmlspecialchars($appt['location'] ?? $appt['office_location']) ?></strong>.
                    </p>

                    <form method="POST">
                        <input type="hidden" name="action" value="confirm_slot">
                        <div class="row g-3 mb-4">
                            <?php foreach ($slots as $idx => $s): ?>
                                <div class="col-md-4">
                                    <div class="slot-card h-100 <?= ($s['is_selected'] == 1) ? 'selected' : '' ?>">
                                        <div class="form-check">
                                            <input class="form-check-input" type="radio" name="selected_slot_id" id="slotRadio<?= $s['slot_id'] ?>" value="<?= $s['slot_id'] ?>" <?= ($s['is_selected'] == 1 || $idx === 0) ? 'checked' : '' ?>>
                                            <label class="form-check-label w-100 cursor-pointer" for="slotRadio<?= $s['slot_id'] ?>">
                                                <span class="badge bg-primary-subtle text-primary mb-2">Option 0<?= $idx + 1 ?></span>
                                                <div class="fw-bold text-navy fs-6 mb-1">
                                                    <?= format_date($s['slot_start']) ?>
                                                </div>
                                                <div class="text-secondary small mb-2">
                                                    <i class="bi bi-clock me-1"></i><?= format_time($s['slot_start']) ?> – <?= format_time($s['slot_end']) ?>
                                                </div>
                                                <div class="small text-muted">
                                                    <i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($appt['location'] ?? 'Office') ?>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary-custom py-2 fs-6 fw-bold">
                                <i class="bi bi-check2-circle me-2"></i> Confirm Selected Meeting Slot
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Appointment Overview Card -->
        <div class="card card-custom mb-4">
            <div class="card-header bg-white py-3">
                <h5 class="card-title mb-0 fw-bold text-navy"><i class="bi bi-info-circle text-primary me-2"></i>Request Overview</h5>
            </div>
            <div class="card-body">
                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="text-muted small">Academic Staff Member</div>
                        <div class="fw-bold text-navy fs-6"><?= htmlspecialchars($appt['staff_title'] . ' ' . $appt['staff_name']) ?></div>
                        <div class="small text-muted"><?= htmlspecialchars($appt['designation']) ?></div>
                        <div class="small text-secondary"><?= htmlspecialchars($appt['staff_email']) ?></div>
                    </div>

                    <div class="col-md-6">
                        <div class="text-muted small">Faculty & Office Cabin</div>
                        <div class="fw-bold text-navy"><?= htmlspecialchars($appt['dept_name']) ?></div>
                        <div class="small text-muted"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($appt['office_location']) ?></div>
                    </div>
                </div>

                <hr>

                <div class="mb-3">
                    <div class="text-muted small">Reason / Subject</div>
                    <div class="fw-bold text-navy"><?= htmlspecialchars($appt['title']) ?></div>
                </div>

                <div class="mb-3">
                    <div class="text-muted small">Detailed Student Description</div>
                    <div class="p-3 bg-light rounded text-secondary" style="white-space: pre-wrap;"><?= htmlspecialchars($appt['description']) ?></div>
                </div>

                <div class="row g-2 small text-muted">
                    <div class="col-sm-6">
                        <i class="bi bi-calendar-event me-1"></i> Created on: <?= format_datetime($appt['created_at']) ?>
                    </div>
                    <div class="col-sm-6 text-sm-end">
                        <i class="bi bi-arrow-repeat me-1"></i> Last updated: <?= format_datetime($appt['updated_at']) ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
