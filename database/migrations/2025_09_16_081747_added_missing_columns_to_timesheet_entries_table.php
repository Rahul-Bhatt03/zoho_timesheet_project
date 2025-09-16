<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddedMissingColumnsToTimesheetEntriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->float('lead_time')->nullable();
            $table->float('cycle_time')->nullable();
            $table->float('defects_density')->nullable();
            $table->json('weekly_points')->nullable(); // storing JSON array
            $table->json('story_point_accuracy')->nullable();
            $table->integer('release_delay')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->dropColumn([
                'lead_time',
                'cycle_time',
                'defects_density',
                'weekly_points',
                'story_point_accuracy',
                'release_delay',
            ]);
        });
    }
}
