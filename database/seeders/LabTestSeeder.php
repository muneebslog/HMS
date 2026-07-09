<?php

namespace Database\Seeders;

use App\Models\LabTest;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class LabTestSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $path = database_path('data/labtests.json');

        if (! File::exists($path)) {
            return;
        }

        /** @var array{tests: list<array<string, mixed>>} $data */
        $data = json_decode(File::get($path), true);

        foreach ($data['tests'] as $test) {
            LabTest::updateOrCreate(
                ['test_name' => $test['test_name']],
                [
                    'test_code' => $test['test_code'],
                    'test_price' => $test['rate'],
                    'sample' => $test['sample'],
                    'time_required' => $test['reports_time'],
                    'is_in_house' => ($test['sourcing'] ?? 'inhouse') === 'inhouse',
                ]
            );
        }
    }
}
