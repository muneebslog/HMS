<?php

namespace App\Http\Controllers\Lab;

use App\Http\Controllers\Controller;
use App\Models\LabTest;
use App\Services\LabReportBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LabTestReportPreviewController extends Controller
{
    /**
     * Show how a test will look on a printed report, filled with sample values.
     */
    public function __invoke(Request $request, LabTest $labTest, LabReportBuilder $builder): View
    {
        $gender = $request->query('gender') === 'female' ? 'female' : 'male';

        $section = $builder->buildSection(
            $labTest,
            $builder->sampleValues($labTest),
            $gender,
            30,
            __('Sample result comment entered by the lab.'),
        );

        return view('lab.reports.report', [
            'header' => [
                'title' => __('Laboratory Report'),
                'number' => '000000',
                'mrn' => 'MRN000000',
                'patient_name' => __('Sample Patient'),
                'age_sex' => __(':age Years / :sex', ['age' => 30, 'sex' => ucfirst($gender)]),
                'phone' => '0300-0000000',
                'referred_by' => __('Self'),
                'sample_date' => now()->format('d M Y, g:i A'),
                'report_date' => now()->format('d M Y, g:i A'),
            ],
            'sections' => $section ? [$section] : [],
            'remarks' => null,
            'banner' => $section
                ? __('Preview with sample values — layout: :layout', ['layout' => $section['layout']->label()])
                : __('This test has no fields yet, so nothing would print.'),
        ]);
    }
}
