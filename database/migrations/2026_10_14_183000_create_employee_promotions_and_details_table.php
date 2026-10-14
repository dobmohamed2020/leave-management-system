<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add promotion fields to employees table
        Schema::table('employees', function (Blueprint $table) {
            $table->date('promotion_date')->nullable()->after('grade');
            $table->string('promotion_decision_number', 100)->nullable()->after('promotion_date');
        });

        // Create employee_promotions history table
        Schema::create('employee_promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('grade');
            $table->string('entitlement_grade', 50)->nullable();
            $table->date('promotion_date');
            $table->string('decision_number', 100)->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['employee_id', 'promotion_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_promotions');

        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['promotion_date', 'promotion_decision_number']);
        });
    }
};
