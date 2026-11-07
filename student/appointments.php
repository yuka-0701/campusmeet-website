<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

require_role('student');
$user = get_logged_user();
$db = getDB();
$studentId = $user['student_id'] ?? 0;

// Handle Cancellation action (PDF Page 6, 9)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && isset($_POST['action']) && $_POST['action'] === 'cancel') {
    $apptId = (int)($_POST['appointment_id'] ?? 0);
    
    // Check ownership
    $stmt = $db->prepare("SELECT a.*, sp.user_id as staff_user_id, u.full_name as staff_name FROM appointments a JOIN staff_profiles sp ON a.staff_id = sp.staff_id JOIN users u ON sp.user_id = u.user_id WHERE a.appointment_id = ? AND a.student_id = ?");
    $stmt->execute([$apptId, $studentId]);
    $appt = $stmt->fetch();

    if ($appt && in_array($appt['status'], ['pending', 'slots_proposed', 'confirmed'])) {
        $upd = $db->prepare("UPDATE appointments SET status = 'cancelled' WHERE appointment_id = ?");
        $upd->execute([$apptId]);

        // Notify Staff automatically
        $notifMsg = "Student {$user['full_name']} cancelled the appointment: '{$appt['title']}'";
        send_notification($appt['staff_user_id'], 'cancelled', $notifMsg, $apptId);
        log_email($appt['staff_user_id'], 'appointment_cancelled', "CampusMeet: Appointment Cancelled by Student", $apptId);

        log_audit($user['user_id'], "Cancelled appointment ID #{$apptId}", 'appointments', $apptId);
        set_flash('success', 'Appointment has been cancelled successfully.');
        header("Location: " . BASE_URL . "/student/appointments.php");
        exit;
    }
}

// Filter by status tab
$currentFilter = $_GET['status'] ?? 'all';

$sql = "
    SELECT a.*, u.full_name as staff_name, sp.title as staff_title, sp.designation, sp.office_location,
           d.name as dept_name, s.slot_start, s.slot_end
    FROM appointments a
    JOIN staff_profiles sp ON a.staff_id = sp.staff_id
    JOIN users u ON sp.user_id = u.user_id
    LEFT JOIN departments d ON u.department_id = d.department_id
    LEFT JOIN appointment_slots s ON a.selected_slot_id = s.slot_id
    WHERE a.student_id = ?
";

$params = [$studentId];

if ($currentFilter === 'pending') {
    $sql .= " AND a.status = 'pending'";
} elseif ($currentFilter === 'slots_proposed') {
    $sql .= " AND a.status IN ('slots_proposed', 'postponed')";
} elseif ($currentFilter === 'confirmed') {
    $sql .= " AND a.status = 'confirmed'";
} elseif ($currentFilter === 'history') {
    $sql .= " AND a.status IN ('completed', 'cancelled')";
}

$sql .= " ORDER BY a.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$appointments = $stmt->fetchAll();

$page_title = 'My Appointments';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4">
    <div>
        <h3 class="fw-bold text-navy mb-1"><i class="bi bi-calendar2-check text-primary me-2"></i>My Appointments</h3>
        <p class="text-muted small mb-0">Track, manage and select slots for your academic appointments</p>
    </div>
    <div class="mt-2 mt-sm-0">
        <a href="<?= BASE_URL ?>/student/book.php" class="btn btn-primary-custom px-3 py-2">
            <i class="bi bi-plus-lg me-1"></i> New Request
        </a>
    </div>
</div>

<!-- Search & Status Filter Tabs -->
<div class="card card-custom mb-4 p-3">
    <div class="row g-3 align-items-center">
        <div class="col-md-5">
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                <input type="text" id="tableSearchInput" class="form-control" placeholder="Search by staff, subject, or faculty...">
            </div>
        </div>
        <div class="col-md-7 text-md-end">
            <div class="btn-group flex-wrap" role="group">
                <a href="<?= BASE_URL ?>/student/appointments.php?status=all" class="btn btn-sm <?= ($currentFilter === 'all') ? 'btn-primary' : 'btn-outline-secondary' ?>">All</a>
                <a href="<?= BASE_URL ?>/student/appointments.php?status=pending" class="btn btn-sm <?= ($currentFilter === 'pending') ? 'btn-primary' : 'btn-outline-secondary' ?>">Pending</a>
                <a href="<?= BASE_URL ?>/student/appointments.php?status=slots_proposed" class="btn btn-sm <?= ($currentFilter === 'slots_proposed') ? 'btn-info text-dark' : 'btn-outline-secondary' ?>">Slots Offered</a>
                <a href="<?= BASE_URL ?>/student/appointments.php?status=confirmed" class="btn btn-sm <?= ($currentFilter === 'confirmed') ? 'btn-success' : 'btn-outline-secondary' ?>">Confirmed</a>
                <a href="<?= BASE_URL ?>/student/appointments.php?status=history" class="btn btn-sm <?= ($currentFilter === 'history') ? 'btn-secondary' : 'btn-outline-secondary' ?>">History</a>
            </div>
        </div>
    </div>
</div>

<!-- Appointments List Table -->
<div class="card card-custom">
    <div class="card-body p-0">
        <?php if (empty($appointments)): ?>
            <div class="p-5 text-center text-muted">
                <i class="bi bi-calendar-x fs-1 text-secondary mb-2"></i>
                <h5 class="fw-bold text-navy">No Appointments Found</h5>
                <p class="small text-muted">There are no appointments matching the selected filter.</p>
                <a href="<?= BASE_URL ?>/student/book.php" class="btn btn-sm btn-primary-custom mt-2">Book an Appointment</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-custom table-hover searchable-table mb-0">
                    <thead>
                        <tr>
                            <th>Academic Staff</th>
                            <th>Subject & Details</th>
                            <th>Status</th>
                            <th>Meeting Time & Place</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $a): ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-navy"><?= htmlspecialchars($a['staff_title'] . ' ' . $a['staff_name']) ?></div>
                                    <div class="small text-muted"><?= htmlspecialchars($a['designation']) ?></div>
                                    <span class="badge bg-light text-secondary border" style="font-size: 0.72rem;"><?= htmlspecialchars($a['dept_name']) ?></span>
                                </td>
                                <td>
                                    <div class="fw-semibold text-navy">
                                        <?= htmlspecialchars($a['title']) ?>
                                    </div>
                                    <div class="small text-muted"><?= htmlspecialchars(substr($a['description'], 0, 75)) ?>...</div>
                                    <small class="text-muted" style="font-size: 0.75rem;">Submitted: <?= format_datetime($a['created_at']) ?></small>
                                </td>
                                <td>
                                    <?= get_status_badge($a['status'], $a['is_urgent']) ?>
                                </td>
                                <td>
                                    <?php if ($a['status'] === 'confirmed' && !empty($a['slot_start'])): ?>
                                        <div class="fw-bold text-success small"><i class="bi bi-calendar-check me-1"></i><?= format_datetime($a['slot_start']) ?></div>
                                        <div class="text-muted small"><i class="bi bi-geo-alt me-1"></i><?= htmlspecialchars($a['location'] ?? $a['office_location']) ?></div>
                                    <?php elseif ($a['status'] === 'slots_proposed' || $a['status'] === 'postponed'): ?>
                                        <span class="badge bg-info-subtle text-info border border-info-subtle">
                                            <i class="bi bi-hand-index-thumb me-1"></i> 3 Slots Ready for Selection
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">Awaiting Staff Review</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-1 flex-wrap">
                                        <?php if ($a['status'] === 'slots_proposed' || $a['status'] === 'postponed'): ?>
                                            <a href="<?= BASE_URL ?>/student/appointment_details.php?id=<?= $a['appointment_id'] ?>" class="btn btn-sm btn-primary">
                                                <i class="bi bi-check2-circle me-1"></i> Choose Slot
                                            </a>
                                        <?php else: ?>
                                            <a href="<?= BASE_URL ?>/student/appointment_details.php?id=<?= $a['appointment_id'] ?>" class="btn btn-sm btn-outline-secondary">
                                                View
                                            </a>
                                        <?php endif; ?>

                                        <!-- Google Calendar Link for Confirmed Meetings (PDF Page 7, 8, 15) -->
                                        <?php if ($a['status'] === 'confirmed' && !empty($a['slot_start']) && !empty($a['slot_end'])): ?>
                                            <?php 
                                            $gCalUrl = generate_google_calendar_url(
                                                $a['title'], 
                                                $a['slot_start'], 
                                                $a['slot_end'], 
                                                "Meeting with " . $a['staff_title'] . ' ' . $a['staff_name'] . "\n" . $a['description'], 
                                                $a['location'] ?? $a['office_location']
                                            );
                                            ?>
                                            <a href="<?= htmlspecialchars($gCalUrl) ?>" target="_blank" class="btn btn-sm btn-outline-success" title="Add to Google Calendar">
                                                <i class="bi bi-google"></i>
                                            </a>
                                        <?php endif; ?>

                                        <!-- Cancel Modal Trigger -->
                                        <?php if (in_array($a['status'], ['pending', 'slots_proposed', 'confirmed'])): ?>
                                            <button type="button" class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#cancelModal<?= $a['appointment_id'] ?>">
                                                <i class="bi bi-x-lg"></i>
                                            </button>

                                            <!-- Cancel Modal -->
                                            <div class="modal fade" id="cancelModal<?= $a['appointment_id'] ?>" tabindex="-1" aria-hidden="true">
                                                <div class="modal-dialog modal-dialog-centered">
                                                    <div class="modal-content text-start">
                                                        <div class="modal-header border-0 pb-0">
                                                            <h5 class="modal-title fw-bold text-navy">Cancel Appointment?</h5>
                                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                        </div>
                                                        <div class="modal-body">
                                                            Are you sure you want to cancel your appointment with <strong><?= htmlspecialchars($a['staff_title'] . ' ' . $a['staff_name']) ?></strong> for <em>"<?= htmlspecialchars($a['title']) ?>"</em>?
                                                            <p class="text-danger small mt-2 mb-0">The staff member will be automatically notified.</p>
                                                        </div>
                                                        <div class="modal-footer border-0 pt-0">
                                                            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep Appointment</button>
                                                            <form method="POST">
                                                                <input type="hidden" name="action" value="cancel">
                                                                <input type="hidden" name="appointment_id" value="<?= $a['appointment_id'] ?>">
                                                                <button type="submit" class="btn btn-danger">Confirm Cancellation</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
