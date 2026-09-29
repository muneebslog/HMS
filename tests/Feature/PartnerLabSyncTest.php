<?php

use App\Models\PartnerLabReport;
use App\Services\PartnerLab\TestZonePageParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * Anonymized copies of the Test Zone (NextStep LIMS) pages, keeping their real structure.
 */
function testZoneLoginPage(): string
{
    return <<<'HTML'
        <html><body><form method="post" action="./AdminLogin.aspx" id="form1">
        <input type="hidden" name="__VIEWSTATE" id="__VIEWSTATE" value="login-state" />
        <input type="hidden" name="__VIEWSTATEGENERATOR" value="C2EE9ABB" />
        <input type="hidden" name="__EVENTTARGET" value="" />
        <input name="txtUserName" type="text" id="txtUserName" />
        <input name="txtUserPassword" type="password" id="txtUserPassword" />
        <input type="submit" name="imgBtnLogin" value="Login" id="imgBtnLogin" />
        </form></body></html>
        HTML;
}

function testZoneFrontDesk(string $grid = ''): string
{
    return <<<HTML
        <html><head><title>Front Desk</title></head><body><form method="post" action="./reception.aspx" id="form1">
        <input type="hidden" name="__EVENTTARGET" value="" />
        <input type="hidden" name="__EVENTARGUMENT" value="" />
        <input type="hidden" name="__VIEWSTATE" value="front-desk-state" />
        <input type="hidden" name="__EVENTVALIDATION" value="validation" />
        <select name="ctl00\$MainContent\$ddlBranch"><option selected="selected" value="-1">Please Select</option><option value="4001">Test Zone</option></select>
        <select name="ctl00\$MainContent\$ddlusername"></select>
        <input name="ctl00\$MainContent\$txtusercode" type="text" value="13566" />
        <input name="ctl00\$MainContent\$txtdatefrom" type="text" value="29/09/2026 0:00 AM" />
        <input name="ctl00\$MainContent\$txtdateto" type="text" value="29/09/2026 11:59 PM" />
        <input name="ctl00\$MainContent\$chkheader" type="checkbox" />
        <input type="submit" name="ctl00\$MainContent\$btnSearch" value="Search" />
        <input type="submit" name="ctl00\$MainContent\$btnClear" value="Clear" />
        {$grid}
        </form></body></html>
        HTML;
}

function testZoneCaseRow(int $row, string $caseNo, string $name, string $ageGender, string $registered, string $detail = ''): string
{
    $ctl = sprintf('ctl%02d', $row + 2);

    return <<<HTML
        <tr class="gridview-row">
            <td><input type="checkbox" name="ctl00\$MainContent\$GVData\${$ctl}\$chkreceived1" /></td>
            <td><a href="#" onclick="window.open('patientcomments.aspx?casedetailid=1'); return false;"><img src="icon-list.png" /></a></td>
            <td><a id="MainContent_GVData_lbshow_{$row}" href="javascript:__doPostBack('ctl00\$MainContent\$GVData\${$ctl}\$lbshow','')">Show</a></td>
            <td>{$caseNo}</td><td>0</td><td>11941{$row}8-TZDC</td><td> <span id="MainContent_GVData_lblPatientName_{$row}">{$name}</span> </td><td>{$ageGender}</td><td>&nbsp;</td>
            <td>{$registered}</td><td>&nbsp;</td><td>SELF</td><td>Mohsin Medical Complex (LHR)</td>
            <td><a href="javascript:__doPostBack('ctl00\$MainContent\$GVData\${$ctl}\$lbDuplicate','')">Revisit</a></td>
            <td><a href="javascript:__doPostBack('ctl00\$MainContent\$GVData\${$ctl}\$lbPatientCopy','')">Patient Copy</a></td>
        </tr>
        <tr> <td colspan="100%"> <div id="div1"> <div> {$detail} </div> </div> </td> </tr>
        HTML;
}

function testZoneTestRow(string $detailId, string $code, string $name, string $status, ?string $guid): string
{
    $print = $guid ? "<a id=\"printbtn\" href=\"../offlineReports/labreport.aspx?id={$guid}\" target=\"_blank\">Print</a>" : '';

    return <<<HTML
        <tr class="gridview-row">
            <td><input type="checkbox" name="chkprint1" checked="checked" /></td><td>{$code}</td><td>{$name}</td>
            <td style="font-weight:bold;">{$status}</td><td><span></span></td>
            <td><input name="txtcomments" type="text" /> <a href="javascript:__doPostBack('lbReceived1','')">Update</a></td>
            <td>{$print}</td><td>No</td><td>No</td><td></td>
            <td><a href="#" onclick="popup=window.open('testcomments.aspx?casedetailid={$detailId}'); return false;"><img src="icon-list.png" /></a></td>
        </tr>
        HTML;
}

function testZoneGrid(string $rows): string
{
    return <<<HTML
        <table class="gridview" id="MainContent_GVData"><tbody>
            <tr class="gridview-header"><th>&nbsp;</th><th>&nbsp;</th><th>&nbsp;</th><th>Lab No</th><th>Ref. No</th><th>Patient No</th><th>Patient Name</th><th>Gender</th><th>M.R.</th><th>Registration Date</th><th>Mobile No</th><th>Consultant</th><th>Reference</th><th>&nbsp;</th><th>&nbsp;</th></tr>
            {$rows}
            <tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
        </tbody>
        </table>
        HTML;
}

function testZoneDetail(string $rows): string
{
    return <<<HTML
        <table class="gridview" id="MainContent_GVData_GVDetail_0"><tbody>
            <tr class="gridview-header"><th>Print</th><th>Code</th><th>Name</th><th>Status</th><th>Alert</th><th>Action/Comments</th><th>Print</th><th>Is Print</th><th>Is Delay</th><th>Whats App</th><th>&nbsp;</th></tr>
            {$rows}
            <tr><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td></tr>
        </tbody>
        </table>
        HTML;
}

beforeEach(function () {
    config([
        'services.testzone.enabled' => true,
        'services.testzone.base_url' => 'https://testzone.example.test',
        'services.testzone.username' => 'mohsin',
        'services.testzone.password' => 'secret',
        'services.testzone.days' => 3,
    ]);

    $this->searchPage = testZoneFrontDesk(testZoneGrid(
        testZoneCaseRow(0, '0926-40228', 'BUSHRA', '45 Year(s)/Female', '29/09/2026 02:14 PM')
        .testZoneCaseRow(1, '0926-40236', 'NASIR', '-/Male', '29/09/2026 02:16 PM')
    ));
    $this->bushraPage = testZoneFrontDesk(testZoneGrid(
        testZoneCaseRow(0, '0926-40228', 'BUSHRA', '45 Year(s)/Female', '29/09/2026 02:14 PM', testZoneDetail(
            testZoneTestRow('2041872', '3400', 'RA Factor Quantitative', 'Approved Report', '9D169355-E0D9-4481-8521-5738373B7D97')
        ))
    ));
    $this->nasirPage = testZoneFrontDesk(testZoneGrid(
        testZoneCaseRow(1, '0926-40236', 'NASIR', '-/Male', '29/09/2026 02:16 PM', testZoneDetail(
            testZoneTestRow('2041883', '4605', 'Ig E (Immunoglobulin E)', 'Test In Process', null)
        ))
    ));
});

/**
 * Fake the portal: login, Front Desk search, and one page per expanded case.
 */
function fakeTestZone(object $test, bool $loginWorks = true): void
{
    Http::fake(function (Request $request) use ($test, $loginWorks) {
        $url = $request->url();
        $data = $request->data();

        if (str_contains($url, 'AdminLogin.aspx')) {
            return Http::response($request->method() === 'POST' && $loginWorks ? '<html>Welcome</html>' : testZoneLoginPage());
        }

        if (str_contains($url, 'reception.aspx')) {
            return Http::response(match (true) {
                $request->method() === 'GET' => testZoneFrontDesk(),
                isset($data['ctl00$MainContent$btnSearch']) => $test->searchPage,
                ($data['__EVENTTARGET'] ?? '') === 'ctl00$MainContent$GVData$ctl02$lbshow' => $test->bushraPage,
                ($data['__EVENTTARGET'] ?? '') === 'ctl00$MainContent$GVData$ctl03$lbshow' => $test->nasirPage,
                default => testZoneFrontDesk(),
            });
        }

        return Http::response('not found', 404);
    });
}

test('the parser reads the case list and a case\'s tests', function () {
    $parser = app(TestZonePageParser::class);

    $cases = $parser->cases($this->searchPage);

    expect($cases)->toHaveCount(2)
        ->and($cases[0])->toMatchArray([
            'event_target' => 'ctl00$MainContent$GVData$ctl02$lbshow',
            'case_no' => '0926-40228',
            'patient_name' => 'BUSHRA',
            'age' => '45 Year(s)',
            'gender' => 'Female',
            'reference' => 'Mohsin Medical Complex (LHR)',
        ])
        ->and($cases[0]['registered_at']->format('Y-m-d H:i'))->toBe('2026-09-29 14:14')
        ->and($cases[1]['age'])->toBeNull();

    expect($parser->tests($this->bushraPage))->toBe([[
        'partner_test_id' => '2041872',
        'code' => '3400',
        'name' => 'RA Factor Quantitative',
        'status' => 'Approved Report',
        'report_path' => '../offlineReports/labreport.aspx?id=9D169355-E0D9-4481-8521-5738373B7D97',
    ]]);
});

test('postback form fields skip buttons, unticked boxes and empty dropdowns like a browser', function () {
    $fields = app(TestZonePageParser::class)->formFields(testZoneFrontDesk());

    expect($fields)->toHaveKeys(['__VIEWSTATE', '__EVENTVALIDATION', 'ctl00$MainContent$ddlBranch', 'ctl00$MainContent$txtusercode'])
        ->and($fields['ctl00$MainContent$ddlBranch'])->toBe('-1')
        ->and($fields)->not->toHaveKeys(['ctl00$MainContent$btnSearch', 'ctl00$MainContent$btnClear', 'ctl00$MainContent$ddlusername', 'ctl00$MainContent$chkheader']);
});

test('the sync logs in, searches and stores every test, marking ready reports', function () {
    fakeTestZone($this);

    $this->artisan('partner-lab:sync')
        ->expectsOutputToContain('Checked 2 case(s), opened 2: 2 new test(s), 1 newly ready report(s).')
        ->assertSuccessful();

    $ready = PartnerLabReport::query()->where('partner_test_id', '2041872')->sole();
    $pending = PartnerLabReport::query()->where('partner_test_id', '2041883')->sole();

    expect($ready->only(['source', 'partner_case_no', 'patient_name', 'patient_age', 'patient_gender', 'test_code', 'test_name', 'status']))->toBe([
        'source' => 'testzone',
        'partner_case_no' => '0926-40228',
        'patient_name' => 'BUSHRA',
        'patient_age' => '45 Year(s)',
        'patient_gender' => 'Female',
        'test_code' => '3400',
        'test_name' => 'RA Factor Quantitative',
        'status' => 'Approved Report',
    ])
        ->and($ready->report_url)->toBe('https://testzone.example.test/offlineReports/labreport.aspx?id=9D169355-E0D9-4481-8521-5738373B7D97')
        ->and($ready->isReady())->toBeTrue()
        ->and($pending->isReady())->toBeFalse();

    Http::assertSent(fn (Request $request) => str_contains($request->url(), 'AdminLogin.aspx')
        && $request->method() === 'POST'
        && $request['txtUserName'] === 'mohsin'
        && $request['txtUserPassword'] === 'secret'
        && $request['__VIEWSTATE'] === 'login-state');
    Http::assertSent(fn (Request $request) => isset($request['ctl00$MainContent$btnSearch'])
        && $request['ctl00$MainContent$txtdatefrom'] === now()->subDays(2)->format('d/m/Y').' 0:00 AM'
        && $request['__VIEWSTATE'] === 'front-desk-state');
    Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'labreport.aspx'));
});

test('a later sync records when a pending report becomes ready and skips finished cases', function () {
    fakeTestZone($this);
    $this->artisan('partner-lab:sync')->assertSuccessful();

    $this->nasirPage = str_replace(['Test In Process', '<td></td><td>No</td>'], ['Print', '<td><a href="../offlineReports/labreport.aspx?id=A2D214B8">Print</a></td><td>No</td>'], $this->nasirPage);
    fakeTestZone($this);

    $this->artisan('partner-lab:sync')
        ->expectsOutputToContain('Checked 2 case(s), opened 1: 0 new test(s), 1 newly ready report(s).')
        ->assertSuccessful();

    expect(PartnerLabReport::query()->where('partner_test_id', '2041883')->sole()->isReady())->toBeTrue();
    Http::assertNotSent(fn (Request $request) => ($request['__EVENTTARGET'] ?? '') === 'ctl00$MainContent$GVData$ctl02$lbshow');
});

test('a failed login stores nothing and reports the failure', function () {
    fakeTestZone($this, loginWorks: false);

    $this->artisan('partner-lab:sync')
        ->expectsOutputToContain('Test Zone login failed')
        ->assertFailed();

    expect(PartnerLabReport::count())->toBe(0);
});

test('the sync does nothing until it is switched on with credentials', function () {
    config(['services.testzone.password' => null]);
    Http::fake();

    $this->artisan('partner-lab:sync')
        ->expectsOutputToContain('Test Zone sync is off')
        ->assertSuccessful();

    Http::assertNothingSent();
});
