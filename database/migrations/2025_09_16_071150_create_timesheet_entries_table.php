<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTimesheetEntriesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('timesheet_entries', function (Blueprint $table) {
            $table->id();
             $table->string('item_id')->nullable();
            $table->string('item_name')->nullable();
            $table->string('meeting_title')->nullable();
            $table->string('log_title')->nullable();
            $table->string('log_type')->nullable();
            $table->string('log_hours')->nullable();
            $table->string('log_hours_for_calculation')->nullable();
            $table->string('log_hours_for_calculation_1')->nullable();
            $table->string('approved_by')->nullable();
            $table->dateTime('created_on')->nullable();
            $table->string('created_by')->nullable();
            $table->string('updated_by')->nullable();
            $table->text('reason_for_rejection')->nullable();
            $table->string('project')->nullable();
            $table->string('log_owner')->nullable();
            $table->dateTime('log_date')->nullable();
            $table->string('billing_status')->nullable();
            $table->string('approval_status')->nullable();
            $table->text('description')->nullable();
            $table->text('descriptions')->nullable();
            $table->string('log_type_duplicate')->nullable();
            $table->string('release')->nullable();
            $table->dateTime('completed_on')->nullable();
            $table->text('tags')->nullable();
            $table->dateTime('created_on_1')->nullable();
            $table->string('created_by_1')->nullable();
            $table->string('sprint')->nullable();
            $table->string('project_1')->nullable();
            $table->text('user_groups')->nullable();
            $table->string('assignee')->nullable();
            $table->string('status')->nullable();
            $table->string('epic')->nullable();
            $table->string('item_type')->nullable();
            $table->string('priority')->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->string('duration')->nullable();
            $table->decimal('estimation_points', 10, 2)->nullable();
            $table->string('release_1')->nullable();
            $table->string('reported_by')->nullable();
            $table->dateTime('requested_date')->nullable();
            $table->decimal('total_workhours', 10, 2)->nullable();
            $table->decimal('work_hours_per_owner', 10, 2)->nullable();
            $table->string('work_hours_type')->nullable();
            $table->string('created_by_2')->nullable();
            $table->string('updated_by_1')->nullable();
            $table->dateTime('created_on_2')->nullable();
            $table->string('project_2')->nullable();
            $table->string('sprint_name')->nullable();
            $table->string('meeting_type')->nullable();
            $table->string('location')->nullable();
            $table->string('remind_before')->nullable();
            $table->text('agenda')->nullable();
            $table->dateTime('last_modified')->nullable();
            $table->string('project_id')->nullable();
            $table->string('project_name')->nullable();
            $table->string('owner_mail_id')->nullable();
            $table->string('owner_role')->nullable();
            $table->string('project_group')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('timesheet_entries');
    }
}
