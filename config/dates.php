<?php
declare(strict_types=1);

const RDOMMS_YEAR_MIN = 1980;
const RDOMMS_YEAR_MAX = 2070;

function rdomms_year_min(): int
{
    return RDOMMS_YEAR_MIN;
}

function rdomms_year_max(): int
{
    return RDOMMS_YEAR_MAX;
}

function rdomms_year_range(): array
{
    return range(RDOMMS_YEAR_MIN, RDOMMS_YEAR_MAX);
}

function rdomms_current_year(): int
{
    return (int) date('Y');
}

function rdomms_current_month_index(): int
{
    return (int) date('n');
}

function rdomms_current_month_name(): string
{
    return date('F');
}

function rdomms_month_names(): array
{
    return [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];
}

function rdomms_current_quarter_slug(): string
{
    return 'q' . (int) ceil(rdomms_current_month_index() / 3);
}

function rdomms_current_quarter_label(): string
{
    return 'Q' . (int) ceil(rdomms_current_month_index() / 3);
}
