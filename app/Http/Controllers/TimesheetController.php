<?php

namespace App\Http\Controllers;

use App\Exports\FormattedTimesheetExport;
use App\Imports\ZohoTimesheetImport;
use Illuminate\Http\Request;
use App\Models\TimesheetEntry;
use App\Services\TimesheetCalculatorService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class TimesheetController extends Controller
{
    public function uploadTimesheet(Request $request)
    {
        $request->validate([
            'timesheet' => 'required|file|mimes:xlsx,csv,xls'
        ]);

        try {
            // Clear previous entries
            TimesheetEntry::truncate();

            $file = $request->file('timesheet');
            $teamName = $request->input('team_name', 'CTS');

            // Import using Maatwebsite Excel
            $importer = new ZohoTimesheetImport($teamName);
            Excel::import($importer, $file);

            // Get all entries with calculated fields
            $entries = TimesheetEntry::all();
            $calculator = new TimesheetCalculatorService();

            // Calculate all formulas for each entry
            $entries->each(function ($entry) use ($calculator) {
                $calculations = $calculator->calculateAllFormulas($entry);

                // Update the entry with calculated values - now with proper rounding
                $entry->lead_time = $calculations['lead_time'];
                $entry->cycle_time = $calculations['cycle_time'];
                $entry->defects_density = $calculations['defects_density'];
                $entry->weekly_points = $calculations['weekly_points'];
                $entry->story_point_accuracy = round($calculations['story_point_accuracy'], 2); // Added rounding
                $entry->release_delay = $calculations['release_delay'];

                $entry->save();
            });

            // Get updated entries with calculations
            $updatedEntries = TimesheetEntry::all();
            $averages = $calculator->calculateAverages($updatedEntries);

            // Return success response with download availability flag
            return response()->json([
                'success' => true,
                'message' => 'Timesheet uploaded and processed successfully',
                'data' => [
                    'total_entries' => $updatedEntries->count(),
                    'entries' => $updatedEntries,
                    'averages' => $averages,
                    'download_available' => true // Added this flag
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error processing timesheet: ' . $e->getMessage(),
                'debug' => [
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]
            ], 500);
        }
    }

public function downloadFormattedTimesheet()
{
    try {
        Log::info("=== downloadFormattedTimesheet() START ===");
        
        $entries = TimesheetEntry::all();
        Log::info("Entries fetched. Count: " . $entries->count());
        
        if ($entries->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'No timesheet data available to export'
            ], 404);
        }

        $filename = 'Miracle_Makers_Weekly_Prod_List_' . date('Y-m-d_His') . '.xlsx';
        Log::info("Generated filename: " . $filename);
        
        $export = new FormattedTimesheetExport($entries);
        
        Log::info("Starting Excel file generation...");
        
        // Store the file in storage/app/public/exports directory
        $filePath = 'exports/' . $filename;
        Excel::store($export, $filePath, 'public');
        
        // Get the full path to the stored file
        $fullPath = storage_path('app/public/' . $filePath);
        
        Log::info("Excel file saved to: " . $fullPath);
        
        // Return JSON response with file information
        return response()->json([
            'success' => true,
            'message' => 'Excel file generated successfully',
            'data' => [
                'filename' => $filename,
                'file_path' => $fullPath,
                'download_url' => url('storage/' . $filePath), // Public URL for download
                'file_size' => file_exists($fullPath) ? filesize($fullPath) : 0
            ]
        ], 200);
        
    } catch (\Exception $e) {
        Log::error("FATAL ERROR in downloadFormattedTimesheet(): " . $e->getMessage());
        Log::error("Stack trace: " . $e->getTraceAsString());
        
        return response()->json([
            'success' => false,
            'message' => 'Error generating Excel file: ' . $e->getMessage()
        ], 500);
    }
}

    public function getFormulas()
    {
        try {
            // Return the formula descriptions for documentation
            $formulas = [
                'lead_time' => 'Lead Time = Actual Release Date - Requested Date (in days)',
                'cycle_time' => 'Cycle Time = Actual Release Date - Actual Start Date (in days)',
                'defects_density' => 'Defects Density = 1 if item_type is BUG, 0 otherwise',
                'weekly_points' => 'Weekly Points = Actual Points OR Log Hours Decimal OR Log Hours',
                'story_point_accuracy' => 'Story Point Accuracy = (Estimated Points / Actual Points) * 100',
                'release_delay' => 'Release Delay = Actual Release Date - Expected Release Date (in days)'
            ];

            return response()->json([
                'success' => true,
                'data' => $formulas,
                'message' => 'Formulas retrieved successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving formulas: ' . $e->getMessage()
            ], 500);
        }
    }

    public function updateFormula(Request $request)
    {
        $request->validate([
            'key' => 'required|string',
            'formula' => 'required|string'
        ]);

        try {
            // This would typically update a configuration file or database
            // For now, return success response
            return response()->json([
                'success' => true,
                'data' => [
                    $request->key => $request->formula
                ],
                'message' => 'Formula updated successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error updating formula: ' . $e->getMessage()
            ], 500);
        }
    }

    public function getTimesheetData()
    {
        try {
            $entries = TimesheetEntry::all();

            if ($entries->isEmpty()) {
                return response()->json([
                    'success' => true,
                    'message' => 'No timesheet data available',
                    'data' => [
                        'entries' => [],
                        'averages' => [],
                        'team_stats' => []
                    ]
                ], 200);
            }

            $calculator = new TimesheetCalculatorService();
            $averages = $calculator->calculateAverages($entries);
            $teamStats = $calculator->calculateTeamStats($entries);
            $memberStats = $calculator->calculateMemberWiseStats($entries);

            return response()->json([
                'success' => true,
                'data' => [
                    'entries' => $entries,
                    'averages' => $averages,
                    'team_stats' => $teamStats,
                    'member_stats' => $memberStats,
                    'total_entries' => $entries->count()
                ],
                'message' => 'Timesheet data retrieved successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error retrieving timesheet data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function clearTimesheetData()
    {
        try {
            TimesheetEntry::truncate();

            return response()->json([
                'success' => true,
                'message' => 'Timesheet data cleared successfully'
            ], 200);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error clearing timesheet data: ' . $e->getMessage()
            ], 500);
        }
    }

    public function recalculateFormulas()
    {
        try {
            $entries = TimesheetEntry::all();
            
            if ($entries->isEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'No timesheet data available for recalculation'
                ], 404);
            }

            $calculator = new TimesheetCalculatorService();

            // Recalculate all formulas for each entry
            $entries->each(function ($entry) use ($calculator) {
                $calculations = $calculator->calculateAllFormulas($entry);

                // Update the entry with recalculated values
                $entry->lead_time = $calculations['lead_time'];
                $entry->cycle_time = $calculations['cycle_time'];
                $entry->defects_density = $calculations['defects_density'];
                $entry->weekly_points = $calculations['weekly_points'];
                $entry->story_point_accuracy = round($calculations['story_point_accuracy'], 2);
                $entry->release_delay = $calculations['release_delay'];

                $entry->save();
            });

            $averages = $calculator->calculateAverages($entries);

            return response()->json([
                'success' => true,
                'message' => 'Formulas recalculated successfully',
                'data' => [
                    'total_entries' => $entries->count(),
                    'averages' => $averages
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error recalculating formulas: ' . $e->getMessage()
            ], 500);
        }
    }
}