<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../config/notification-tables.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'success', 'count' => 0, 'breakdown' => []]);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'success', 'count' => 0, 'breakdown' => []]);
}

rdomms_ensure_notification_tables($conn);

$currentUser = getAuthenticatedUser($session);
if (!$currentUser) {
    sendJSONResponse(['status' => 'success', 'count' => 0, 'breakdown' => []]);
}

$userId = (int) ($currentUser['id'] ?? 0);
$role = normalizeRoleName($currentUser['role'] ?? '');
$scopeUserId = getDataScopeUserId($currentUser);
$canApprove = in_array($role, ['admin', 'head_of_organization_center', 'head_of_department', 'human_resource_manager'], true);
$alertScope = rdomms_system_alert_scope_sql($currentUser);

$breakdown = [
    'alerts' => 0,
    'messages' => 0,
    'approvals' => 0,
    'user_registrations' => 0,
];

$alertStmt = $conn->prepare(
    "SELECT COUNT(*) AS count FROM notifications
     WHERE {$alertScope['where']}
       AND is_read = 0"
);
$alertStmt->execute($alertScope['params']);
$breakdown['alerts'] = (int) (($alertStmt->fetch()['count'] ?? 0));

try {
    $messageStmt = $conn->prepare(
        "SELECT COUNT(*) AS count
         FROM notification_messages nm
         INNER JOIN notification_threads nt ON nt.thread_id = nm.thread_id
         WHERE nt.owner_user_id = ?
           AND nm.sender = 'them'
           AND nm.is_read = 0"
    );
    $messageStmt->execute([$userId]);
    $breakdown['messages'] = (int) (($messageStmt->fetch()['count'] ?? 0));
} catch (Throwable $e) {
    error_log('Unread message count skipped: ' . $e->getMessage());
}

if ($canApprove) {
    $approvalSql = "SELECT COUNT(*) AS count FROM approval_requests WHERE status = 'pending'";
    $approvalParams = [];
    appendDataScopeFilter($approvalSql, $approvalParams, 'created_by', $scopeUserId);
    $approvalStmt = $conn->prepare($approvalSql);
    $approvalStmt->execute($approvalParams);
    $breakdown['approvals'] = (int) (($approvalStmt->fetch()['count'] ?? 0));

    if (in_array($role, ['admin', 'head_of_organization_center', 'human_resource_manager'], true)) {
        $userStmt = $conn->query("SELECT COUNT(*) AS count FROM users WHERE status = 'pending'");
        $breakdown['user_registrations'] = (int) (($userStmt->fetch()['count'] ?? 0));
    }
}

$count = array_sum($breakdown);

sendJSONResponse([
    'status' => 'success',
    'count' => $count,
    'breakdown' => $breakdown,
]);
