<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_balances', 'used_carried')) {
                $table->decimal('used_carried', 5, 1)->default(0)->after('carried_over');
            }
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            if (! Schema::hasColumn('leave_requests', 'deduct_from_carried')) {
                $table->boolean('deduct_from_carried')->default(false)->after('leave_type_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leave_balances', function (Blueprint $table) {
            if (Schema::hasColumn('leave_balances', 'used_carried')) {
                $table->dropColumn('used_carried');
            }
        });

        Schema::table('leave_requests', function (Blueprint $table) {
            if (Schema::hasColumn('leave_requests', 'deduct_from_carried')) {
                $table->dropColumn('deduct_from_carried');
            }
        });
    }
};
