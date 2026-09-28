<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function rdomms_primary_organization_id(): string
{
    return 'ORG001';
}

function rdomms_organization_defaults(): array
{
    return [
        'org_id' => rdomms_primary_organization_id(),
        'name' => 'Rural Development Organization',
        'registration_no' => '',
        'email' => 'info@rdo.or.tz',
        'phone' => '+255 22 123456',
        'website' => 'https://rdo.or.tz',
        'address' => 'Dodoma, Tanzania',
        'mission' => 'Empowering rural communities through sustainable development initiatives.',
        'logo_url' => '',
    ];
}

function rdomms_ensure_primary_organization(PDO $conn): void
{
    $defaults = rdomms_organization_defaults();
    $stmt = $conn->prepare('SELECT org_id FROM organizations WHERE org_id = ? LIMIT 1');
    $stmt->execute([$defaults['org_id']]);
    if ($stmt->fetch()) {
        return;
    }

    $insert = $conn->prepare(
        'INSERT INTO organizations (org_id, name, registration_no, email, phone, website, address, mission, logo_url)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $insert->execute([
        $defaults['org_id'],
        $defaults['name'],
        $defaults['registration_no'],
        $defaults['email'],
        $defaults['phone'],
        $defaults['website'],
        $defaults['address'],
        $defaults['mission'],
        $defaults['logo_url'],
    ]);
}

function rdomms_organization_row_to_client(array $row): array
{
    return [
        'id' => $row['org_id'] ?? $row['id'] ?? '',
        'org_id' => $row['org_id'] ?? '',
        'name' => $row['name'] ?? '',
        'regNo' => $row['registration_no'] ?? '',
        'registration_no' => $row['registration_no'] ?? '',
        'email' => $row['email'] ?? '',
        'phone' => $row['phone'] ?? '',
        'website' => $row['website'] ?? '',
        'address' => $row['address'] ?? '',
        'mission' => $row['mission'] ?? '',
        'logo' => $row['logo_url'] ?? '',
        'logo_url' => $row['logo_url'] ?? '',
        'updated_at' => $row['updated_at'] ?? $row['created_at'] ?? null,
    ];
}

function rdomms_organization_public_client(array $row): array
{
    $client = rdomms_organization_row_to_client($row);
    return [
        'name' => $client['name'],
        'email' => $client['email'],
        'phone' => $client['phone'],
        'website' => $client['website'],
        'address' => $client['address'],
        'logo_url' => $client['logo_url'],
    ];
}

function rdomms_load_primary_organization(?PDO $conn = null): array
{
    if (!$conn) {
        $db = new Database();
        $conn = $db->getConnection();
    }

    if (!$conn) {
        return rdomms_organization_defaults();
    }

    rdomms_ensure_primary_organization($conn);

    $stmt = $conn->prepare('SELECT * FROM organizations WHERE org_id = ? LIMIT 1');
    $stmt->execute([rdomms_primary_organization_id()]);
    $row = $stmt->fetch();

    return $row ?: rdomms_organization_defaults();
}

function rdomms_store_organization_logo(string $logoInput): ?string
{
    $logoInput = trim($logoInput);
    if ($logoInput === '') {
        return null;
    }

    if (!preg_match('#^data:image/(png|jpeg|jpg|gif|webp|svg\+xml);base64,#i', $logoInput)
        && !preg_match('#^https?://#i', $logoInput)
        && !preg_match('#^uploads/#i', $logoInput)
        && !preg_match('#^assets/#i', $logoInput)) {
        return null;
    }

    if (!preg_match('#^data:image/#i', $logoInput)) {
        return $logoInput;
    }

    $uploadDir = __DIR__ . '/../uploads/organization';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
        return null;
    }

    [$meta, $base64Data] = explode(',', $logoInput, 2);
    $binaryData = base64_decode($base64Data, true);
    if ($binaryData === false) {
        return null;
    }

    if (strlen($binaryData) > 2 * 1024 * 1024) {
        return null;
    }

    $extension = 'png';
    if (stripos($meta, 'jpeg') !== false || stripos($meta, 'jpg') !== false) {
        $extension = 'jpg';
    } elseif (stripos($meta, 'gif') !== false) {
        $extension = 'gif';
    } elseif (stripos($meta, 'webp') !== false) {
        $extension = 'webp';
    } elseif (stripos($meta, 'svg') !== false) {
        $extension = 'svg';
    }

    $fileName = 'org_logo_' . uniqid('', true) . '.' . $extension;
    $filePath = $uploadDir . '/' . $fileName;
    if (file_put_contents($filePath, $binaryData) === false) {
        return null;
    }

    return 'uploads/organization/' . $fileName;
}

function rdomms_save_primary_organization(PDO $conn, array $input): array
{
    rdomms_ensure_primary_organization($conn);

    $map = [
        'name' => 'name',
        'regNo' => 'registration_no',
        'registration_no' => 'registration_no',
        'email' => 'email',
        'phone' => 'phone',
        'website' => 'website',
        'address' => 'address',
        'mission' => 'mission',
        'logo' => 'logo_url',
        'logo_url' => 'logo_url',
    ];

    $fields = [];
    $params = [];

    foreach ($map as $rawKey => $column) {
        if (!array_key_exists($rawKey, $input)) {
            continue;
        }

        $value = trim((string) $input[$rawKey]);
        if ($column === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            sendJSONResponse(['status' => 'error', 'message' => 'Invalid organization email'], 400);
        }

        if ($column === 'logo_url') {
            if ($value === '') {
                $fields[] = 'logo_url = ?';
                $params[] = null;
                continue;
            }

            if (preg_match('#^data:image/#i', $value)) {
                $stored = rdomms_store_organization_logo($value);
                if ($stored === null) {
                    sendJSONResponse(['status' => 'error', 'message' => 'Invalid logo upload'], 400);
                }
                $value = $stored;
            }
        }

        if ($column === 'name' && $value === '') {
            sendJSONResponse(['status' => 'error', 'message' => 'Organization name is required'], 400);
        }

        $fields[] = "{$column} = ?";
        $params[] = $value !== '' ? $value : null;
    }

    if (!$fields) {
        return rdomms_load_primary_organization($conn);
    }

    $params[] = rdomms_primary_organization_id();
    $stmt = $conn->prepare('UPDATE organizations SET ' . implode(', ', $fields) . ' WHERE org_id = ?');
    $stmt->execute($params);

    return rdomms_load_primary_organization($conn);
}
