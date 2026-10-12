<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class ArmantCredentialsSeeder extends Seeder
{
    /**
     * Run the Armant Schools and Credentials Seeder.
     */
    public function run(): void
    {
        $this->call(ArmantSchoolsSeeder::class);
    }
}
