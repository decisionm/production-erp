<?php

namespace Database\Seeders;

use App\Modules\Production\Models\Shift;
use Illuminate\Database\Seeder;

class ShiftSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $shifts = [
            ['name' => 'Morning', 'start_time' => '06:00', 'end_time' => '14:00'],
            ['name' => 'Afternoon', 'start_time' => '14:00', 'end_time' => '22:00'],
            ['name' => 'Night', 'start_time' => '22:00', 'end_time' => '06:00'],
        ];

        foreach ($shifts as $shift) {
            Shift::query()->firstOrCreate(['name' => $shift['name']], $shift);
        }
    }
}
