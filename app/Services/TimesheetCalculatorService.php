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
            'weekly_points' => $this->calculateWeeklyPoints($entry),
            'story_point_accuracy' => $this->calculateStoryPointAccuracy($entry),
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

        return $requestedDate->diffInDays($releaseDate);
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

        return $startDate->diffInDays($releaseDate);
    }

    /**
     * Defects Density = 1 if item_type is BUG, 0 otherwise
     */
    private function calculateDefectsDensity($entry): int
    {
        return strtoupper($entry->item_type ?? '') === 'BUG' ? 1 : 0;
    }

    /**
     * Weekly Points = Actual Points OR Log Hours Decimal OR Log Hours
     * Priority: actual_points > log_hours_decimal > log_hours > 0
     */
    private function calculateWeeklyPoints($entry): float
    {
        // First try actual_points
        if (isset($entry->actual_points) && $entry->actual_points > 0) {
            return (float) $entry->actual_points;
        }
        
        // Then try log_hours_decimal 
        if (isset($entry->log_hours_decimal) && $entry->log_hours_decimal > 0) {
            return (float) $entry->log_hours_decimal;
        }
        
        // Then try log_hours (convert if it's in time format)
        if (isset($entry->log_hours) && $entry->log_hours > 0) {
            return $this->convertToDecimalHours($entry->log_hours);
        }
        
        return 0;
    }

    /**
     * Story Point Accuracy = (Estimated Points / Actual Points) * 100
     * If estimated is 0 or actual is 0, return 0
     */
    private function calculateStoryPointAccuracy($entry): float
    {
        $estimated = (float) ($entry->estimated_points ?? 0);
        $actual = $this->calculateWeeklyPoints($entry);
        
        if ($estimated == 0 || $actual == 0) {
            return 0;
        }
        
        // Fixed: Should be estimated/actual, not actual/estimated
        return ($estimated / $actual) * 100;
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

        // Fixed: Expected - Actual (positive = delay, negative = early)
        return $expectedDate->diffInDays($actualDate, false);
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
            'total_actual_points' => $calculationCollection->sum('weekly_points'),
            'total_weekly_points' => $calculationCollection->sum('weekly_points'),
            
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
        
        foreach($members as $member) {
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
}