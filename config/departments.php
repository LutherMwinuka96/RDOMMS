<?php
/**
 * Canonical RDOMMS department list (matches auth signup + system-wide selects).
 */
function getRdommsDepartments(): array
{
    return [
        ['value' => 'orphanage_support', 'label' => 'Orphanage Support'],
        ['value' => 'water_transmission_association', 'label' => 'Water Transmission Association'],
        ['value' => 'administration', 'label' => 'Administration'],
        ['value' => 'agriculture', 'label' => 'Agriculture'],
        ['value' => 'pottery', 'label' => 'Pottery'],
        ['value' => 'vocational_training_center', 'label' => 'Vocational Training Center'],
        ['value' => 'store', 'label' => 'Store'],
        ['value' => 'finance', 'label' => 'Department of Finance'],
        ['value' => 'marketing_procurement_supply', 'label' => 'Department of Marketing, Procurement & Supply'],
    ];
}

function getRdommsDepartmentLabel(string $value): string
{
    foreach (getRdommsDepartments() as $department) {
        if ($department['value'] === $value || $department['label'] === $value) {
            return $department['label'];
        }
    }
    return $value;
}

function isValidRdommsDepartment(string $value): bool
{
    $key = trim($value);
    if ($key === '') {
        return false;
    }
    foreach (getRdommsDepartments() as $department) {
        if ($department['value'] === $key || $department['label'] === $key) {
            return true;
        }
    }
    return false;
}

function normalizeRdommsDepartment(string $value): ?string
{
    $key = trim($value);
    foreach (getRdommsDepartments() as $department) {
        if ($department['value'] === $key || $department['label'] === $key) {
            return $department['value'];
        }
    }
    return null;
}

/**
 * Replace departments table rows with the canonical RDO|MMS list.
 * Legacy installs/backups may contain generic departments (HR, Health, etc.).
 */
function syncRdommsDepartmentsToDatabase(PDO $conn): int
{
    $canonical = getRdommsDepartments();
    $conn->beginTransaction();

    try {
        $conn->exec('DELETE FROM departments');
        $stmt = $conn->prepare('INSERT INTO departments (name, description) VALUES (?, ?)');
        $count = 0;

        foreach ($canonical as $department) {
            $stmt->execute([$department['label'], $department['value']]);
            $count++;
        }

        $conn->commit();
        return $count;
    } catch (Throwable $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        throw $e;
    }
}
