<?php
// config/db.php

require_once __DIR__ . '/env.php';
rdomms_load_env();
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/encryption.php';

if (!defined('RDOMMS_SKIP_HTTPS_REDIRECT')) {
    rdomms_force_https_if_configured();
}

if (!defined('RDOMMS_SKIP_WAF')) {
    require_once __DIR__ . '/waf.php';
    rdomms_waf_protect();
}

class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    private $conn;

    public function __construct() {
        $this->host = rdomms_env('DB_HOST', 'localhost') ?: 'localhost';
        $this->db_name = rdomms_env('DB_NAME', 'rdomms_db') ?: 'rdomms_db';
        $this->username = rdomms_env('DB_USER', 'root') ?: 'root';
        $this->password = rdomms_env('DB_PASSWORD', '') ?? '';
    }

    public function getConnection() {
        $this->conn = null;

        $dsn = "mysql:host={$this->host};dbname={$this->db_name};charset=utf8mb4";
        $credentialSets = $this->buildCredentialSets();

        foreach ($credentialSets as [$username, $password]) {
            try {
                $this->conn = new PDO($dsn, $username, $password);
                $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $this->conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                return $this->conn;
            } catch (PDOException $credentialException) {
                $this->conn = null;
            }
        }

        error_log('Database connection error: unable to connect with configured credentials');
        return $this->conn;
    }

    private function buildCredentialSets(): array
    {
        $sets = [];
        $add = static function (array &$target, string $user, string $pass): void {
            $key = $user . "\0" . $pass;
            if (!isset($target[$key])) {
                $target[$key] = [$user, $pass];
            }
        };

        $bucket = [];
        $add($bucket, $this->username, $this->password);

        $fallback = rdomms_env('DB_PASSWORD_FALLBACK');
        if ($fallback !== null && $fallback !== '') {
            $add($bucket, $this->username, $fallback);
        }

        // Common local WAMP default when .env password is not set yet.
        if ($this->password !== '') {
            $add($bucket, $this->username, '');
        }

        return array_values($bucket);
    }
}

function generateUUID() {
    return uniqid('', true) . '_' . bin2hex(random_bytes(4));
}

function sendJSONResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function rdomms_verify_password(string $plain, string $stored, bool &$needsRehash = false): bool
{
    $needsRehash = false;
    if ($stored === '') {
        return false;
    }

    if (password_verify($plain, $stored)) {
        if (password_needs_rehash($stored, PASSWORD_DEFAULT)) {
            $needsRehash = true;
        }
        return true;
    }

    $info = password_get_info($stored);
    if (($info['algo'] ?? 0) === 0 && hash_equals($stored, $plain)) {
        $needsRehash = true;
        return true;
    }

    return false;
}

function ensureSessionStarted() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    rdomms_configure_secure_session();
    session_start();
    rdomms_ensure_csrf_token();
}

function verifySession(bool $touch = false) {
    ensureSessionStarted();

    if (empty($_SESSION['user_id']) || empty($_SESSION['session_token'])) {
        return null;
    }

    $db = new Database();
    $conn = $db->getConnection();
    if (!$conn) {
        return null;
    }

    require_once __DIR__ . '/session-manager.php';
    $session = rdomms_fetch_active_session($conn, (string) $_SESSION['session_token'], $touch);

    if (!$session) {
        unset($_SESSION['user_id'], $_SESSION['session_token'], $_SESSION['user_name'], $_SESSION['user_role']);
    }

    return $session ?: null;
}

function rdomms_app_root_path(): string
{
    static $root = null;
    if ($root === null) {
        $root = str_replace('\\', '/', realpath(dirname(__DIR__)) ?: dirname(__DIR__));
    }

    return rtrim($root, '/');
}

function rdomms_login_redirect_url(bool $expired = false): string
{
    $appUrl = trim((string) rdomms_env('APP_URL', ''));
    if ($appUrl !== '') {
        $url = rtrim($appUrl, '/') . '/auth/signin.php';
    } else {
        $scriptFile = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
        $scriptDir = str_replace('\\', '/', dirname($scriptFile));
        $appRoot = rdomms_app_root_path();
        $relative = '';
        $current = rtrim($scriptDir, '/');
        $root = rtrim($appRoot, '/');
        while ($current !== '' && $current !== $root) {
            $relative .= '../';
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }
            $current = $parent;
        }
        $url = $relative . 'auth/signin.php';
    }

    $params = [];
    if ($expired) {
        $params['expired'] = '1';
    }
    if ($params !== []) {
        $url .= '?' . http_build_query($params);
    }

    return $url;
}

function rdomms_require_authenticated_page(): array
{
    $session = verifySession();
    $user = getAuthenticatedUser($session);
    if (!$user) {
        header('Location: ' . rdomms_login_redirect_url(false));
        exit;
    }

    return $user;
}

function normalizeRoleName($role) {
    $normalized = strtolower(trim((string) $role));
    $aliases = [
        'mkurugenzi_mtendaji' => 'admin',
        'administrator' => 'admin',
        'system_administrator' => 'admin',
    ];

    return $aliases[$normalized] ?? $normalized;
}

/** Admin accounts bypass row-level and organization scope filters. */
function userCanViewAllData($user) {
    if (!$user) {
        return false;
    }

    return normalizeRoleName($user['role'] ?? '') === 'admin';
}

function getAllowedRolesMap() {
    return [
        'admin' => 'admin',
        'head_of_department' => 'head_of_department',
        'head_of_organization_center' => 'head_of_organization_center',
        'human_resource_manager' => 'human_resource_manager',
        'accountant' => 'accountant',
        'field_officer' => 'field_officer',
        'staff' => 'staff',
    ];
}

function isValidSystemRole($role) {
    $role = normalizeRoleName($role);
    $roles = getAllowedRolesMap();
    return isset($roles[$role]);
}

function getAuthenticatedUser($session = null) {
    if (!$session) {
        $session = verifySession();
    }

    if (!$session) {
        return null;
    }

    $userId = $session['user_id'] ?? null;
    if (empty($userId)) {
        return null;
    }

    $db = new Database();
    $conn = $db->getConnection();
    if (!$conn) {
        return null;
    }

    $stmt = $conn->prepare(
        'SELECT id, user_id, fullname, email, role, department, organization, status FROM users WHERE id = ? OR user_id = ? LIMIT 1'
    );
    $stmt->execute([$userId, $userId]);
    $user = $stmt->fetch();

    if ($user && isset($user['role'])) {
        $user['role'] = normalizeRoleName($user['role']);
    }

    return $user ?: null;
}

function requireRole(array $allowedRoles, $session = null) {
    $user = getAuthenticatedUser($session);
    if (!$user) {
        sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
    }

    $role = normalizeRoleName($user['role'] ?? '');
    $allowed = array_map('normalizeRoleName', $allowedRoles);
    if (!in_array($role, $allowed, true)) {
        sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
    }

    return $user;
}

function requireValidRole($role, array $allowedRoles = []) {
    $normalized = normalizeRoleName($role);
    if (!isValidSystemRole($normalized)) {
        sendJSONResponse(['status' => 'error', 'message' => 'Invalid role'], 400);
    }

    if ($allowedRoles) {
        $allowed = array_map('normalizeRoleName', $allowedRoles);
        if (!in_array($normalized, $allowed, true)) {
            sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
        }
    }

    return $normalized;
}

/** Returns numeric users.id for row-level filtering, or null when no filter applies. */
function getDataScopeUserId($user) {
    if (!$user || userCanViewAllData($user)) {
        return null;
    }

    return (int) ($user['id'] ?? 0);
}

/** Filter project lists by role: own projects for staff/field officers; org/dept scope for heads. */
function appendProjectListScopeFilter(&$sql, &$params, $user, ?string $scopeMode = null) {
    if (!$user || userCanViewAllData($user)) {
        return;
    }

    $role = normalizeRoleName($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);
    $organization = trim((string) ($user['organization'] ?? ''));
    $mode = normalizeDataScopeMode($user, $scopeMode);

    // Accountants approve org-wide project budgets; do not restrict to own records.
    if ($role === 'accountant') {
        return;
    }

    if ($mode === 'personal') {
        $sql .= ' AND created_by = ?';
        $params[] = $userId;
        return;
    }

    if (in_array($role, ['field_officer', 'staff'], true)) {
        $sql .= ' AND created_by = ?';
        $params[] = $userId;
        return;
    }

    if ($role === 'head_of_organization_center' && $organization !== '') {
        require_once __DIR__ . '/organization-centers.php';
        $orgKey = normalizeOrganizationKey($organization);
        $stationKeys = getUserOrganizationStationKeys($user);
        if (!$stationKeys) {
            $stationKeys = [$orgKey];
        }
        $stationKeys = array_values(array_unique(array_filter(array_merge(
            $stationKeys,
            [$orgKey, normalizeRdommsOrganizationCenter($organization) ?? '', getRdommsOrganizationCenterLabel($orgKey)]
        ))));
        $stationPlaceholders = implode(', ', array_fill(0, count($stationKeys), '?'));

        $sql .= " AND (
            created_by = ?
            OR LOWER(REPLACE(COALESCE(organization, ''), ' ', '_')) = ?
            OR organization IN ({$stationPlaceholders})
            OR LOWER(REPLACE(COALESCE(organization, ''), ' ', '_')) IN ({$stationPlaceholders})
            OR created_by IN (
                SELECT id FROM users
                WHERE LOWER(REPLACE(COALESCE(organization, ''), ' ', '_')) = ?
            )
        )";
        array_push($params, $userId, $orgKey);
        foreach ($stationKeys as $stationKey) {
            $params[] = $stationKey;
        }
        foreach ($stationKeys as $stationKey) {
            $params[] = normalizeOrganizationKey((string) $stationKey);
        }
        $params[] = $orgKey;
        return;
    }

    if ($role === 'head_of_department' && $organization !== '' && trim((string) ($user['department'] ?? '')) !== '') {
        require_once __DIR__ . '/departments.php';
        require_once __DIR__ . '/organization-centers.php';
        $department = trim((string) $user['department']);
        $normalizedDepartment = normalizeRdommsDepartment($department) ?? $department;
        $departmentLabel = getRdommsDepartmentLabel($normalizedDepartment);
        $orgKey = normalizeOrganizationKey($organization);
        $stationKeys = getUserOrganizationStationKeys($user);
        if (!$stationKeys) {
            $stationKeys = [$orgKey];
        }
        $stationPlaceholders = implode(', ', array_fill(0, count($stationKeys), '?'));

        $sql .= " AND (
            created_by = ?
            OR sector = ?
            OR sector = ?
            OR LOWER(sector) = LOWER(?)
            OR organization IN ({$stationPlaceholders})
            OR created_by IN (
                SELECT id FROM users
                WHERE LOWER(REPLACE(COALESCE(organization, ''), ' ', '_')) = ?
                  AND (department = ? OR department = ? OR LOWER(department) = LOWER(?))
            )
        )";
        array_push($params, $userId, $normalizedDepartment, $departmentLabel, $department);
        foreach ($stationKeys as $stationKey) {
            $params[] = $stationKey;
        }
        array_push($params, $orgKey, $normalizedDepartment, $departmentLabel, $department);
        return;
    }

    $sql .= ' AND created_by = ?';
    $params[] = $userId;
}

/** Finance roles can view organization-wide budget/expense data. */
function userCanViewFinanceModule(array $user): bool
{
    $role = normalizeRoleName($user['role'] ?? '');
    return in_array($role, ['admin', 'accountant'], true);
}

/** Only accountants may create, update, or delete finance records. */
function userCanManageFinanceCrud(array $user): bool
{
    return normalizeRoleName($user['role'] ?? '') === 'accountant';
}

function userCanViewFinanceReports(array $user): bool
{
    return userCanViewFinanceModule($user);
}

function getFinanceDataScopeUserId($user) {
    if (!$user) {
        return null;
    }

    $role = normalizeRoleName($user['role'] ?? '');
    if (in_array($role, ['admin', 'accountant', 'head_of_organization_center', 'head_of_department'], true)) {
        return null;
    }

    return (int) ($user['id'] ?? 0);
}

function userCanToggleDataScopeMode(array $user): bool
{
    $role = normalizeRoleName($user['role'] ?? '');
    return in_array($role, ['head_of_organization_center', 'head_of_department'], true);
}

function normalizeDataScopeMode(array $user, ?string $requested = null): string
{
    if (!userCanToggleDataScopeMode($user)) {
        return 'team';
    }

    $mode = strtolower(trim((string) ($requested ?? 'team')));
    return in_array($mode, ['personal', 'team'], true) ? $mode : 'team';
}

function getScopeModeFromRequest(array $user): string
{
    $requested = $_GET['scope_mode'] ?? $_POST['scope_mode'] ?? null;
    return normalizeDataScopeMode($user, is_string($requested) ? $requested : null);
}

function appendBeneficiaryListScopeFilter(string &$sql, array &$params, array $user, ?string $scopeMode = null): void
{
    if (!$user || userCanViewAllData($user)) {
        return;
    }

    $role = normalizeRoleName($user['role'] ?? '');
    $userId = (int) ($user['id'] ?? 0);
    $organization = trim((string) ($user['organization'] ?? ''));
    $mode = normalizeDataScopeMode($user, $scopeMode);

    if ($mode === 'personal') {
        appendDataScopeFilter($sql, $params, 'registered_by', $userId > 0 ? $userId : null);
        return;
    }

    if ($role === 'head_of_organization_center' && $organization !== '') {
        $orgKey = normalizeOrganizationKey($organization);
        $sql .= ' AND registered_by IN (
            SELECT id FROM users
            WHERE LOWER(REPLACE(COALESCE(organization, \'\'), \' \', \'_\')) = ?
        )';
        $params[] = $orgKey;
        return;
    }

    if ($role === 'head_of_department' && $organization !== '' && trim((string) ($user['department'] ?? '')) !== '') {
        require_once __DIR__ . '/departments.php';
        $department = trim((string) $user['department']);
        $normalizedDepartment = normalizeRdommsDepartment($department) ?? $department;
        $departmentLabel = getRdommsDepartmentLabel($normalizedDepartment);
        $orgKey = normalizeOrganizationKey($organization);
        $sql .= ' AND registered_by IN (
            SELECT id FROM users
            WHERE LOWER(REPLACE(COALESCE(organization, \'\'), \' \', \'_\')) = ?
              AND (department = ? OR department = ? OR LOWER(department) = LOWER(?))
        )';
        array_push($params, $orgKey, $normalizedDepartment, $departmentLabel, $department);
        return;
    }

    appendDataScopeFilter($sql, $params, 'registered_by', $userId > 0 ? $userId : null);
}

function beneficiaryMatchesScope(PDO $conn, array $user, array $beneficiary, ?string $scopeMode = null): bool
{
    if (userCanViewAllData($user)) {
        return true;
    }

    $sql = 'SELECT COUNT(*) AS total FROM beneficiaries WHERE (beneficiary_id = ? OR id = ?)';
    $params = [
        (string) ($beneficiary['beneficiary_id'] ?? $beneficiary['id'] ?? ''),
        (int) ($beneficiary['id'] ?? 0),
    ];
    appendBeneficiaryListScopeFilter($sql, $params, $user, $scopeMode);
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return (int) ($row['total'] ?? 0) > 0;
}

function denyUnlessBeneficiaryInScope(PDO $conn, array $user, $beneficiaryId, ?string $scopeMode = null): void
{
    if (userCanViewAllData($user)) {
        return;
    }

    $stmt = $conn->prepare('SELECT * FROM beneficiaries WHERE beneficiary_id = ? OR id = ? LIMIT 1');
    $stmt->execute([$beneficiaryId, $beneficiaryId]);
    $beneficiary = $stmt->fetch();

    if (!$beneficiary || !beneficiaryMatchesScope($conn, $user, $beneficiary, $scopeMode)) {
        sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
    }
}

function getUserOrganizationStationKeys(array $user): array
{
    require_once __DIR__ . '/organization-centers.php';

    $role = normalizeRoleName($user['role'] ?? '');
    if ($role !== 'head_of_organization_center') {
        return [];
    }

    $organization = trim((string) ($user['organization'] ?? ''));
    if ($organization === '') {
        return [];
    }

    $normalized = normalizeRdommsOrganizationCenter($organization) ?? normalizeOrganizationKey($organization);
    $label = getRdommsOrganizationCenterLabel($normalized);

    return array_values(array_unique(array_filter([$normalized, $label, normalizeOrganizationKey($organization)])));
}

function appendFinanceStationScopeFilter(string &$sql, array &$params, array $user, string $stationColumn = 'station'): void
{
    $stations = getUserOrganizationStationKeys($user);
    if (!$stations) {
        return;
    }

    $placeholders = implode(', ', array_fill(0, count($stations), '?'));
    $sql .= " AND {$stationColumn} IN ({$placeholders})";
    foreach ($stations as $station) {
        $params[] = $station;
    }
}

function appendFinanceDepartmentScopeFilter(string &$sql, array &$params, array $user, string $departmentColumn = 'department'): void
{
    $role = normalizeRoleName($user['role'] ?? '');
    if ($role !== 'head_of_department') {
        return;
    }

    $department = trim((string) ($user['department'] ?? ''));
    if ($department === '') {
        $sql .= ' AND 1 = 0';
        return;
    }

    require_once __DIR__ . '/departments.php';
    $normalizedDepartment = normalizeRdommsDepartment($department) ?? $department;
    $departmentLabel = getRdommsDepartmentLabel($normalizedDepartment);

    $sql .= " AND ({$departmentColumn} = ? OR {$departmentColumn} = ? OR LOWER({$departmentColumn}) = LOWER(?))";
    array_push($params, $normalizedDepartment, $departmentLabel, $department);
}

function appendFinanceListScopeFilter(string &$sql, array &$params, array $user, string $ownerColumn = 'created_by'): void
{
    $scopeUserId = getFinanceDataScopeUserId($user);
    appendDataScopeFilter($sql, $params, $ownerColumn, $scopeUserId);
    appendFinanceStationScopeFilter($sql, $params, $user);
    appendFinanceDepartmentScopeFilter($sql, $params, $user);
}

/** Guard legacy api.php sync_table bulk writes by role. */
function rdomms_guard_legacy_sync_table(array $user, string $table): void
{
    $role = normalizeRoleName($user['role'] ?? '');

    if (in_array($table, ['projects', 'rd_projects'], true)) {
        sendJSONResponse(['success' => false, 'message' => 'Projects cannot be bulk-synced. Use the projects API.'], 400);
    }

    if (in_array($table, ['budgetAllocations', 'expenses'], true)) {
        if (!userCanManageFinanceCrud($user)) {
            sendJSONResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
        return;
    }

    if (in_array($table, ['users', 'user_activities', 'notifications', 'system_alerts', 'scheduledReports', 'reportHistory'], true)) {
        if (!userCanViewAllData($user)) {
            sendJSONResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
        return;
    }

    if (in_array($table, ['beneficiaries', 'departments', 'services'], true)) {
        sendJSONResponse(['success' => false, 'message' => 'Use the dedicated API for this resource.'], 403);
    }

    if (in_array($table, ['monthlyPlans', 'quarterlyPlans', 'annualPlans'], true)) {
        if (!in_array($role, ['admin', 'head_of_organization_center', 'head_of_department', 'field_officer'], true)) {
            sendJSONResponse(['success' => false, 'message' => 'Forbidden'], 403);
        }
    }
}

function normalizeOrganizationKey($value) {
    return strtolower(preg_replace('/[\s\-]+/', '_', trim((string) $value)));
}

function organizationsMatch($left, $right) {
    require_once __DIR__ . '/organization-centers.php';

    $a = normalizeRdommsOrganizationCenter(trim((string) $left))
        ?? normalizeOrganizationKey($left);
    $b = normalizeRdommsOrganizationCenter(trim((string) $right))
        ?? normalizeOrganizationKey($right);

    return $a !== '' && $b !== '' && normalizeOrganizationKey($a) === normalizeOrganizationKey($b);
}

/** Operational project updates: creator only (admin bypasses via null scope). */
function denyUnlessCanUpdateProject($conn, $user, $projectId, $scopeUserId) {
    if ($scopeUserId === null) {
        return;
    }

    $stmt = $conn->prepare('SELECT created_by FROM projects WHERE project_id = ? OR id = ? LIMIT 1');
    $stmt->execute([$projectId, $projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
    }

    if ((int) ($project['created_by'] ?? 0) === $scopeUserId) {
        return;
    }

    sendJSONResponse([
        'status' => 'error',
        'message' => 'Only the user who created this project can update it.',
        'code' => 'not_project_owner',
    ], 403);
}

function departmentsMatch($left, $right) {
    require_once __DIR__ . '/departments.php';

    $leftKey = normalizeRdommsDepartment((string) $left) ?? normalizeOrganizationKey($left);
    $rightKey = normalizeRdommsDepartment((string) $right) ?? normalizeOrganizationKey($right);

    return $leftKey !== '' && $rightKey !== '' && $leftKey === $rightKey;
}

function denyUnlessCanManageProject($conn, $user, $projectId, $scopeUserId) {
    if ($scopeUserId === null) {
        return;
    }

    $stmt = $conn->prepare('SELECT created_by, organization FROM projects WHERE project_id = ? OR id = ? LIMIT 1');
    $stmt->execute([$projectId, $projectId]);
    $project = $stmt->fetch();

    if (!$project) {
        sendJSONResponse(['status' => 'error', 'message' => 'Project not found'], 404);
    }

    if ((int) ($project['created_by'] ?? 0) === $scopeUserId) {
        return;
    }

    $role = normalizeRoleName($user['role'] ?? '');

    if ($role === 'head_of_organization_center') {
        if (organizationsMatch($user['organization'] ?? '', $project['organization'] ?? '')) {
            return;
        }
    }

    if ($role === 'head_of_department') {
        $creatorStmt = $conn->prepare('SELECT department, organization FROM users WHERE id = ? LIMIT 1');
        $creatorStmt->execute([(int) ($project['created_by'] ?? 0)]);
        $creator = $creatorStmt->fetch() ?: [];

        if (
            departmentsMatch($user['department'] ?? '', $creator['department'] ?? '')
            && organizationsMatch($user['organization'] ?? '', $creator['organization'] ?? '')
        ) {
            return;
        }
    }

    sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
}

function appendUserOrganizationScopeFilter(&$sql, &$params, array $user): void
{
    $organization = trim((string) ($user['organization'] ?? ''));
    if ($organization === '') {
        $sql .= ' AND 1 = 0';
        return;
    }

    $sql .= ' AND (LOWER(REPLACE(organization, \' \', \'_\')) = ? OR LOWER(organization) = LOWER(?))';
    array_push($params, normalizeOrganizationKey($organization), $organization);
}

function appendUserDepartmentScopeFilter(&$sql, &$params, array $user): void
{
    require_once __DIR__ . '/departments.php';

    $department = trim((string) ($user['department'] ?? ''));
    if ($department === '') {
        $sql .= ' AND 1 = 0';
        return;
    }

    $normalizedDepartment = normalizeRdommsDepartment($department) ?? $department;
    $departmentLabel = getRdommsDepartmentLabel($normalizedDepartment);
    $sql .= ' AND (department = ? OR department = ? OR LOWER(department) = LOWER(?))';
    array_push($params, $normalizedDepartment, $departmentLabel, $department);
}

/** Filter user directory lists by role: all orgs, center, department, or self. */
function appendUserListScopeFilter(&$sql, &$params, array $user): void
{
    if (!$user || userCanViewAllData($user)) {
        return;
    }

    $role = normalizeRoleName($user['role'] ?? '');

    if ($role === 'human_resource_manager') {
        return;
    }

    if ($role === 'head_of_organization_center') {
        appendUserOrganizationScopeFilter($sql, $params, $user);
        return;
    }

    if (in_array($role, ['head_of_department', 'field_officer'], true)) {
        appendUserOrganizationScopeFilter($sql, $params, $user);
        appendUserDepartmentScopeFilter($sql, $params, $user);
        return;
    }

    $sql .= ' AND id = ?';
    $params[] = (int) ($user['id'] ?? 0);
}

function fetchUserRowByIdentifier(PDO $conn, string $identifier): ?array
{
    $id = trim($identifier);
    if ($id === '') {
        return null;
    }

    $stmt = $conn->prepare('SELECT * FROM users WHERE user_id = ? OR id = ? LIMIT 1');
    $stmt->execute([$id, $id]);
    $row = $stmt->fetch();

    return is_array($row) ? $row : null;
}

function currentUserCanManageTargetUser(array $manager, array $target, string $action = 'update'): bool
{
    $managerRole = normalizeRoleName($manager['role'] ?? '');
    $targetRole = normalizeRoleName($target['role'] ?? '');

    if (userCanViewAllData($manager) || $managerRole === 'human_resource_manager') {
        if ($action === 'delete' && $targetRole === 'admin') {
            return userCanViewAllData($manager);
        }

        return true;
    }

    if ($targetRole === 'admin') {
        return false;
    }

    if ($managerRole === 'head_of_organization_center') {
        if (!organizationsMatch($manager['organization'] ?? '', $target['organization'] ?? '')) {
            return false;
        }

        if ($action === 'delete') {
            return !in_array($targetRole, ['admin', 'head_of_organization_center'], true);
        }

        return true;
    }

    if ($managerRole === 'head_of_department') {
        if (
            !organizationsMatch($manager['organization'] ?? '', $target['organization'] ?? '')
            || !departmentsMatch($manager['department'] ?? '', $target['department'] ?? '')
        ) {
            return false;
        }

        if (in_array($targetRole, ['admin', 'head_of_organization_center', 'human_resource_manager'], true)) {
            return false;
        }

        return $action !== 'delete';
    }

    return false;
}

function denyUnlessCanManageUser(PDO $conn, array $manager, string $targetId, string $action = 'update'): array
{
    $target = fetchUserRowByIdentifier($conn, $targetId);
    if (!$target) {
        sendJSONResponse(['status' => 'error', 'message' => 'User not found'], 404);
    }

    if (!currentUserCanManageTargetUser($manager, $target, $action)) {
        sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
    }

    return $target;
}

function enforceManagedUserPayloadScope(array $manager, array &$data): void
{
    require_once __DIR__ . '/organization-centers.php';
    require_once __DIR__ . '/departments.php';

    $role = normalizeRoleName($manager['role'] ?? '');

    if (userCanViewAllData($manager) || $role === 'human_resource_manager') {
        return;
    }

    if ($role === 'head_of_organization_center') {
        $data['organization'] = normalizeRdommsOrganizationCenter((string) ($manager['organization'] ?? ''))
            ?? trim((string) ($manager['organization'] ?? ''));

        if (isset($data['role']) && normalizeRoleName((string) $data['role']) === 'admin') {
            sendJSONResponse(['status' => 'error', 'message' => 'You cannot assign the admin role'], 403);
        }

        return;
    }

    if ($role === 'head_of_department') {
        $data['organization'] = normalizeRdommsOrganizationCenter((string) ($manager['organization'] ?? ''))
            ?? trim((string) ($manager['organization'] ?? ''));
        $data['department'] = normalizeRdommsDepartment((string) ($manager['department'] ?? ''))
            ?? trim((string) ($manager['department'] ?? ''));

        if (isset($data['role'])) {
            $newRole = normalizeRoleName((string) $data['role']);
            if (in_array($newRole, ['admin', 'head_of_organization_center', 'human_resource_manager'], true)) {
                sendJSONResponse(['status' => 'error', 'message' => 'You cannot assign this role'], 403);
            }
        }
    }
}

function appendTeamMemberScopeFilter(&$sql, &$params, $user) {
    $role = normalizeRoleName($user['role'] ?? '');
    $sql .= " AND LOWER(status) = 'active'";

    if (in_array($role, ['admin', 'human_resource_manager'], true)) {
        return;
    }

    if ($role === 'head_of_organization_center') {
        appendUserOrganizationScopeFilter($sql, $params, $user);
        return;
    }

    if (in_array($role, ['head_of_department', 'field_officer', 'staff'], true)) {
        appendUserOrganizationScopeFilter($sql, $params, $user);
        appendUserDepartmentScopeFilter($sql, $params, $user);
    } elseif (!in_array($role, ['admin', 'human_resource_manager'], true)) {
        $sql .= ' AND 1 = 0';
    }
}

function fetchAllowedTeamMemberNames($conn, $user) {
    $query = 'SELECT fullname FROM users WHERE 1=1';
    $params = [];
    appendTeamMemberScopeFilter($query, $params, $user);
    $stmt = $conn->prepare($query);
    $stmt->execute($params);

    $names = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $name = strtolower(trim((string) ($row['fullname'] ?? '')));
        if ($name !== '') {
            $names[$name] = true;
        }
    }

    return $names;
}

function validateProjectTeamMembers($conn, $user, $teamMembers) {
    if (!is_array($teamMembers) || !$teamMembers) {
        return;
    }

    $allowedNames = fetchAllowedTeamMemberNames($conn, $user);
    foreach ($teamMembers as $member) {
        if (is_array($member)) {
            $name = trim((string) ($member['name'] ?? $member['fullname'] ?? ''));
        } else {
            $name = trim((string) $member);
        }

        if ($name === '') {
            continue;
        }

        if (!isset($allowedNames[strtolower($name)])) {
            sendJSONResponse([
                'status' => 'error',
                'message' => 'Team member not allowed for your department/center: ' . $name,
            ], 403);
        }
    }
}

function appendDataScopeFilter(&$sql, &$params, $ownerColumn, $scopeUserId) {
    if ($scopeUserId === null) {
        return;
    }

    $sql .= " AND {$ownerColumn} = ?";
    $params[] = $scopeUserId;
}

function denyUnlessOwnsRecord($record, $ownerColumn, $scopeUserId) {
    if ($scopeUserId === null) {
        return;
    }

    if (!$record || (int) ($record[$ownerColumn] ?? 0) !== $scopeUserId) {
        sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
    }
}

function denyUnlessRecordOwned($conn, $table, $whereClause, $params, $ownerColumn, $scopeUserId) {
    if ($scopeUserId === null) {
        return;
    }

    $stmt = $conn->prepare("SELECT {$ownerColumn} AS owner_id FROM {$table} WHERE {$whereClause} LIMIT 1");
    $stmt->execute($params);
    $row = $stmt->fetch();

    if (!$row || (int) ($row['owner_id'] ?? 0) !== $scopeUserId) {
        sendJSONResponse(['status' => 'error', 'message' => 'Forbidden'], 403);
    }
}

function logActivity($userId, $action, $module, $severity, $details, $ip = null) {
    $db = new Database();
    $conn = $db->getConnection();
    if (!$conn) {
        return false;
    }

    $ip = $ip ?: ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $stmt = $conn->prepare(
        'INSERT INTO activity_logs (user_id, action, module, severity, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)'
    );

    return $stmt->execute([$userId, $action, $module, $severity, $details, $ip]);
}

if (!defined('RDOMMS_SKIP_SECURITY')) {
    rdomms_bootstrap_api_security();
}
