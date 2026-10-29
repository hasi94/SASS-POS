<?php

declare(strict_types=1);

namespace Flexipos\Kernel\Audit;

/**
 * The two file formats {@see AuditExport} can write (T-017 AC-18, D-13).
 */
enum ExportFormat: string
{
    case Json = 'json';
    case Csv = 'csv';
}
