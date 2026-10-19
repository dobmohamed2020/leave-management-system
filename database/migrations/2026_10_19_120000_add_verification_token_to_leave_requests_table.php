<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->uuid('verification_token')->nullable()->unique()->after('number');
        });

        DB::table('leave_requests')
            ->whereNull('verification_token')
            ->orderBy('id')
            ->lazyById()
            ->each(function ($row) {
                DB::table('leave_requests')
                    ->where('id', $row->id)
                    ->update(['verification_token' => (string) Str::uuid()]);
            });
    }

    public function down(): void
    {
        Schema::table('leave_requests', function (Blueprint $table) {
            $table->dropColumn('verification_token');
        });
    }
};
