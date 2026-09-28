<?php
declare(strict_types=1);

/**
 * Canonical funding source labels and chart colors for RDO|MMS.
 */
function getRdommsFundingSourceCatalog(): array
{
    return [
        ['label' => 'Government – Central', 'color' => '#2563eb'],
        ['label' => 'Government – Local', 'color' => '#16a34a'],
        ['label' => 'Donor / Grant', 'color' => '#f59e0b'],
        ['label' => 'NGO Partner', 'color' => '#8b5cf6'],
        ['label' => 'Community Contribution', 'color' => '#0891b2'],
        ['label' => 'Not specified', 'color' => '#94a3b8'],
    ];
}

function getRdommsFundingSourceColorMap(): array
{
    $map = [];
    foreach (getRdommsFundingSourceCatalog() as $entry) {
        $map[$entry['label']] = $entry['color'];
    }
    return $map;
}

function normalizeRdommsFundingSourceLabel(?string $raw, ?string $donorName = null): string
{
    $label = trim((string) $raw);
    $label = str_replace(
        ['â€“', 'â€"', 'â€”', '–', '—', '−'],
        '-',
        $label
    );
    $label = preg_replace('/\s+/', ' ', $label) ?? $label;

    if ($label === '') {
        $donor = trim((string) $donorName);
        if ($donor !== '') {
            return 'Donor / Grant';
        }
        return 'Not specified';
    }

    $key = strtolower(str_replace(['_', '-'], ' ', $label));
    $key = preg_replace('/\s+/', ' ', $key) ?? $key;

    $aliases = [
        'government central' => 'Government – Central',
        'government local' => 'Government – Local',
        'donor grant' => 'Donor / Grant',
        'donor' => 'Donor / Grant',
        'grant' => 'Donor / Grant',
        'ngo partner' => 'NGO Partner',
        'ngo' => 'NGO Partner',
        'community contribution' => 'Community Contribution',
        'community' => 'Community Contribution',
        'not specified' => 'Not specified',
        'unspecified' => 'Not specified',
        'other' => 'Not specified',
    ];

    if (isset($aliases[$key])) {
        return $aliases[$key];
    }

    foreach (getRdommsFundingSourceCatalog() as $entry) {
        $catalogKey = strtolower(str_replace(['–', '—', '-'], ' ', $entry['label']));
        $catalogKey = preg_replace('/\s+/', ' ', $catalogKey) ?? $catalogKey;
        if ($catalogKey === $key) {
            return $entry['label'];
        }
    }

    return $label;
}

function rdomms_build_funding_sources_chart_data(array $aggregatedRows): array
{
    $totals = [];
    foreach ($aggregatedRows as $row) {
        $label = normalizeRdommsFundingSourceLabel(
            (string) ($row['funding_source'] ?? $row['label'] ?? ''),
            (string) ($row['donor_name'] ?? '')
        );
        $amount = (float) ($row['total_budget'] ?? $row['value'] ?? 0);
        $totals[$label] = ($totals[$label] ?? 0.0) + $amount;
    }

    $colorMap = getRdommsFundingSourceColorMap();
    $extraPalette = [
        '#ea580c', '#db2777', '#4f46e5', '#0d9488', '#ca8a04',
        '#dc2626', '#7c3aed', '#0369a1', '#65a30d', '#c026d3',
    ];
    $extraIndex = 0;

    $result = [];
    foreach (getRdommsFundingSourceCatalog() as $catalogEntry) {
        $label = $catalogEntry['label'];
        $result[] = [
            'label' => $label,
            'value' => (int) round($totals[$label] ?? 0),
            'color' => $catalogEntry['color'],
        ];
        unset($totals[$label]);
    }

    foreach ($totals as $label => $value) {
        $color = $colorMap[$label] ?? $extraPalette[$extraIndex % count($extraPalette)];
        $extraIndex++;
        $result[] = [
            'label' => $label,
            'value' => (int) round($value),
            'color' => $color,
        ];
    }

    usort($result, static function (array $a, array $b): int {
        if ($a['value'] !== $b['value']) {
            return $b['value'] <=> $a['value'];
        }
        return strcasecmp($a['label'], $b['label']);
    });

    return $result;
}
