<?php

namespace App\Exports;

use App\Models\TimesheetEntry;
use App\Services\TimesheetCalculatorService;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Illuminate\Support\Facades\Log;

class FormattedTimesheetExport implements FromArray, WithTitle, WithEvents
{
    protected $entries;
    protected $calculator;
    protected $averages;
    protected $teamStats;
    protected $memberStats;
    protected $weekStart;
    protected $weekEnd;

    public function __construct($entries, $weekStart = null, $weekEnd = null)
    {
        Log::info("=== FormattedTimesheetExport CONSTRUCTOR START ===");
        Log::info("Entries count: " . $entries->count());

        $this->entries = $entries;
        $this->calculator = new TimesheetCalculatorService();
        $this->weekStart = $weekStart ? Carbon::parse($weekStart) : null;
        $this->weekEnd = $weekEnd ? Carbon::parse($weekEnd) : null;

        try {
            $this->averages = $this->calculator->calculateAverages($entries);
            $this->teamStats = $this->calculateTeamStats();
            $this->memberStats = $this->calculateMemberWiseStats();
            Log::info("All calculations completed successfully");
        } catch (\Exception $e) {
            Log::error("Failed to calculate stats: " . $e->getMessage());
            $this->averages = $this->getEmptyAverages();
            $this->teamStats = ['total_members' => 0, 'availability' => '', 'total_points' => 0];
            $this->memberStats = [];
        }

        Log::info("=== FormattedTimesheetExport CONSTRUCTOR END ===");
    }

    /**
     * Helper method to extract numeric value from weekly_points array
     */
    private function getWeeklyPointsSum($weeklyPointsArray): float
    {
        if (!is_array($weeklyPointsArray)) {
            return (float) $weeklyPointsArray;
        }
        
        $sum = 0;
        foreach ($weeklyPointsArray as $weeklyPoint) {
            $sum += $weeklyPoint['weekly_points'] ?? 0;
        }
        
        return $sum;
    }

    /**
 * Group entries by item_id and log_owner to avoid duplicates and calculate weekly points correctly
 */
private function groupEntriesByItemAndOwner($entries)
{
    $grouped = [];
    
    foreach ($entries as $entry) {
        $key = ($entry->item_id ?? 'no_id') . '_' . ($entry->log_owner ?? 'no_owner');
        
        if (!isset($grouped[$key])) {
            $grouped[$key] = clone $entry;
            // Initialize log_hours_decimal if not set
            $grouped[$key]->log_hours_decimal = $entry->log_hours_decimal ?? 0;
        } else {
            // Sum the log_hours_decimal for same item_id + log_owner
            $existing = $grouped[$key];
            $existing->log_hours_decimal = ($existing->log_hours_decimal ?? 0) + ($entry->log_hours_decimal ?? 0);
            
            // Also sum other numeric fields if needed
            $existing->actual_points = ($existing->actual_points ?? 0) + ($entry->actual_points ?? 0);
            
            // Update other fields if they're empty in the existing entry
            $existing->remarks = $existing->remarks ?: $entry->remarks;
            $existing->zoho_link = $existing->zoho_link ?: $entry->zoho_link;
        }
    }
    
    return collect(array_values($grouped));
}

/**
 * Calculate weekly points for a grouped entry (contains summed log_hours_decimal)
 */
private function calculateWeeklyPointsForGroupedEntry($groupedEntry): float
{
    $totalHours = $groupedEntry->log_hours_decimal ?? 0;
    $totalMinutes = $totalHours * 60;
    return $totalMinutes / 240;
}


    /**
     * Check if an item type is a meeting
     */
    private function isMeetingType($itemType): bool
    {
        $meetingTypes = ['meeting', 'meetings', 'daily stand-up', 'standup', 'stand-up'];
        return in_array(strtolower(trim($itemType ?? '')), $meetingTypes);
    }

    /**
     * Check if an item name indicates a meeting
     */
    private function isMeetingItem($itemName): bool
    {
        $meetingKeywords = ['standup', 'meeting', 'demo', 'discussion', 'stand-up'];
        $itemNameLower = strtolower($itemName ?? '');
        
        foreach ($meetingKeywords as $keyword) {
            if (strpos($itemNameLower, $keyword) !== false) {
                return true;
            }
        }
        
        return false;
    }

    public function array(): array
    {
        Log::info("=== array() method called ===");

        $data = [];

        // Row 1-4: Team summary info
        $data[] = ['Total Team members count:', count($this->memberStats)];
        $data[] = ['Capacity: based on team availability and effort est.', ''];
        $data[] = ['Total available team: ' . $this->teamStats['availability'] . '%', ''];
        $data[] = ['Total points delivered: ' . number_format($this->averages['total_weekly_points'], 3), ''];

        // Row 5: Empty
        $data[] = [''];

        // Row 6: Summary totals
        $summaryRow = array_fill(0, 20, ''); // Fixed to 20 columns
        $summaryRow[10] = number_format($this->averages['average_lead_time'], 0); // K6
        $summaryRow[11] = number_format($this->averages['average_cycle_time'], 0); // L6
        $summaryRow[12] = number_format($this->averages['average_defects_density'], 2); // M6
        $summaryRow[13] = number_format($this->averages['total_estimated_points'], 0); // N6
        $summaryRow[14] = number_format($this->averages['total_actual_points'], 2); // O6
        $summaryRow[15] = number_format($this->averages['total_weekly_points'], 3); // P6
        $summaryRow[16] = number_format($this->averages['average_story_point_accuracy'], 2); // Q6
        $summaryRow[19] = number_format($this->averages['average_release_delay'], 2); // T6
        $data[] = $summaryRow;

        // Row 7: Headers
        $data[] = [
            'APPLICATION',
            'ITEM NAME',
            'Item detail / Subtask',
            'ITEM TYPE',
            'TEAM NAME',
            'Requested Date',
            'Expected Start date',
            'Expected Release Date',
            'Actual Start Date',
            'Actual Release Date',
            'Lead Time',
            'Cycle Time',
            'Defects density',
            'Estimated Points',
            'Actual Points',
            'Weekly Points',
            'Story point Accuracy',
            'Remarks',
            'ZOHO LINK',
            'Release delay'
        ];

      // Data rows - completed items only (grouped by item_id and log_owner)
$completedEntries = $this->entries->filter(function ($entry) {
    $completedOn = $entry->release_date ? Carbon::parse($entry->release_date) : null;

    // If we have week data, check if task was completed within the week
    if ($this->weekStart && $this->weekEnd && $completedOn) {
        return $completedOn->between($this->weekStart, $this->weekEnd) &&
            !is_null($entry->actual_release_date);
    }

    // Fallback to original logic if no week data provided
    return !is_null($entry->actual_release_date);
});

// Group completed entries to avoid duplicates and sum hours
$groupedCompletedEntries = $this->groupEntriesByItemAndOwner($completedEntries);

// Separate meetings and regular items
$regularItems = $groupedCompletedEntries->filter(function ($entry) {
    $exportItemType = $this->calculator->getExportItemType($entry);
    return !$this->isMeetingType($exportItemType) && !$this->isMeetingItem($entry->item_name);
});

$meetingItems = $groupedCompletedEntries->filter(function ($entry) {
    $exportItemType = $this->calculator->getExportItemType($entry);
    return $this->isMeetingType($exportItemType) || $this->isMeetingItem($entry->item_name);
});

// Add regular items first
foreach ($regularItems as $entry) {
    // Calculate other formulas (lead time, cycle time, etc.) normally
    $calculations = $this->calculator->calculateAllFormulas($entry);
    $exportItemType = $this->calculator->getExportItemType($entry);
    
    // Calculate weekly points correctly for grouped entry
    $weeklyPoints = $this->calculateWeeklyPointsForGroupedEntry($entry);

    $data[] = [
        $entry->epic,
        $entry->item_name ?? '',
        $entry->item_detail ?? '',
        $exportItemType,
        $entry->log_owner ?? $entry->team_name ?? '',
        $entry->requested_date ? $entry->requested_date->format('M j') : '',
        $entry->expected_start_date ? $entry->expected_start_date->format('M j') : '',
        $entry->expected_release_date ? $entry->expected_release_date->format('M j') : '',
        $entry->actual_start_date ? $entry->actual_start_date->format('M j') : '',
        $entry->actual_release_date ? $entry->actual_release_date->format('M j') : '',
        $calculations['lead_time'],
        $calculations['cycle_time'],
        $calculations['defects_density'],
        $entry->estimated_points ?? 0,
        $entry->actual_points ?? 0,
        number_format($weeklyPoints, 2), // Use the correctly calculated weekly points
        number_format($calculations['story_point_accuracy'], 2),
        $entry->remarks ?? '',
        $entry->zoho_link ?? '',
        $calculations['release_delay']
    ];
}

// Add meeting items after regular items
foreach ($meetingItems as $entry) {
    // Calculate other formulas (lead time, cycle time, etc.) normally
    $calculations = $this->calculator->calculateAllFormulas($entry);
    $exportItemType = $this->calculator->getExportItemType($entry);
    
    // Calculate weekly points correctly for grouped entry
    $weeklyPoints = $this->calculateWeeklyPointsForGroupedEntry($entry);

    $data[] = [
        $entry->epic,
        $entry->item_name ?? '',
        $entry->item_detail ?? '',
        $exportItemType,
        $entry->log_owner ?? $entry->team_name ?? '',
        $entry->requested_date ? $entry->requested_date->format('M j') : '',
        $entry->expected_start_date ? $entry->expected_start_date->format('M j') : '',
        $entry->expected_release_date ? $entry->expected_release_date->format('M j') : '',
        $entry->actual_start_date ? $entry->actual_start_date->format('M j') : '',
        $entry->actual_release_date ? $entry->actual_release_date->format('M j') : '',
        $calculations['lead_time'],
        $calculations['cycle_time'],
        $calculations['defects_density'],
        $entry->estimated_points ?? 0,
        $entry->actual_points ?? 0,
        number_format($weeklyPoints, 2), // Use the correctly calculated weekly points
        number_format($calculations['story_point_accuracy'], 2),
        $entry->remarks ?? '',
        $entry->zoho_link ?? '',
        $calculations['release_delay']
    ];
}

        // Totals row
        $data[] = [
            'TOTALS/AVERAGES',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            number_format($this->averages['average_lead_time'], 2),
            number_format($this->averages['average_cycle_time'], 2),
            number_format($this->averages['average_defects_density'], 2),
            number_format($this->averages['total_estimated_points'], 0),
            number_format($this->averages['total_actual_points'], 0),
            number_format($this->averages['total_weekly_points'], 3),
            number_format($this->averages['average_story_point_accuracy'], 2),
            '',
            '',
            number_format($this->averages['average_release_delay'], 2)
        ];

        // Empty rows
        $data[] = array_fill(0, 20, '');
        $data[] = array_fill(0, 20, '');

        // In Progress section - only items where expected_release_date is before completion date or no completion date
        $data[] = ['In progress', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''];

        $inProgressEntries = $this->entries->filter(function ($entry) {
            $completedOn = $entry->actual_release_date ? Carbon::parse($entry->actual_release_date) : null;
            $status = strtolower($entry->status ?? '');
            $logType = strtolower($entry->log_type ?? '');

            // Exclude meetings
            if ($logType === 'meeting') {
                return false;
            }

            // In-progress if no completion date or not in current week
            if (!$completedOn || ($this->weekStart && $this->weekEnd && !$completedOn->between($this->weekStart, $this->weekEnd))) {
                return true;
            }

            // Also include items with status inprogress or on hold
            if (in_array($status, ['inprogress', 'on hold'])) {
                return true;
            }

            return false;
        });


        // Group in-progress entries to avoid duplicates
      $inProgressEntries = $this->entries->filter(function ($entry) {
    $completedOn = $entry->actual_release_date ? Carbon::parse($entry->actual_release_date) : null;
    $status = strtolower($entry->status ?? '');
    $logType = strtolower($entry->log_type ?? '');

    // Exclude meetings
    if ($logType === 'meeting') {
        return false;
    }

    // In-progress if no completion date or not in current week
    if (!$completedOn || ($this->weekStart && $this->weekEnd && !$completedOn->between($this->weekStart, $this->weekEnd))) {
        return true;
    }

    // Also include items with status inprogress or on hold
    if (in_array($status, ['inprogress', 'on hold'])) {
        return true;
    }

    return false;
});

// Group in-progress entries to avoid duplicates and sum hours
$groupedInProgressEntries = $this->groupEntriesByItemAndOwner($inProgressEntries);

foreach ($groupedInProgressEntries as $entry) {
    // Calculate other formulas normally
    $calculations = $this->calculator->calculateAllFormulas($entry);
    $exportItemType = $this->calculator->getExportItemType($entry);
    
    // Calculate weekly points correctly for grouped entry
    $weeklyPoints = $this->calculateWeeklyPointsForGroupedEntry($entry);

    $data[] = [
        $entry->epic,
        $entry->item_name ?? '',
        $entry->item_detail ?? '',
        $exportItemType,
        $entry->log_owner ?? $entry->team_name ?? '',
        $entry->requested_date ? $entry->requested_date->format('M j') : '',
        $entry->expected_start_date ? $entry->expected_start_date->format('M j') : '',
        $entry->expected_release_date ? $entry->expected_release_date->format('M j') : '',
        $entry->actual_start_date ? $entry->actual_start_date->format('M j') : '',
        $entry->actual_release_date ? $entry->actual_release_date->format('M j') : '',
        $calculations['lead_time'],
        $calculations['cycle_time'],
        $calculations['defects_density'],
        $entry->estimated_points ?? 0,
        $entry->actual_points ?? 0,
        number_format($weeklyPoints, 2), // Use the correctly calculated weekly points
        number_format($calculations['story_point_accuracy'], 2),
        $entry->remarks ?? '',
        $entry->zoho_link ?? '',
        $calculations['release_delay']
    ];
}

        // Empty rows before member-wise section
        $data[] = array_fill(0, 20, '');
        $data[] = array_fill(0, 20, '');

        // Member-wise Calculation section
        $data[] = ['Member-wise Calculation', '', '', '', '', '', '', '', '', '', ''];
        $data[] = [
            'Resource',
            'Planned Leave',
            'Unplanned Leave',
            'Leave Count',
            'Average Lead Time',
            'Average Cycle Time',
            'Average Defect Density',
            'Total Weekly Points',
            'Capacity',
            'Story point accuracy',
            'Average Release Delay',
        ];

        // Member-wise data
        foreach($this->memberStats as $member => $stats) {
            $data[] = [
                $member,
                $stats['planned_leave'] ?? 0,
                $stats['unplanned_leave'] ?? 0,
                $stats['leave_count'] ?? 0,
                number_format($stats['avg_lead_time'] ?? 0, 2),
                number_format($stats['avg_cycle_time'] ?? 0, 2),
                number_format($stats['avg_defect_density'] ?? 0, 2),
                number_format($stats['total_weekly_points'] ?? 0, 2),
                number_format($stats['capacity'] ?? 0, 2),
                number_format($stats['story_point_accuracy'] ?? 0, 2),
                number_format($stats['avg_release_delay'] ?? 0, 2),
            ];
        }

        // Final summary row
        $data[] = [
            'Total team members',
            count($this->memberStats),
            '',
            '',
            '',
            'Total weekly points',
            number_format($this->averages['total_weekly_points'], 3),
            '',
            'Team Availability',
            $this->teamStats['availability'] . '%',
            ''
        ];

        Log::info("Generated data array with " . count($data) . " rows");
        return $data;
    }

    public function title(): string
    {
        return 'Weekly Production Report';
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                Log::info("=== AfterSheet EVENT TRIGGERED ===");
                try {
                    $sheet = $event->sheet->getDelegate();
                    $this->formatSheet($sheet);
                    Log::info("Sheet formatting completed successfully");
                } catch (\Exception $e) {
                    Log::error("ERROR in AfterSheet event: " . $e->getMessage());
                    Log::error("Stack trace: " . $e->getTraceAsString());
                }
            },
        ];
    }

   private function formatSheet(Worksheet $sheet)
{
    Log::info("=== formatSheet() START ===");

    try {
        // Style team summary (rows 1-4)
        $sheet->getStyle('T1:U4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 10],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['argb' => 'FFE6F3FF']
            ],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // Style summary numbers row (row 6) with yellow background
        $sheet->getStyle('K6:T6')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['argb' => 'FFFFFF00']
            ],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THICK]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        // Style main headers (row 7)
        $sheet->getStyle('A7:T7')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'color' => ['argb' => 'FF4472C4'] // Blue
            ],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);
        $sheet->getRowDimension(7)->setRowHeight(25);

        // Apply item type colors to ALL data rows (both completed and in-progress)
        $this->applyItemTypeColors($sheet);
        
        // Then apply section colors (these will override item type colors for section headers)
        $this->styleInProgressSection($sheet);
        $this->styleMemberWiseSection($sheet);

        // Auto-size columns
        foreach (range('A', 'T') as $columnID) {
            $sheet->getColumnDimension($columnID)->setAutoSize(true);
        }
    } catch (\Exception $e) {
        Log::error("ERROR in formatSheet: " . $e->getMessage());
        Log::error("Stack trace: " . $e->getTraceAsString());
    }

    Log::info("=== formatSheet() END ===");
}

private function applyItemTypeColors(Worksheet $sheet)
{
    $highestRow = $sheet->getHighestRow();
    
    // Start from row 8 (after headers) and go through all data rows
    for ($row = 8; $row <= $highestRow; $row++) {
        $itemTypeCell = $sheet->getCell('D' . $row); // Column D contains ITEM TYPE
        $itemType = $itemTypeCell->getValue();
        
        // Skip empty rows and section header rows
        if (empty($itemType) || 
            $itemType === 'In progress' || 
            $itemType === 'Member-wise Calculation' ||
            $itemType === 'Resource') {
            continue;
        }
        
        $color = $this->getRowColor($itemType);
        
       if ($color) {
    $sheet->getStyle('D' . $row)->applyFromArray([
        'fill' => [
            'fillType' => Fill::FILL_SOLID,
            'color' => ['argb' => $color]
        ]
    ]);
}
    }
}

private function getRowColor($itemType): ?string
{
    $type = strtoupper(trim($itemType ?? ''));
    
   switch ($type) {
    case 'BUG':
        return 'FFFF9999'; // bright pastel red
    case 'NEW REQUEST':
        return 'FF99FF99'; // bright pastel green
    case 'PLANNED':
        return 'FF99CCFF'; // bright pastel blue
    case 'MEETING':
    case 'MEETINGS':
    case 'DAILY STAND-UP':
        return 'FFFFFF99'; // bright pastel yellow
    case 'TASK':
        return 'FFD1B3FF'; // bright pastel purple
    case 'STORY':
        return 'FF99E6FF'; // bright pastel cyan-blue
    case 'HOT FIX':
        return 'FFFFB366'; // bright pastel orange
    case 'ENHANCEMENT':
        return 'FF99FFFF'; // bright pastel aqua
    default:
        return 'FFFFFFFF'; // white (default)
}

}

private function styleInProgressSection(Worksheet $sheet)
{
    $highestRow = $sheet->getHighestRow();
    
    // Find the "In progress" row
    for ($row = 1; $row <= $highestRow; $row++) {
        $cellValue = $sheet->getCell('A' . $row)->getValue();
        if ($cellValue === 'In progress') {
            // Style the "In progress" header row with orange background (override item type color)
            $sheet->getStyle('A' . $row . ':T' . $row)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['argb' => 'FFFFA500'] // Orange
                ],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);
            break;
        }
    }
}

private function styleMemberWiseSection(Worksheet $sheet)
{
    $highestRow = $sheet->getHighestRow();
    
    // Find the "Member-wise Calculation" row
    for ($row = 1; $row <= $highestRow; $row++) {
        $cellValue = $sheet->getCell('A' . $row)->getValue();
        if ($cellValue === 'Member-wise Calculation') {
            // Style the "Member-wise Calculation" header row with green background
            $sheet->getStyle('A' . $row . ':K' . $row)->applyFromArray([
                'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['argb' => 'FF4F8A10'] // Green
                ],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
            ]);

            // Style the member-wise column headers (next row)
            $headerRow = $row + 1;
            $sheet->getStyle('A' . $headerRow . ':K' . $headerRow)->applyFromArray([
                'font' => ['bold' => true],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'color' => ['argb' => 'FFE6F3FF'] // Light blue
                ],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
            ]);

            // Style the final summary row
            $summaryRow = $headerRow + count($this->memberStats) + 1;
            $summaryCellValue = $sheet->getCell('A' . $summaryRow)->getValue();
            if ($summaryCellValue === 'Total team members') {
                $sheet->getStyle('A' . $summaryRow . ':K' . $summaryRow)->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'color' => ['argb' => 'FFFFFFCC'] // Light yellow
                    ],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_DOUBLE]]
                ]);
            }
            
            break;
        }
    }
}

    private function calculateTeamStats(): array
    {
        $uniqueMembers = $this->entries->pluck('log_owner')->filter()->unique();

        return [
            'total_members' => $uniqueMembers->count(),
            'availability' => 96.36, // This should come from your HR system
            'total_points' => $this->averages['total_weekly_points'] ?? 0
        ];
    }

    private function calculateMemberWiseStats(): array
    {
        $memberStats = [];
        $members = $this->entries->pluck('log_owner')->filter()->unique();

        foreach ($members as $member) {
            $memberEntries = $this->entries->where('log_owner', $member);
            $memberCalculations = [];

            foreach ($memberEntries as $entry) {
                $memberCalculations[] = $this->calculator->calculateAllFormulas($entry);
            }

            $calculationCollection = collect($memberCalculations);

            // Calculate total weekly points correctly
            $totalWeeklyPoints = 0;
            foreach ($memberCalculations as $calculation) {
                // weekly_points is an array, so we need to sum its values
                $totalWeeklyPoints += $this->getWeeklyPointsSum($calculation['weekly_points']);
            }

            $memberStats[$member] = [
                'leave_count' => 0, // Would come from leave system
                'avg_lead_time' => $calculationCollection->avg('lead_time') ?? 0,
                'avg_cycle_time' => $calculationCollection->avg('cycle_time') ?? 0,
                'avg_defect_density' => $calculationCollection->avg('defects_density') ?? 0,
                'total_weekly_points' => $totalWeeklyPoints,
                'capacity' => $totalWeeklyPoints * 10, // Fixed: use calculated value
                'story_point_accuracy' => $calculationCollection->avg('story_point_accuracy') ?? 0,
                'avg_release_delay' => $calculationCollection->avg('release_delay') ?? 0,
                'planned_leave' => 0, // Would come from leave system
                'unplanned_leave' => 0, // Would come from leave system
            ];
        }

        return $memberStats;
    }

    private function getEmptyAverages(): array
    {
        return [
            'total_estimated_points' => 0,
            'total_actual_points' => 0,
            'total_weekly_points' => 0,
            'average_lead_time' => 0,
            'average_cycle_time' => 0,
            'average_defects_density' => 0,
            'average_story_point_accuracy' => 0,
            'average_release_delay' => 0,
        ];
    }
}