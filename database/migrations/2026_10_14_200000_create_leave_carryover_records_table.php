<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leave_carryover_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->foreignId('leave_type_id')->constrained('leave_types')->cascadeOnDelete();
            $table->unsignedSmallInteger('from_fiscal_year');
            $table->unsignedSmallInteger('to_fiscal_year');
            $table->unsignedSmallInteger('days_carried');
            $table->string('entitlement_grade', 50)->nullable();
            $table->string('source', 20)->default('year_end');
            $table->foreignId('leave_balance_transaction_id')
                ->nullable()
                ->constrained('leave_balance_transactions')
                ->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['employee_id', 'leave_type_id', 'from_fiscal_year', 'to_fiscal_year'],
                'leave_carryover_unique_year_pair'
            );
            $table->index(['employee_id', 'to_fiscal_year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leave_carryover_records');
    }
};
