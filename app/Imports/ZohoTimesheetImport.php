<?php

namespace App\Imports;

use App\Models\TimesheetEntry;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithStartRow;

class ZohoTimesheetImport implements ToCollection, WithStartRow
{
    protected $teamName;
    
    protected array $fieldMappings = [
        'item_id'              => ['item_id'],
        'item_name'            => ['itemname', 'name', 'title', 'item_name'],
        'item_detail'          => ['meetingtitle', 'itemdetail', 'detail', 'description', 'cts', 'meeting_title'],
        'log_type'             => ['logtype', 'type', 'log_type'],
        'log_hours'            => ['log_hours'],
        'log_hours_decimal'    => ['log_hours'],
        'project'              => ['project', 'project_name'], // Added project_name as fallback
        'application'          => ['application', 'app', 'project_name'], // Use project_name as application fallback
        'log_owner'            => ['logowner', 'owner', 'log_owner'],
        'log_date'             => ['logdate', 'date', 'log_date'],
        'billing_status'       => ['billingstatus', 'billing', 'billing_status'],
        'approval_status'      => ['approvalstatus', 'approval', 'approval_status'],
        'descriptions' => ['descriptions', 'description', 'remarks'],
        'remarks'              => ['description', 'remarks', 'notes', 'comment', 'descriptions'],
        'sprint'               => ['sprint'],
        'status'               => ['status'],
        'item_type'            => ['itemtype', 'item_type'],
        'estimated_points'     => ['estimationpoints', 'estimated', 'points', 'estimation_points'],
        'actual_points'        => ['actualpoints', 'actual', 'actual_points'],
        'requested_date'       => ['requesteddate', 'requested', 'requested_date'],
        'start_date'           => ['startdate', 'start_date'],
        'release_date'         => [ 'end_date'],
        'expected_start_date'  => ['startdate','expectedstartdate', 'expected_start_date'],
        'expected_release_date' => ['end_date','expectedreleasedate', 'expected_release_date'],
        'actual_start_date'    => ['actualstartdate', 'actual_start_date', 'start_date'], // Use start_date as fallback
        'actual_release_date'  => ['actualreleasedate', 'actual_release_date', 'completed_on'], // Use completed_on as fallback
        'completed_on'         => ['completedon', 'completed_on'],
        'created_on'           => ['createdon', 'created_on'],
        'created_by'           => ['createdby', 'created_by'],
        'updated_by'           => ['updatedby', 'updated_by'],
        'reason_for_rejection' => ['reasonforrejection', 'reason_for_rejection'],
        'user_groups'          => ['usergroups', 'user_groups'],
        'assignee'             => ['assignee'],
        'epic'                 => ['epic'],
        'priority'             => ['priority'],
        'duration'             => ['duration'],
        'reported_by'          => ['reportedby', 'reported_by'],
        'total_workhours'      => ['totalworkhours', 'total_workhours', 'total_work_hours'],
        'work_hours_per_owner' => ['workhoursperowner', 'work_hours_per_owner'],
        'work_hours_type'      => ['workhourstype', 'work_hours_type'],
        'meeting_type'         => ['meetingtype', 'meeting_type'],
        'location'             => ['location'],
        'remind_before'        => ['remindbefore', 'remind_before'],
        'agenda'               => ['agenda'],
        'last_modified'        => ['lastmodified', 'last_modified'],
        'project_id'           => ['projectid', 'project_id'],
        'project_name'         => ['projectname', 'project_name'],
        'owner_mail_id'        => ['ownermailid', 'owner_mail_id'],
        'owner_role'           => ['ownerrole', 'owner_role'],
        'project_group'        => ['projectgroup', 'project_group'],
        'team_name'            => ['teamname', 'team_name', 'team', 'log_owner'], // Added log_owner as fallback
        'approved_by'          => ['approvedby', 'approved_by'],
        'tags'                 => ['tags'],
        'log_title'            => ['logtitle', 'log_title'],
        'sprint_name'          => ['sprintname', 'sprint_name'],
        'zoho_link'            => ['zoholink', 'zoho_link', 'link', 'url'],
    ];

    public function __construct(string $teamName)
    {
        $this->teamName = $teamName;
    }

    /**
     * Skip first 7 rows (metadata) and start from row 8 (headers)
     */
    public function startRow(): int
    {
        return 8;
    }

    /**
     * Process the collection of rows
     */
    public function collection(Collection $rows)
    {
        Log::info("=== STARTING MAATWEBSITE IMPORT PROCESS ===");
        Log::info("Team name: {$this->teamName}");
        Log::info("Total rows received: " . $rows->count());

        if ($rows->isEmpty()) {
            Log::warning("No rows found in Excel file.");
            return;
        }

        // Get headers from first row
        $headers = $rows->first();
        if (!$headers) {
            Log::warning("No headers found in the file.");
            return;
        }

        Log::info("=== HEADERS FOUND ===");
        Log::info("Headers: " . json_encode($headers->toArray()));

        // Convert headers to normalized keys
        $normalizedHeaders = [];
        foreach ($headers as $index => $header) {
            $normalizedKey = $this->normalizeKey($header);
            $normalizedHeaders[$index] = $normalizedKey;
            Log::info("Header mapping: Column {$index} '{$header}' -> '{$normalizedKey}'");
        }

        // Process data rows (skip header row)
        foreach ($rows->skip(1) as $rowIndex => $row) {
            Log::info("=== PROCESSING ROW " . ($rowIndex + 1) . " ===");
            
            if ($row->filter()->isEmpty()) {
                Log::info("Skipping empty row " . ($rowIndex + 1));
                continue;
            }

            try {
                // Create associative array with normalized headers
                $normalizedRow = [];
                foreach ($row as $colIndex => $value) {
                    if (isset($normalizedHeaders[$colIndex])) {
                        $normalizedRow[$normalizedHeaders[$colIndex]] = $value;
                    }
                }

                Log::info("Raw row data: " . json_encode($row->toArray()));
                Log::info("Normalized row data: " . json_encode($normalizedRow));

                // Map fields dynamically
                $mappedRow = $this->mapRowDataDynamic($normalizedRow, $rowIndex);
                Log::info("Mapped row data: " . json_encode(array_filter($mappedRow, function($value) {
                    return $value !== null && $value !== '';
                })));

                // Create entry
                $entryData = array_merge($mappedRow, ['team_name' => $this->teamName]);
                Log::info("Final entry data before save: " . json_encode($entryData));
                
                $entry = TimesheetEntry::create($entryData);
                Log::info("Entry created successfully with ID: " . $entry->id);

            } catch (\Exception $e) {
                Log::error("Failed to create entry for row " . ($rowIndex + 1) . ": " . $e->getMessage());
                Log::error("Row data: " . json_encode($row->toArray()));
                throw $e;
            }
        }

        Log::info("=== IMPORT COMPLETED ===");
    }

    private function normalizeKey($key): string
    {
        $normalizedKey = strtolower(trim($key));
        $normalizedKey = preg_replace('/[^a-z0-9]+/', '_', $normalizedKey);
        return trim($normalizedKey, '_');
    }

    private function mapRowDataDynamic(array $normalizedRow, int $rowIndex = 0): array
    {
        $mapped = [];
        $mappingLog = [];

        foreach ($this->fieldMappings as $field => $possibleKeys) {
            $mapped[$field] = null;
            $foundKey = null;

            foreach ($possibleKeys as $key) {
                $normalizedKey = $this->normalizeKey($key);

                if (isset($normalizedRow[$normalizedKey]) && $normalizedRow[$normalizedKey] !== null && $normalizedRow[$normalizedKey] !== '') {
                    $mapped[$field] = $normalizedRow[$normalizedKey];
                    $foundKey = $normalizedKey;
                    break;
                }
            }

            if ($foundKey) {
                $mappingLog[] = "{$field} <- {$foundKey} = '{$mapped[$field]}'";
            } else {
                $mappingLog[] = "{$field} <- NOT FOUND";
            }
        }

        // Special handling for missing fields
        $this->handleSpecialMappings($mapped, $normalizedRow, $mappingLog);

        if ($rowIndex < 3) { // Only log mapping details for first few rows
            Log::info("Field mapping for row {$rowIndex}:");
            foreach ($mappingLog as $log) {
                Log::info("  " . $log);
            }
        }

        // Convert dates and decimals with detailed logging
        $this->processDateFields($mapped, $rowIndex);
        $this->processDecimalFields($mapped, $rowIndex);

        return $mapped;
    }

 private function handleSpecialMappings(array &$mapped, array $normalizedRow, array &$mappingLog): void
{
    // If application is missing, use project_name
    if (empty($mapped['application']) && !empty($mapped['project_name'])) {
        $mapped['application'] = $mapped['project_name'];
        $mappingLog[] = "application <- project_name (fallback) = '{$mapped['application']}'";
    }

    // If team_name is missing, use log_owner
    if (empty($mapped['team_name']) && !empty($mapped['log_owner'])) {
        $mapped['team_name'] = $mapped['log_owner'];
        $mappingLog[] = "team_name <- log_owner (fallback) = '{$mapped['team_name']}'";
    }

    // If actual_points is missing, use calculated log_hours_decimal
    if (empty($mapped['actual_points']) && !empty($mapped['log_hours_decimal'])) {
        $mapped['actual_points'] = $mapped['log_hours_decimal'];
        $mappingLog[] = "actual_points <- log_hours_decimal (fallback) = '{$mapped['actual_points']}'";
    }

    // If expected_start_date is missing, use start_date (if available)
    if (empty($mapped['expected_start_date']) && !empty($mapped['start_date'])) {
        $mapped['expected_start_date'] = $mapped['start_date'];
        $mappingLog[] = "expected_start_date <- start_date (fallback) = '{$mapped['expected_start_date']}'";
    }

    // If expected_release_date is missing, use release_date (if available)
    if (empty($mapped['expected_release_date']) && !empty($mapped['release_date'])) {
        $mapped['expected_release_date'] = $mapped['release_date'];
        $mappingLog[] = "expected_release_date <- release_date (fallback) = '{$mapped['expected_release_date']}'";
    }

    // If actual_start_date is missing, use start_date (if available)
    if (empty($mapped['actual_start_date']) && !empty($mapped['start_date'])) {
        $mapped['actual_start_date'] = $mapped['start_date'];
        $mappingLog[] = "actual_start_date <- start_date (fallback) = '{$mapped['actual_start_date']}'";
    }

    // If actual_release_date is missing, use completed_on or release_date (if available)
    if (empty($mapped['actual_release_date'])) {
        if (!empty($mapped['completed_on'])) {
            $mapped['actual_release_date'] = $mapped['completed_on'];
            $mappingLog[] = "actual_release_date <- completed_on (fallback) = '{$mapped['actual_release_date']}'";
        } elseif (!empty($mapped['release_date'])) {
            $mapped['actual_release_date'] = $mapped['release_date'];
            $mappingLog[] = "actual_release_date <- release_date (fallback) = '{$mapped['actual_release_date']}'";
        }
    }

    // Generate zoho_link if missing
    if (empty($mapped['zoho_link']) && !empty($mapped['item_id']) && !empty($mapped['project_name'])) {
        $mapped['zoho_link'] = "https://projects.zoho.com/" . strtolower($mapped['project_name']) . "/item/" . $mapped['item_id'];
        $mappingLog[] = "zoho_link <- generated = '{$mapped['zoho_link']}'";
    }
}

    private function processDateFields(array &$mapped, int $rowIndex): void
    {
        $dateFields = [
            'log_date', 'requested_date', 'start_date', 'release_date',
            'expected_start_date', 'expected_release_date', 
            'actual_start_date', 'actual_release_date',
            'created_on', 'last_modified','completed_on'
        ];
        
        foreach ($dateFields as $field) {
            if ($mapped[$field] !== null) {
                $originalValue = $mapped[$field];
                $mapped[$field] = $this->parseDate($mapped[$field]);
                if ($rowIndex < 3) {
                    Log::info("Date conversion - {$field}: '{$originalValue}' -> '{$mapped[$field]}'");
                }
            }
        }
    }

    private function processDecimalFields(array &$mapped, int $rowIndex): void
    {
        $decimalFields = [
            'log_hours_decimal', 'estimated_points', 'actual_points', 
            'total_workhours', 'work_hours_per_owner', 'log_hours'
        ];
        
        foreach ($decimalFields as $field) {
            if ($mapped[$field] !== null) {
                $originalValue = $mapped[$field];
                $mapped[$field] = $this->parseDecimal($mapped[$field]);
                if ($rowIndex < 3) {
                    Log::info("Decimal conversion - {$field}: '{$originalValue}' -> '{$mapped[$field]}'");
                }
            }
        }
    }

    private function parseDate($date): ?string
    {
        if (!$date || trim($date) === '') {
            return null;
        }

        try {
            $date = trim($date);
            Log::debug("Attempting to parse date: '{$date}'");
            
            // Handle format: 01/Jul/2025 01:00 AM
            if (preg_match('/(\d{2})\/([a-zA-Z]{3})\/(\d{4})\s+(\d{1,2}):(\d{2})\s+(AM|PM)/', $date)) {
                $parsed = Carbon::createFromFormat('d/M/Y h:i A', $date);
                Log::debug("Parsed with format 'd/M/Y h:i A': " . $parsed->format('Y-m-d H:i:s'));
            } 
            // Handle format: 01/Jul/2025 08:23 PM  
            elseif (preg_match('/(\d{2})\/([a-zA-Z]{3})\/(\d{4})\s+(\d{1,2}):(\d{2})\s+(AM|PM)/', $date)) {
                $parsed = Carbon::createFromFormat('d/M/Y g:i A', $date);
                Log::debug("Parsed with format 'd/M/Y g:i A': " . $parsed->format('Y-m-d H:i:s'));
            }
            // Handle format: 01/Jul/2025
            elseif (preg_match('/(\d{2})\/([a-zA-Z]{3})\/(\d{4})/', $date)) {
                $parsed = Carbon::createFromFormat('d/M/Y', $date);
                Log::debug("Parsed with format 'd/M/Y': " . $parsed->format('Y-m-d H:i:s'));
            }
            // Handle format with time ranges like: 26/Jun/2025 12:00 AM - 01/Jul/2025 11:59 PM
            elseif (preg_match('/(\d{2})\/([a-zA-Z]{3})\/(\d{4})\s+(\d{1,2}):(\d{2})\s+(AM|PM)\s*-/', $date)) {
                $startDatePart = preg_replace('/\s*-.*$/', '', $date);
                $parsed = Carbon::createFromFormat('d/M/Y g:i A', $startDatePart);
                Log::debug("Parsed date range, using start: " . $parsed->format('Y-m-d H:i:s'));
            }
            else {
                $parsed = Carbon::parse($date);
                Log::debug("Parsed with Carbon::parse(): " . $parsed->format('Y-m-d H:i:s'));
            }

            return $parsed->format('Y-m-d H:i:s');
        } catch (\Exception $e) {
            Log::warning("Failed to parse date: '{$date}' - {$e->getMessage()}");
            return null;
        }
    }

    private function parseDecimal($value): float
    {
        if (!$value || trim($value) === '') {
            return 0;
        }

        $originalValue = $value;
        Log::debug("Attempting to parse decimal: '{$originalValue}'");

        // Handle Excel formulas - calculate the time from log_hours field
        if (str_starts_with(trim($value), '=')) {
            Log::debug("Found Excel formula: '{$originalValue}', will calculate from time format");
            return 0; // Will be calculated later from log_hours
        }

        // Handle time format like "08:50" to convert to decimal hours
        if (preg_match('/(\d{1,2}):(\d{2})/', $value, $matches)) {
            $hours = (int)$matches[1];
            $minutes = (int)$matches[2];
            $result = $hours + ($minutes / 60);
            Log::debug("Converted time '{$originalValue}' to decimal: {$result}");
            return $result;
        }

        $cleaned = preg_replace('/[^0-9.]/', '', trim($value));
        $result = $cleaned !== '' ? (float) $cleaned : 0;
        Log::debug("Converted '{$originalValue}' to decimal: {$result}");
        return $result;
    }
}