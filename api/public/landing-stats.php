<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';

rdomms_send_security_headers();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');
header('Cache-Control: public, max-age=30');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

try {
    $beneficiaryStmt = $conn->query('SELECT COUNT(*) AS total FROM beneficiaries');
    $beneficiaryRow = $beneficiaryStmt ? $beneficiaryStmt->fetch() : [];
    $totalBeneficiaries = (int) ($beneficiaryRow['total'] ?? 0);

    $projectStmt = $conn->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_count,
                COALESCE(ROUND(AVG(progress)), 0) AS avg_progress
         FROM projects"
    );
    $projectRow = $projectStmt ? $projectStmt->fetch() : [];
    $totalProjects = (int) ($projectRow['total'] ?? 0);
    $completedProjects = (int) ($projectRow['completed_count'] ?? 0);
    $avgProgress = (int) ($projectRow['avg_progress'] ?? 0);

    $efficiencyRate = 98;

    sendJSONResponse([
        'status' => 'success',
        'data' => [
            'total_beneficiaries' => $totalBeneficiaries,
            'total_projects' => $totalProjects,
            'completed_projects' => $completedProjects,
            'avg_progress' => $avgProgress,
            'efficiency_rate' => $efficiencyRate,
            'synced_at' => date('c'),
        ],
    ]);
} catch (Throwable $e) {
    error_log('landing-stats: ' . $e->getMessage());
    sendJSONResponse(['status' => 'error', 'message' => 'Unable to load public statistics'], 500);
}
