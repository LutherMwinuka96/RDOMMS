<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/notification-tables.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$session = verifySession();
if (!$session) {
    sendJSONResponse(['status' => 'error', 'message' => 'Unauthorized'], 401);
}

requireRole(['admin', 'head_of_organization_center', 'head_of_department', 'field_officer', 'staff', 'accountant', 'human_resource_manager'], $session);

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    sendJSONResponse(['status' => 'error', 'message' => 'Database connection failed'], 500);
}

rdomms_ensure_notification_tables($conn);

$userId = (int) ($_SESSION['user_id'] ?? 0);
$method = $_SERVER['REQUEST_METHOD'];

function threadRowToResponse(PDO $conn, array $row): array
{
    $threadId = $row['thread_id'] ?? '';
    $stmt = $conn->prepare(
        'SELECT body, sender, is_read, created_at
         FROM notification_messages
         WHERE thread_id = ?
         ORDER BY created_at DESC
         LIMIT 1'
    );
    $stmt->execute([$threadId]);
    $last = $stmt->fetch() ?: [];

    $unreadStmt = $conn->prepare(
        'SELECT COUNT(*) AS count
         FROM notification_messages
         WHERE thread_id = ? AND sender = \'them\' AND is_read = 0'
    );
    $unreadStmt->execute([$threadId]);
    $unread = (int) (($unreadStmt->fetch()['count'] ?? 0));

    return [
        'id' => $threadId,
        'name' => $row['participant_name'] ?? '',
        'role' => $row['participant_role'] ?? '',
        'status' => $row['participant_status'] ?? 'offline',
        'updatedAt' => $row['updated_at'] ?? $row['created_at'] ?? '',
        'lastMessage' => $last['body'] ?? '',
        'lastSender' => $last['sender'] ?? '',
        'lastAt' => $last['created_at'] ?? '',
        'unread' => $unread,
    ];
}

switch ($method) {
    case 'GET':
        if (!empty($_GET['thread_id'])) {
            $threadId = (string) $_GET['thread_id'];
            $threadStmt = $conn->prepare(
                'SELECT * FROM notification_threads WHERE thread_id = ? AND owner_user_id = ? LIMIT 1'
            );
            $threadStmt->execute([$threadId, $userId]);
            $thread = $threadStmt->fetch();
            if (!$thread) {
                sendJSONResponse(['status' => 'error', 'message' => 'Conversation not found'], 404);
            }

            $msgStmt = $conn->prepare(
                'SELECT message_id AS id, sender AS `from`, body AS text, is_read AS `read`, created_at AS ts
                 FROM notification_messages
                 WHERE thread_id = ?
                 ORDER BY created_at ASC
                 LIMIT 500'
            );
            $msgStmt->execute([$threadId]);
            sendJSONResponse([
                'status' => 'success',
                'data' => [
                    'thread' => threadRowToResponse($conn, $thread),
                    'messages' => $msgStmt->fetchAll(),
                ],
            ]);
        }

        $stmt = $conn->prepare(
            'SELECT * FROM notification_threads WHERE owner_user_id = ? ORDER BY updated_at DESC LIMIT 100'
        );
        $stmt->execute([$userId]);
        $threads = array_map(static function ($row) use ($conn) {
            return threadRowToResponse($conn, $row);
        }, $stmt->fetchAll());

        sendJSONResponse(['status' => 'success', 'data' => $threads]);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (!empty($data['thread_id']) && !empty($data['text'])) {
            $threadId = (string) $data['thread_id'];
            $threadStmt = $conn->prepare(
                'SELECT thread_id FROM notification_threads WHERE thread_id = ? AND owner_user_id = ? LIMIT 1'
            );
            $threadStmt->execute([$threadId, $userId]);
            if (!$threadStmt->fetch()) {
                sendJSONResponse(['status' => 'error', 'message' => 'Conversation not found'], 404);
            }

            $messageId = 'MSG' . time() . rand(100, 999);
            $body = trim((string) $data['text']);
            if ($body === '') {
                sendJSONResponse(['status' => 'error', 'message' => 'Message text is required'], 400);
            }

            $sender = ($data['sender'] ?? 'me') === 'them' ? 'them' : 'me';
            $stmt = $conn->prepare(
                'INSERT INTO notification_messages (message_id, thread_id, sender, body, is_read)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([$messageId, $threadId, $sender, $body, $sender === 'me' ? 1 : 0]);

            $conn->prepare('UPDATE notification_threads SET updated_at = NOW() WHERE thread_id = ?')
                ->execute([$threadId]);

            sendJSONResponse(['status' => 'success', 'message' => 'Message sent', 'data' => ['message_id' => $messageId]], 201);
        }

        $name = trim((string) ($data['name'] ?? $data['participant_name'] ?? ''));
        if ($name === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Participant name is required'], 400);
        }

        $threadId = 'THR' . time() . rand(100, 999);
        $stmt = $conn->prepare(
            'INSERT INTO notification_threads (thread_id, participant_name, participant_role, participant_status, owner_user_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $threadId,
            $name,
            $data['role'] ?? $data['participant_role'] ?? null,
            $data['status'] ?? 'offline',
            $userId,
        ]);

        if (!empty($data['text'])) {
            $messageId = 'MSG' . time() . rand(100, 999);
            $msgStmt = $conn->prepare(
                'INSERT INTO notification_messages (message_id, thread_id, sender, body, is_read)
                 VALUES (?, ?, \'me\', ?, 1)'
            );
            $msgStmt->execute([$messageId, $threadId, trim((string) $data['text'])]);
        }

        sendJSONResponse(['status' => 'success', 'message' => 'Conversation created', 'data' => ['thread_id' => $threadId]], 201);
        break;

    case 'PUT':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!is_array($data)) {
            $data = $_POST;
        }

        if (empty($data['thread_id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Thread ID required'], 400);
        }

        $threadId = (string) $data['thread_id'];
        $threadStmt = $conn->prepare(
            'SELECT thread_id FROM notification_threads WHERE thread_id = ? AND owner_user_id = ? LIMIT 1'
        );
        $threadStmt->execute([$threadId, $userId]);
        if (!$threadStmt->fetch()) {
            sendJSONResponse(['status' => 'error', 'message' => 'Conversation not found'], 404);
        }

        if (!empty($data['action']) && $data['action'] === 'mark_read') {
            $stmt = $conn->prepare(
                "UPDATE notification_messages SET is_read = 1 WHERE thread_id = ? AND sender = 'them'"
            );
            $stmt->execute([$threadId]);
            sendJSONResponse(['status' => 'success', 'message' => 'Messages marked as read']);
        }

        sendJSONResponse(['status' => 'error', 'message' => 'Invalid action'], 400);
        break;

    case 'DELETE':
        if (empty($_GET['thread_id'])) {
            sendJSONResponse(['status' => 'error', 'message' => 'Thread ID required'], 400);
        }

        $threadId = (string) $_GET['thread_id'];
        $threadStmt = $conn->prepare(
            'SELECT thread_id FROM notification_threads WHERE thread_id = ? AND owner_user_id = ? LIMIT 1'
        );
        $threadStmt->execute([$threadId, $userId]);
        if (!$threadStmt->fetch()) {
            sendJSONResponse(['status' => 'error', 'message' => 'Conversation not found'], 404);
        }

        $conn->prepare('DELETE FROM notification_messages WHERE thread_id = ?')->execute([$threadId]);
        $conn->prepare('DELETE FROM notification_threads WHERE thread_id = ? AND owner_user_id = ?')
            ->execute([$threadId, $userId]);

        sendJSONResponse(['status' => 'success', 'message' => 'Conversation deleted']);
        break;

    default:
        sendJSONResponse(['status' => 'error', 'message' => 'Method not allowed'], 405);
}
