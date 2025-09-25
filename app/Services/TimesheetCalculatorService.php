<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Collection;

class TimesheetCalculatorService
{
public function calculateAllFormulas($entry, bool $isInProgress = false): array
{
    if ($isInProgress) {
        return [
            'lead_time' => null,
            'cycle_time' => null,
            'defects_density' => $this->calculateDefectsDensity($entry),
            'weekly_points' => $this->calculateWeeklyPoints(collect([$entry])),
            'story_point_accuracy' => $this->calculateStoryPointAccuracy(collect([$entry])),
            'release_delay' => null,
        ];
    }

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
     * Lead Time = NETWORKDAYS.INTL(Requested Date, Actual Release Date)
     * Equivalent to Excel's NETWORKDAYS.INTL function
     */
    private function calculateLeadTime($entry): float
    {
        $requestedDate = $this->parseDate($entry->requested_date);
        $releaseDate = $this->parseDate($entry->actual_release_date ?? $entry->release_date);

        if (!$requestedDate || !$releaseDate) {
            return 0;
        }

        return $this->networkDays($requestedDate, $releaseDate);
    }

    /**
     * Cycle Time = NETWORKDAYS.INTL(Actual Start Date, Actual Release Date)
     * Equivalent to Excel's NETWORKDAYS.INTL function
     */
    private function calculateCycleTime($entry): float
    {
        $startDate = $this->parseDate($entry->actual_start_date ?? $entry->start_date);
        $releaseDate = $this->parseDate($entry->actual_release_date ?? $entry->release_date);

        if (!$startDate || !$releaseDate) {
            return 0;
        }

        return $this->networkDays($startDate, $releaseDate);
    }

    /**
     * Equivalent to Excel's NETWORKDAYS.INTL function
     * Calculates business days between two dates (excluding weekends)
     * This matches Excel's behavior exactly
     */
    private function networkDays(Carbon $startDate, Carbon $endDate): float
    {
        // If end date is before start date, return negative
        if ($endDate->lt($startDate)) {
            return -$this->networkDays($endDate, $startDate);
        }

        // If same date, return 0 (Excel behavior)
        if ($startDate->isSameDay($endDate)) {
            return 1;
        }

        // Use Carbon's diffInWeekdays which excludes weekends
        // Add 1 to include the start date like Excel does
        return $startDate->diffInWeekdays($endDate) + 1;
    }



    /**
     * Defects Density = 1 if item_type is BUG, 0 otherwise
     */
    /**
     * Defects Density = 1 if item_type is Bug/Defect/Hotfix, 0 for Planned/New Request/Off Hour
     */
    private function calculateDefectsDensity($entry): float
    {
        $reportedBy = strtolower($entry->reported_by ?? '');
        $itemType   = strtolower($entry->item_type ?? '');
        $logType    = strtolower($entry->log_type ?? ''); // to handle offhour by log_type

        $isPlanned = strpos($reportedBy, 'planned') !== false;

        // If reported by is planned
        if ($isPlanned) {
            // story or task → Planned = 0
            if (strpos($itemType, 'story') !== false || strpos($itemType, 'task') !== false) {
                return 0;
            }

            // bug → Bug = 1
            if (strpos($itemType, 'bug') !== false) {
                return 1;
            }

            // defect → Defect = 1
            if (strpos($itemType, 'defect') !== false) {
                return 1;
            }

            // hotfix → Hotfix = 1
            if (strpos($itemType, 'hotfix') !== false) {
                return 1;
            }

            // offhour by item_type or log_type → Off Hour = 0
            if (strpos($itemType, 'offhour') !== false || strpos($logType, 'offhour') !== false) {
                return 0;
            }

            // fallback = 0
            return 0;
        }

        // If reported by is NOT planned
        // bug → Bug = 1
        if (strpos($itemType, 'bug') !== false) {
            return 1;
        }

        // defect → Defect = 1
        if (strpos($itemType, 'defect') !== false) {
            return 1;
        }

        // hotfix → Hotfix = 1
        if (strpos($itemType, 'hotfix') !== false) {
            return 1;
        }

        // story or task → New Request = 0
        if (strpos($itemType, 'story') !== false || strpos($itemType, 'task') !== false) {
            return 0;
        }

        // fallback = 0
        return 0;
    }

    private function calculateWeeklyPoints(Collection $entries): array
    {
        // Group by item_id and log_owner combination
        $grouped = $entries->groupBy(function ($entry) {
            return $entry->item_id . '|' . $entry->log_owner;
        });

        $weeklyPoints = [];

        foreach ($grouped as $key => $itemEntries) {
            [$itemId, $owner] = explode('|', $key);

            // Sum all log hours for this item_id + log_owner combination
            $totalHours = $itemEntries->sum('log_hours_decimal');

            $totalMinutes = $totalHours * 60;

            // Convert minutes to points (divide by 240)
            $points = $totalMinutes / 240;

            $weeklyPoints[] = [
                'item_id' => $itemId,
                'log_owner' => $owner,
                'total_hours' => $totalHours, // Added for debugging
                'weekly_points' => $points,
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
     * Release Delay = Actual Release Date - Expected Release Date (in business days)
     * Updated to use networkDays instead of getBusinessDays
     */
    private function calculateReleaseDelay($entry): float
    {
        $expectedDate = $this->parseDate($entry->expected_release_date);
        $actualDate = $this->parseDate($entry->actual_release_date ?? $entry->release_date);

        if (!$expectedDate || !$actualDate) {
            return 0;
        }

        // Use networkDays for consistency with Lead Time and Cycle Time
        if ($actualDate->gte($expectedDate)) {
            return $this->networkDays($expectedDate, $actualDate);
        } else {
            return -$this->networkDays($actualDate, $expectedDate);
        }
    }

    /**
     * Updated getBusinessDays method - now uses networkDays logic
     * This maintains backward compatibility while using correct calculation
     */
    public function getBusinessDays(Carbon $startDate, Carbon $endDate): float
    {
        return $this->networkDays($startDate, $endDate);
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
    $itemType   = strtolower($entry->item_type ?? '');
    $logType    = strtolower($entry->log_type ?? ''); // Convert log_type to lowercase for consistent comparison

    $isPlanned = strpos($reportedBy, 'planned') !== false;

    // If reported by is planned
    if ($isPlanned) {
        // Check log_type first for off hour (case-insensitive)
        if (strpos($logType, 'off hour log type') !== false) {
            return 'Off Hour';
        }

        // Then check item_type for planned category
        if (strpos($itemType, 'story') !== false || strpos($itemType, 'task') !== false) {
            return 'Planned';
        }

        if (strpos($itemType, 'bug') !== false) {
            return 'Bug';
        }

        if (strpos($itemType, 'defect') !== false) {
            return 'Defect';
        }

        if (strpos($itemType, 'hotfix') !== false) {
            return 'Hotfix';
        }

        if (strpos($itemType, 'offhour') !== false) {
            return 'Off Hour';
        }

        return 'Planned';
    }

    // If reported by is NOT planned
    // Check log_type first for off hour (case-insensitive)
    if (strpos($logType, 'off hour log type') !== false) {
        return 'Off Hour';
    }

    // Then check item_type for non-planned category
    if (strpos($itemType, 'bug') !== false) {
        return 'Bug';
    }

    if (strpos($itemType, 'defect') !== false) {
        return 'Defect';
    }

    if (strpos($itemType, 'hotfix') !== false) {
        return 'Hotfix';
    }

    if (strpos($itemType, 'story') !== false || strpos($itemType, 'task') !== false) {
        return 'New Request';
    }

    return ucfirst($entry->item_type ?? '');
}
}
