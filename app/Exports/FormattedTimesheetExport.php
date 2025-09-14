<?php

namespace App\Exports;

use App\Models\TimesheetEntry;
use App\Services\TimesheetCalculatorService;
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

    public function __construct($entries)
    {
        Log::info("=== FormattedTimesheetExport CONSTRUCTOR START ===");
        Log::info("Entries count: " . $entries->count());

        $this->entries = $entries;
        $this->calculator = new TimesheetCalculatorService();
        
        try {
            $this->averages = $this->calculator->calculateAverages($entries);
            $this->teamStats = $this->calculateTeamStats();
            $this->memberStats = $this->calculateMemberWiseStats();
            Log::info("All calculations completed successfully");
        } catch (\Exception $e) {
            Log::error("Failed to calculate stats: " . $e->getMessage());
            $this->averages = $this->getEmptyAverages();
            $this->teamStats = ['total_members' => 0, 'availability' => 96.36, 'total_points' => 0];
            $this->memberStats = [];
        }
        
        Log::info("=== FormattedTimesheetExport CONSTRUCTOR END ===");
    }

    public function array(): array
    {
        Log::info("=== array() method called ===");
        
        $data = [];
        
        // Row 1-4: Team summary info
        $data[] = ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Total Team members count:', $this->teamStats['total_members']];
        $data[] = ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Capacity: based on team availability and effort est.', ''];
        $data[] = ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Total available team: ' . $this->teamStats['availability'] . '%', ''];
        $data[] = ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Total points delivered: ' . number_format($this->averages['total_weekly_points'], 3), ''];
        
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
        
        // Data rows - completed items only
        $completedEntries = $this->entries->filter(function($entry) {
            return !is_null($entry->actual_release_date) && 
                   strtolower($entry->item_type ?? '') !== 'planned' &&
                   strtolower($entry->status ?? '') !== 'in progress';
        });
        
        foreach($completedEntries as $entry) {
            $calculations = $this->calculator->calculateAllFormulas($entry);
            
            $data[] = [
                $entry->application ?? 'Enrollment',
                $entry->item_name ?? '',
                $entry->item_detail ?? '',
                $entry->item_type ?? '',
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
                number_format($calculations['weekly_points'], 2),
                number_format($calculations['story_point_accuracy'], 2),
                $entry->remarks ?? '',
                $entry->zoho_link ?? '',
                $calculations['release_delay']
            ];
        }
        
        // Totals row
        $data[] = [
            'TOTALS/AVERAGES', '', '', '', '', '', '', '', '', '',
            number_format($this->averages['average_lead_time'], 2),
            number_format($this->averages['average_cycle_time'], 2), 
            number_format($this->averages['average_defects_density'], 2),
            number_format($this->averages['total_estimated_points'], 0),
            number_format($this->averages['total_actual_points'], 0),
            number_format($this->averages['total_weekly_points'], 3),
            number_format($this->averages['average_story_point_accuracy'], 2),
            '', '',
            number_format($this->averages['average_release_delay'], 2)
        ];
        
        // Empty rows
        $data[] = array_fill(0, 20, '');
        $data[] = array_fill(0, 20, '');
        
        // In Progress section
        $data[] = ['In progress', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''];
        
        $inProgressEntries = $this->entries->filter(function($entry) {
            return is_null($entry->actual_release_date) || 
                   strtolower($entry->status ?? '') === 'in progress' ||
                   strtolower($entry->item_type ?? '') === 'planned';
        });
        
        foreach($inProgressEntries as $entry) {
            $calculations = $this->calculator->calculateAllFormulas($entry);
            
            $data[] = [
                $entry->application ?? 'Enrollment',
                $entry->item_name ?? '',
                $entry->item_detail ?? '',
                $entry->item_type ?? '',
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
                number_format($calculations['weekly_points'], 2),
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
            'Resource', 'Leave Count', 'Average Lead Time', 'Average Cycle Time',
            'Average Defect Density', 'Total Weekly Points', 'Capacity', 
            'Story point accuracy', 'Average Release Delay', 'Planned Leave', 'Unplanned Leave'
        ];
        
        // Member-wise data
        foreach($this->memberStats as $member => $stats) {
            $data[] = [
                $member,
                $stats['leave_count'] ?? 0,
                number_format($stats['avg_lead_time'] ?? 0, 2),
                number_format($stats['avg_cycle_time'] ?? 0, 2),
                number_format($stats['avg_defect_density'] ?? 0, 2),
                number_format($stats['total_weekly_points'] ?? 0, 2),
                number_format($stats['capacity'] ?? 0, 2),
                number_format($stats['story_point_accuracy'] ?? 0, 2),
                number_format($stats['avg_release_delay'] ?? 0, 2),
                $stats['planned_leave'] ?? 0,
                $stats['unplanned_leave'] ?? 0
            ];
        }
        
        // Final summary row
        $data[] = [
            'Total team members', count($this->memberStats), '', '', '', 
            'Total weekly points', number_format($this->averages['total_weekly_points'], 3), '', 
            'Team Availability', $this->teamStats['availability'] . '%', ''
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
            AfterSheet::class => function(AfterSheet $event) {
                Log::info("=== AfterSheet EVENT TRIGGERED ===");
                try {
                    $sheet = $event->sheet->getDelegate();
                    $this->formatSheet($sheet);
                    Log::info("Sheet formatting completed successfully");
                } catch (\Exception $e) {
                    Log::error("ERROR in AfterSheet event: " . $e->getMessage());
                    Log::error("Stack trace: " . $e->getTraceAsString());
                    // Don't throw the exception, just log it and continue
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
                    'color' => ['argb' => 'FF4472C4']
                ],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_MEDIUM]],
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_CENTER,
                    'vertical' => Alignment::VERTICAL_CENTER
                ]
            ]);
            $sheet->getRowDimension(7)->setRowHeight(25);
            
            // Auto-size columns
            foreach(range('A','T') as $columnID) {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }
            
        } catch (\Exception $e) {
            Log::error("ERROR in formatSheet: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());
        }
        
        Log::info("=== formatSheet() END ===");
    }
    
    private function getRowColor($itemType): ?string
    {
        switch(strtoupper(trim($itemType ?? ''))) {
            case 'BUG':
                return 'FFFFCCCC'; // Light red
            case 'NEW REQUEST':
                return 'FFCCFFCC'; // Light green  
            case 'PLANNED':
                return 'FFCCCCFF'; // Light blue
            case 'MEETING':
            case 'MEETINGS':
                return 'FFFFFF99'; // Light yellow
            default:
                return null;
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
        
        foreach($members as $member) {
            $memberEntries = $this->entries->where('log_owner', $member);
            $memberCalculations = [];
            
            foreach($memberEntries as $entry) {
                $memberCalculations[] = $this->calculator->calculateAllFormulas($entry);
            }
            
            $calculationCollection = collect($memberCalculations);
            
            $memberStats[$member] = [
                'leave_count' => 0, // Would come from leave system
                'avg_lead_time' => $calculationCollection->avg('lead_time') ?? 0,
                'avg_cycle_time' => $calculationCollection->avg('cycle_time') ?? 0,
                'avg_defect_density' => $calculationCollection->avg('defects_density') ?? 0,
                'total_weekly_points' => $calculationCollection->sum('weekly_points') ?? 0,
                'capacity' => ($calculationCollection->sum('weekly_points') ?? 0) * 10, // Assuming 10x multiplier
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