<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('get_employee_info', function (Blueprint $table) {
            $table->id(); // optional, remove if not needed
            $table->string('empno', 220)->nullable();
            $table->string('fname', 220)->nullable();
            $table->string('mname', 220)->nullable();
            $table->string('sname', 220)->nullable();
            $table->string('ename', 220)->nullable();
            $table->string('division_name', 220)->nullable();
            $table->string('unit_name', 220)->nullable();
            $table->string('area_assignment_name', 250)->nullable();
            $table->string('eaddress', 220)->nullable();
            $table->string('sex', 13)->nullable();
            $table->date('birthdate')->nullable();
            $table->string('fund_source_name', 200)->nullable();
            $table->string('password', 220)->nullable();
            $table->string('classification_employment_name', 200)->nullable();
            $table->integer('salary_history_id')->nullable();
            $table->string('account_status_name', 25)->nullable();
            $table->string('status_description', 50)->nullable();
            // ✅ Newly added fields
            $table->string('position', 220)->nullable();
            $table->decimal('salary', 15, 2)->nullable();
            $table->timestamps(); // optional
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('get_employee_info');
    }
};
