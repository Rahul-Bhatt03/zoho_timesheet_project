<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetCalculatorService
{
    public function calculateAllFormulas($entry): array
    {
        return [
            'lead_time' => $this->calculateLeadTime($entry),
            'cycle_time' => $this->calculateCycleTime($entry),
            'defects_density' => $this->calculateDefectsDensity($entry),
            'weekly_points' => $this->calculateWeeklyPoints(collect([$entry])),
            'story_point_accuracy' => $this->calculateStoryPointAccuracy(collect([$entry])),
            'release_delay' => $this->calculateReleaseDelay($entry)
        ];
    }

    /**
     * Lead Time = Actual Release Date - Requested Date (in days)
     */
    private function calculateLeadTime($entry): float
    {
        $requestedDate = $this->parseDate($entry->requested_date);
        $releaseDate = $this->parseDate($entry->actual_release_date ?? $entry->release_date);

        if (!$requestedDate || !$releaseDate) {
            return 0;
        }
return $this->getBusinessDays($requestedDate,$releaseDate);
        // return $requestedDate->diffInDays($releaseDate);
    }

    /**
     * Cycle Time = Actual Release Date - Actual Start Date (in days)
     */
    private function calculateCycleTime($entry): float
    {
        $startDate = $this->parseDate($entry->actual_start_date ?? $entry->start_date);
        $releaseDate = $this->parseDate($entry->actual_release_date ?? $entry->release_date);

        if (!$startDate || !$releaseDate) {
            return 0;
        }

return $this->getBusinessDays($startDate, $releaseDate);
    }

    /**
     * Defects Density = 1 if item_type is BUG, 0 otherwise
     */
    private function calculateDefectsDensity($entry): float
    {
        $reportedBy = strtolower($entry->reported_by ?? '');
        $itemType = strtolower($entry->item_type ?? '');

        //   if reported by is plannend 
        if (strpos($reportedBy, 'planned') !== false) {
            return 0;
        }
        // If reported by is "internal team"
        if (strpos($reportedBy, 'internal team') !== false) {
            return 1;
        }

        // If item type is "bug"
        if (strpos($itemType, 'bug') !== false) {
            return 1;
        }

        // If reported by is not "planned" and item type is "story" or "task"
        if (strpos($itemType, 'story') !== false || strpos($itemType, 'task') !== false) {
            return 1;
        }

        return 0;
    }

    /**
     * Weekly Points = Actual Points OR Log Hours Decimal OR Log Hours
     * Priority: actual_points > log_hours_decimal > log_hours > 0
     */
    private function calculateWeeklyPoints(Collection $entries): array
    {
        // group by item id and log owner 
        $grouped = $entries->groupBy(function ($entry) {
            return $entry->item_id . '|' . $entry->log_owner;
        });
        $weeklyPoints = [];
        foreach ($grouped as $key => $itemEntries) {
            [$itemId, $owner] = explode('|', $key);
            $sum = $itemEntries->sum('log_hours_decimal');
            $weeklyPoints[] = [
                'item_id' => $itemId,
                'log_owner' => $owner,
                'weekly_points' => $sum,
            ];
        }
        return $weeklyPoints;
    }

    //  * Story Point Accuracy = (Estimated Points / Actual Points) * 100
    //  * If estimated is 0 or actual is 0, return 0
    //  */
    private function calculateStoryPointAccuracy(Collection $entries): float
    {
        $weeklyPoints = $this->calculateWeeklyPoints($entries);
        $totalAccuracy = 0;
        $count = 0;

        foreach ($weeklyPoints as $wp) {
            $estimated = (float)($entries->where('item_id', $wp['item_id'])->where('log_owner', $wp['log_owner'])->first()->estimated_points ?? 0);
            $actual = $wp['weekly_points'];

            if ($estimated > 0 && $actual > 0) {
                $accuracy = ($estimated / $actual) * 100;
                $totalAccuracy += $accuracy;
                $count++;
            }
        }

        return $count > 0 ? $totalAccuracy / $count : 0;
    }

    /**
     * Release Delay = Actual Release Date - Expected Release Date (in days)
     * Positive number means delay, negative means early
     */
    private function calculateReleaseDelay($entry): float
    {
        $expectedDate = $this->parseDate($entry->expected_release_date);
        $actualDate = $this->parseDate($entry->actual_release_date ?? $entry->release_date);

        if (!$expectedDate || !$actualDate) {
            return 0;
        }

        // Expected - Actual (positive = delay, negative = early)
        // return $expectedDate->diffInDays($actualDate, false);
       if ($actualDate->gte($expectedDate)) {
        return $this->getBusinessDays($expectedDate, $actualDate);
    } else {
        return -$this->getBusinessDays($actualDate, $expectedDate);
    }
    }

    /**
     * Calculate averages for the entire collection
     */
    public function calculateAverages(Collection $entries): array
    {
        if ($entries->isEmpty()) {
            return $this->getEmptyAverages();
        }

        $calculations = [];
        foreach ($entries as $entry) {
            $calculations[] = $this->calculateAllFormulas($entry);
        }

        $calculationCollection = collect($calculations);

        return [
            'total_estimated_points' => $entries->sum('estimated_points'),
            'total_actual_points' => $calculationCollection->sum(function ($calc) {
                return array_sum(array_column($calc['weekly_points'], 'weekly_points'));
            }),
            'total_weekly_points' => $calculationCollection->sum(fn($c) => array_sum(array_column($c['weekly_points'], 'weekly_points'))),

            // Fixed: Remove filtering to include all entries in averages
            'average_lead_time' => round($calculationCollection->avg('lead_time') ?? 0, 2),
            'average_cycle_time' => round($calculationCollection->avg('cycle_time') ?? 0, 2),
            'average_defects_density' => round($calculationCollection->avg('defects_density') ?? 0, 2),
            'average_story_point_accuracy' => round($calculationCollection->avg('story_point_accuracy') ?? 0, 2),
            'average_release_delay' => round($calculationCollection->avg('release_delay') ?? 0, 2),
        ];
    }

    /**
     * Parse date from various formats
     */
    private function parseDate($date): ?Carbon
    {
        if (!$date || trim($date) === '') {
            return null;
        }

        try {
            // If it's already a Carbon instance
            if ($date instanceof Carbon) {
                return $date;
            }

            // If it's a DateTime instance
            if ($date instanceof \DateTime) {
                return Carbon::instance($date);
            }

            // Parse string date
            return Carbon::parse($date);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Convert time format to decimal hours
     */
    private function convertToDecimalHours($time): float
    {
        if (is_numeric($time)) {
            return (float) $time;
        }

        // Handle time format like "08:50"
        if (preg_match('/(\d{1,2}):(\d{2})/', $time, $matches)) {
            $hours = (int)$matches[1];
            $minutes = (int)$matches[2];
            return $hours + ($minutes / 60);
        }

        return (float) $time;
    }

    /**
     * Get empty averages structure
     */
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

    /**
     * Calculate member-wise statistics
     */
    public function calculateMemberWiseStats(Collection $entries, string $memberField = 'log_owner'): array
    {
        $memberStats = [];
        $members = $entries->pluck($memberField)->filter()->unique();

        foreach ($members as $member) {
            $memberEntries = $entries->where($memberField, $member);
            $memberAverages = $this->calculateAverages($memberEntries);

            $memberStats[$member] = [
                'entry_count' => $memberEntries->count(),
                'total_estimated_points' => $memberAverages['total_estimated_points'],
                'total_actual_points' => $memberAverages['total_actual_points'],
                'total_weekly_points' => $memberAverages['total_weekly_points'],
                'average_lead_time' => $memberAverages['average_lead_time'],
                'average_cycle_time' => $memberAverages['average_cycle_time'],
                'average_defects_density' => $memberAverages['average_defects_density'],
                'average_story_point_accuracy' => $memberAverages['average_story_point_accuracy'],
                'average_release_delay' => $memberAverages['average_release_delay'],
                'capacity' => $memberAverages['total_weekly_points'] * 10, // Assuming 10x multiplier
            ];
        }

        return $memberStats;
    }

    /**
     * Calculate team-level statistics
     */
    public function calculateTeamStats(Collection $entries, float $actualAvailability = null): array
    {
        $uniqueMembers = $entries->pluck('log_owner')->filter()->unique();
        $averages = $this->calculateAverages($entries);

        // Get availability from HR system or pass it as parameter
        $availability = $actualAvailability ?? 96.36; // Use your actual data

        return [
            'total_members' => $uniqueMembers->count(),
            'availability' => $availability, // From external source, not calculated
            'total_points' => $averages['total_weekly_points'],
            'total_estimated_points' => $averages['total_estimated_points'],
            'total_actual_points' => $averages['total_actual_points'],
            'average_story_point_accuracy' => $averages['average_story_point_accuracy'],
        ];
    }

    private function calculateOvertime($entry): float
    {
        // Check if log_type contains "off hour"
        $logType = strtolower($entry->descriptions ?? '');
        if (strpos($logType, 'off hour') !== false) {
            return ($entry->log_hours_decimal ?? 0) * 1.5; // 1.5x for overtime
        }
        return $entry->log_hours_decimal ?? 0;
    }

    public function getExportItemType($entry): string
    {
        $reportedBy = strtolower($entry->reported_by ?? '');
        $itemType = strtolower($entry->item_type ?? '');

        // If reported by is "planned"
        if (strpos($reportedBy, 'planned') !== false) {
            return 'Planned';
        }

        // If reported by is "internal team"
        if (strpos($reportedBy, 'internal team') !== false) {
            return 'Hot Fix';
        }

        // If item type is "bug"
        if (strpos($itemType, 'bug') !== false) {
            return 'Bug';
        }

        // If reported by is not "planned" and item type is "story" or "task"
        if (strpos($itemType, 'story') !== false || strpos($itemType, 'task') !== false) {
            return 'New Request';
        }

        return ucfirst($entry->item_type ?? 'Unknown');
    }

    public function getBusinessDays(Carbon $startDate , Carbon $endDate):float{
$days=0;
$current=$startDate->copy();
while($current->lte($endDate)){
    // skip weekend (Saturday=6 and sunday =0)
    if($current->isWeekend()){
        $days++;
    }
    $current->addDay();
}
return $days; 
    }
}
