<?php
require_once __DIR__ . '/../config/db.php';

header('Content-Type: text/html; charset=utf-8');

echo '<h1>RDO|MMS Installation</h1>';

try {
    $db = new Database();
    $conn = $db->getConnection();

    if (!$conn) {
        throw new RuntimeException('Database connection failed');
    }

    echo '<p>Database connection successful</p>';

    $schemaCandidates = [
        __DIR__ . '/../database.sql',
        __DIR__ . '/database.sql',
        __DIR__ . '/database.sql.sql',
    ];

    $sqlFile = null;
    foreach ($schemaCandidates as $candidate) {
        if (file_exists($candidate)) {
            $sqlFile = $candidate;
            break;
        }
    }

    if (!$sqlFile) {
        throw new RuntimeException('Database schema file not found');
    }

    $sql = file_get_contents($sqlFile);
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql);

    $success = 0;
    $failed = 0;

    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '' || substr(ltrim($statement), 0, 2) === '--') {
            continue;
        }

        try {
            $conn->exec($statement);
            $success++;
        } catch (PDOException $e) {
            $failed++;
            echo '<p style="color: orange;">Warning: ' . htmlspecialchars($e->getMessage()) . '</p>';
        }
    }

    echo '<p>Statements executed successfully: ' . (int) $success . '</p>';
    if ($failed > 0) {
        echo '<p>Statements with warnings: ' . (int) $failed . '</p>';
    }

    $hashedPassword = password_hash('RDOMMS@2012', PASSWORD_DEFAULT);
    $stmt = $conn->prepare(
        "INSERT INTO users (user_id, fullname, email, password, role, status)
         VALUES ('USR001', 'System Administrator', 'ffilipatali@yahoo.com', ?, 'admin', 'active')
         ON DUPLICATE KEY UPDATE
            email = VALUES(email),
            password = VALUES(password),
            fullname = VALUES(fullname),
            role = 'admin',
            status = 'active'"
    );
    $stmt->execute([$hashedPassword]);

    echo '<p>Default admin user: ffilipatali@yahoo.com / RDOMMS@2012</p>';

    $migrationDir = __DIR__ . '/../database/migrations';
    if (is_dir($migrationDir)) {
        $migrationFiles = glob($migrationDir . '/*.sql') ?: [];
        sort($migrationFiles);
        $migrationCount = 0;
        foreach ($migrationFiles as $migrationFile) {
            $migrationSql = file_get_contents($migrationFile);
            if ($migrationSql === false) {
                continue;
            }
            $migrationStatements = preg_split('/;\s*(?:\r?\n|$)/', $migrationSql);
            foreach ($migrationStatements as $statement) {
                $statement = trim($statement);
                if ($statement === '' || substr(ltrim($statement), 0, 2) === '--') {
                    continue;
                }
                try {
                    $conn->exec($statement);
                    $migrationCount++;
                } catch (PDOException $e) {
                    echo '<p style="color: orange;">Migration warning (' . htmlspecialchars(basename($migrationFile)) . '): '
                        . htmlspecialchars($e->getMessage()) . '</p>';
                }
            }
        }
        echo '<p>Migration statements executed: ' . (int) $migrationCount . '</p>';
    }

    require_once __DIR__ . '/../config/departments.php';
    try {
        $synced = syncRdommsDepartmentsToDatabase($conn);
        echo '<p>Departments synced: ' . (int) $synced . ' canonical records</p>';
    } catch (Throwable $e) {
        echo '<p style="color: orange;">Department sync warning: ' . htmlspecialchars($e->getMessage()) . '</p>';
    }

    echo '<h2>Installation Complete</h2>';
    echo '<p><a href="../index.html">Go to Homepage</a> | <a href="../auth/signin.php">Go to Login</a></p>';
} catch (Throwable $e) {
    echo '<p style="color: red;">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<p>Make sure MySQL is running and database credentials in config/db.php are correct.</p>';
}
