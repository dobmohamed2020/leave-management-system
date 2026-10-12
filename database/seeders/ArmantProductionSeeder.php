<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ArmantProductionSeeder extends Seeder
{
    /**
     * Seed production accounts for Armant:
     * - 3 Administration accounts:
     *   1. مدير الإدارة (armant.manager@armant-schools.edu)
     *   2. شؤون عاملين (armant.hr@armant-schools.edu)
     *   3. مسؤول الإجازات (armant.leaves@armant-schools.edu)
     * - 1 Manager account per school (مدير مدرسة)
     * - Removes all specialist/extra user accounts
     * - Cleans all entered/demo employees and their balances
     */
    public function run(): void
    {
        $this->call(ArmantSchoolsSeeder::class);
    }
}
