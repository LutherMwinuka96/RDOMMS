<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../config/funding-sources-catalog.php';

$db = new Database();
$conn = $db->getConnection();
if (!$conn) {
    fwrite(STDERR, "No DB connection\n");
    exit(1);
}

$rows = $conn->query('SELECT id, funding_source, donor_name FROM projects')->fetchAll();
$update = $conn->prepare('UPDATE projects SET funding_source = ? WHERE id = ?');

foreach ($rows as $row) {
    $normalized = normalizeRdommsFundingSourceLabel(
        (string) ($row['funding_source'] ?? ''),
        (string) ($row['donor_name'] ?? '')
    );
    if ($normalized !== (string) ($row['funding_source'] ?? '')) {
        $update->execute([$normalized, $row['id']]);
        echo "id {$row['id']}: {$row['funding_source']} -> {$normalized}\n";
    }
}

echo "Done. Rows: " . count($rows) . "\n";
