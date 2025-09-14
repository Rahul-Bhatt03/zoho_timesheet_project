<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class TimesheetEntry extends Model
{
    protected $guarded = [];
   
    // Cast the calculated fields to appropriate types
    protected $casts = [
        'log_hours_decimal' => 'float',
        'estimated_points' => 'float',
        'actual_points' => 'float',
        'weekly_points' => 'float',
        'lead_time' => 'float',
        'cycle_time' => 'float',
        'defects_density' => 'float',
        'story_point_accuracy' => 'float',
        'release_delay' => 'float',
        'total_workhours' => 'float',
        'work_hours_per_owner' => 'float',
        'log_date' => 'datetime',
        'requested_date' => 'datetime',
        'start_date' => 'datetime',
        'release_date' => 'datetime',
        'expected_start_date' => 'datetime',
        'expected_release_date' => 'datetime',
        'actual_start_date' => 'datetime',
        'actual_release_date' => 'datetime',
        'created_on' => 'datetime',
        'last_modified' => 'datetime',
    ];

    // Mutators to handle date parsing if needed
    public function setLogDateAttribute($value)
    {
        $this->attributes['log_date'] = $this->parseDate($value);
    }

    public function setRequestedDateAttribute($value)
    {
        $this->attributes['requested_date'] = $this->parseDate($value);
    }

    public function setStartDateAttribute($value)
    {
        $this->attributes['start_date'] = $this->parseDate($value);
    }

    public function setReleaseDateAttribute($value)
    {
        $this->attributes['release_date'] = $this->parseDate($value);
    }

    public function setExpectedStartDateAttribute($value)
    {
        $this->attributes['expected_start_date'] = $this->parseDate($value);
    }

    public function setExpectedReleaseDateAttribute($value)
    {
        $this->attributes['expected_release_date'] = $this->parseDate($value);
    }

    public function setActualStartDateAttribute($value)
    {
        $this->attributes['actual_start_date'] = $this->parseDate($value);
    }

    public function setActualReleaseDateAttribute($value)
    {
        $this->attributes['actual_release_date'] = $this->parseDate($value);
    }

    public function setCreatedOnAttribute($value)
    {
        $this->attributes['created_on'] = $this->parseDate($value);
    }

    public function setLastModifiedAttribute($value)
    {
        $this->attributes['last_modified'] = $this->parseDate($value);
    }

    private function parseDate($value)
    {
        if (empty($value) || $value instanceof Carbon) {
            return $value;
        }

        try {
            return Carbon::parse($value);
        } catch (\Exception $e) {
            return null;
        }
    }
}