<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddMissingColumnsToTimesheetEntriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('timesheet_entries', function (Blueprint $table) {
            $table->text('item_detail')->nullable();
            $table->decimal('log_hours_decimal', 10, 2)->nullable();
            $table->string('application')->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('actual_points', 10, 2)->nullable();
            $table->dateTime('release_date')->nullable();
            $table->dateTime('expected_start_date')->nullable();
            $table->dateTime('expected_release_date')->nullable();
            $table->dateTime('actual_start_date')->nullable();
            $table->dateTime('actual_release_date')->nullable();
            $table->string('team_name')->nullable();
            $table->string('zoho_link')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
   public function down()
{
    Schema::table('timesheet_entries', function (Blueprint $table) {
        $table->dropColumn([
            'item_detail',
            'log_hours_decimal',
            'application',
            'remarks',
            'actual_points',
            'release_date',
            'expected_start_date',
            'expected_release_date',
            'actual_start_date',
            'actual_release_date',
            'team_name',
            'zoho_link',
        ]);
    });
}
}
