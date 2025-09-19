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
use Maatwebsite\Excel\Excel as ExcelFormat;

class TimesheetController extends Controller
{
    public function uploadTimesheet(Request $request)
    {
        $request->validate([
          'timesheet' => [
    'required',
    'file',
    function ($attribute, $value, $fail) {
        $extension = strtolower($value->getClientOriginalExtension());
        $mimeType = $value->getMimeType();
        
        $allowedExtensions = ['xlsx', 'xls', 'csv'];
        $csvMimes = ['text/csv', 'text/plain', 'application/csv', 'text/comma-separated-values'];
        $xlsxMimes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
        $xlsMimes = ['application/vnd.ms-excel'];
        
        if (!in_array($extension, $allowedExtensions)) {
            $fail('The file must be a CSV, XLS, or XLSX file.');
            return;
        }
        
        // Validate based on extension and MIME type combination
        if ($extension === 'csv' && !in_array($mimeType, $csvMimes)) {
            Log::warning("CSV file has unexpected MIME type: {$mimeType}, allowing anyway");
            // Don't fail for CSV - MIME detection is unreliable for CSV files
        } elseif ($extension === 'xlsx' && !in_array($mimeType, $xlsxMimes)) {
            $fail('XLSX file has invalid format.');
        } elseif ($extension === 'xls' && !in_array($mimeType, $xlsMimes)) {
            Log::warning("XLS file has unexpected MIME type: {$mimeType}, will validate during processing");
        }
    }
],
            'week_start_date' => 'date',
            'week_end_date' => 'date'
        ]);

        try {
            // Clear previous entries
            TimesheetEntry::truncate();

            $file = $request->file('timesheet');
            $teamName = $request->input('team_name', 'CTS');
            $weekStart = $request->input('week_start_date');
            $weekEnd = $request->input('week_end_date');

            // Get file info
            $fileExtension = strtolower($file->getClientOriginalExtension());
            $filePath = $file->getRealPath();

            Log::info("Processing file: {$file->getClientOriginalName()}, Extension: {$fileExtension}, MIME: {$file->getMimeType()}");

            $importer = new ZohoTimesheetImport($teamName, $fileExtension);

            // Detect actual file format and use appropriate driver
            $driver = $this->detectFileFormat($filePath, $fileExtension, $file->getMimeType());

            Log::info("Using driver: {$driver} for file: {$file->getClientOriginalName()}");

            // Import with detected driver
            switch ($driver) {
                case 'xlsx':
                    Excel::import($importer, $file, null, \Maatwebsite\Excel\Excel::XLSX);
                    break;
                case 'xls':
                    Excel::import($importer, $file, null, \Maatwebsite\Excel\Excel::XLS);
                    break;
                case 'csv':
                    // Configure CSV settings for better compatibility
                    config(['excel.imports.csv.delimiter' => $this->detectCSVDelimiter($filePath)]);
                    config(['excel.imports.csv.enclosure' => '"']);
                    config(['excel.imports.csv.escape' => '\\']);
                    config(['excel.imports.csv.contiguous' => false]);
                    config(['excel.imports.csv.input_encoding' => 'UTF-8']);

                    Excel::import($importer, $file, null, \Maatwebsite\Excel\Excel::CSV);
                    Log::info("Processing CSV file with detected delimiter");
                    break;
                default:
                    // Fallback: let Excel auto-detect
                    Log::warning("Using auto-detection for file: {$file->getClientOriginalName()}");
                    Excel::import($importer, $file);
            }

            // Get all entries with calculated fields
            $entries = TimesheetEntry::all();
            $calculator = new TimesheetCalculatorService();

            // Calculate all formulas for each entry
            $entries->each(function ($entry) use ($calculator) {
                $calculations = $calculator->calculateAllFormulas($entry);

                // Update the entry with calculated values
                $entry->lead_time = $calculations['lead_time'];
                $entry->cycle_time = $calculations['cycle_time'];
                $entry->defects_density = $calculations['defects_density'];
                $entry->weekly_points = $calculations['weekly_points'];
                $entry->story_point_accuracy = round($calculations['story_point_accuracy'], 2);
                $entry->release_delay = $calculations['release_delay'];

                $entry->save();
            });

            // Get updated entries with calculations
            $updatedEntries = TimesheetEntry::all();
            $averages = $calculator->calculateAverages($updatedEntries);

            return response()->json([
                'success' => true,
                'message' => 'Timesheet uploaded and processed successfully using ' . strtoupper($driver) . ' driver',
                'data' => [
                    'total_entries' => $updatedEntries->count(),
                    'entries' => $updatedEntries,
                    'averages' => $averages,
                    'download_available' => true,
                    'week_start_date' => $weekStart,
                    'week_end_date' => $weekEnd,
                    'file_type_processed' => strtoupper($driver)
                ]
            ], 200);
        } catch (\Exception $e) {
            Log::error("Upload error: " . $e->getMessage());
            Log::error("Stack trace: " . $e->getTraceAsString());

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

    public function downloadFormattedTimesheet(Request $request)
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

            // Get week parameters from request
            $weekStart = $request->input('week_start_date');
            $weekEnd = $request->input('week_end_date');

            $filename = 'Miracle_Makers_Weekly_Prod_List_' . date('Y-m-d_His') . '.xlsx';
            Log::info("Generated filename: " . $filename);

            $export = new FormattedTimesheetExport($entries, $weekStart, $weekEnd);

            Log::info("Starting Excel file generation...");

            // Store the file in storage/app/public/exports directory
            $filePath = 'exports/' . $filename;
            Excel::store($export, $filePath, 'public', \Maatwebsite\Excel\Excel::XLSX);

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
private function detectFileFormat(string $filePath, string $extension, string $mimeType): string
{
    Log::info("Detecting format for file - Extension: {$extension}, MIME: {$mimeType}");
    
    // For CSV files - be more lenient with MIME type detection
    if ($extension === 'csv' || 
        in_array($mimeType, ['text/csv', 'text/plain', 'application/csv', 'text/comma-separated-values'])) {
        
        // Additional check: look for CSV-like content
        if ($this->looksLikeCSV($filePath)) {
            Log::info("Confirmed CSV format");
            return 'csv';
        }
    }
    
    // For XLSX files
    if ($extension === 'xlsx' || $mimeType === 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet') {
        if ($this->isZipFile($filePath)) {
            Log::info("Confirmed XLSX format");
            return 'xlsx';
        }
    }
    
    // For XLS files
    if ($extension === 'xls' || $mimeType === 'application/vnd.ms-excel') {
        if ($this->isOLEFile($filePath)) {
            Log::info("Confirmed XLS format");
            return 'xls';
        } else {
            Log::warning("File has .xls extension but is not OLE format, treating as CSV");
            return 'csv';
        }
    }
    
    // Fallback - try to detect by content
    if ($this->looksLikeCSV($filePath)) {
        return 'csv';
    }
    
    return $extension;
}

    /**
     * Check if file is a ZIP file (XLSX format)
     */
    private function isZipFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }

        $signature = fread($handle, 4);
        fclose($handle);

        // ZIP file signature: PK\x03\x04 or PK\x05\x06 or PK\x07\x08
        return substr($signature, 0, 2) === 'PK';
    }

    /**
     * Check if file is an OLE file (XLS format)
     */
    private function isOLEFile(string $filePath): bool
    {
        if (!file_exists($filePath)) {
            return false;
        }

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            return false;
        }

        $signature = fread($handle, 8);
        fclose($handle);

        // OLE file signature
        $oleSignature = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
        return $signature === $oleSignature;
    }
    /**
 * Detect CSV delimiter by analyzing the first few lines
 */
private function detectCSVDelimiter(string $filePath): string
{
    if (!file_exists($filePath)) {
        return ','; // Default fallback
    }
    
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return ',';
    }
    
    // Read first few lines to detect delimiter
    $lines = [];
    for ($i = 0; $i < 3 && !feof($handle); $i++) {
        $line = fgets($handle);
        if ($line) {
            $lines[] = $line;
        }
    }
    fclose($handle);
    
    if (empty($lines)) {
        return ',';
    }
    
    // Test common delimiters
    $delimiters = [',', ';', '\t', '|'];
    $delimiterCounts = [];
    
    foreach ($delimiters as $delimiter) {
        $count = 0;
        foreach ($lines as $line) {
            $count += substr_count($line, $delimiter === '\t' ? "\t" : $delimiter);
        }
        $delimiterCounts[$delimiter] = $count;
    }
    
    // Return delimiter with highest count
    $bestDelimiter = array_keys($delimiterCounts, max($delimiterCounts))[0];
    Log::info("Detected CSV delimiter: " . ($bestDelimiter === '\t' ? 'TAB' : $bestDelimiter));
    
    return $bestDelimiter === '\t' ? "\t" : $bestDelimiter;
}
private function looksLikeCSV(string $filePath): bool
{
    if (!file_exists($filePath)) {
        return false;
    }
    
    $handle = fopen($filePath, 'r');
    if (!$handle) {
        return false;
    }
    
    $firstLine = fgets($handle);
    fclose($handle);
    
    if (!$firstLine) {
        return false;
    }
    
    // Check for common CSV characteristics
    $commonDelimiters = [',', ';', "\t", '|'];
    foreach ($commonDelimiters as $delimiter) {
        if (substr_count($firstLine, $delimiter) >= 2) {
            Log::info("File appears to be CSV with delimiter: " . ($delimiter === "\t" ? 'TAB' : $delimiter));
            return true;
        }
    }
    
    return false;
}
}
