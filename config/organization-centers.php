<?php
/**
 * Canonical RDO organization centers — matches auth/signup and finance station field.
 */
function getRdommsOrganizationCenters(): array
{
    return [
        ['value' => 'rdo_mafinga', 'label' => 'RDO Mafinga'],
        ['value' => 'rdo_mdabulo', 'label' => 'RDO Mdabulo'],
        ['value' => 'rdo_ibwanzi', 'label' => 'RDO Ibwanzi'],
        ['value' => 'rdo_kilolo', 'label' => 'RDO Kilolo'],
        ['value' => 'rdo_malangali', 'label' => 'RDO Malangali'],
    ];
}

function getRdommsOrganizationCenterLabel(string $value): string
{
    $key = trim($value);
    foreach (getRdommsOrganizationCenters() as $center) {
        if ($center['value'] === $key || $center['label'] === $key) {
            return $center['label'];
        }
    }
    if ($key === '') {
        return 'All centers';
    }
    return str_replace('_', ' ', ucwords(str_replace('_', ' ', $key)));
}

function normalizeRdommsOrganizationCenter(string $value): ?string
{
    $key = trim($value);
    foreach (getRdommsOrganizationCenters() as $center) {
        if ($center['value'] === $key || $center['label'] === $key) {
            return $center['value'];
        }
    }
    return $key !== '' ? $key : null;
}
