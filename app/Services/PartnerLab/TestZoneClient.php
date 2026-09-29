<?php

namespace App\Services\PartnerLab;

use Carbon\CarbonInterface;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the Test Zone (NextStep LIMS) portal without a browser: logs in, searches
 * the Front Desk and expands cases, replaying the ASP.NET postbacks a browser would send.
 * It only reads: it never ticks "received", edits comments or re-registers a patient.
 */
class TestZoneClient
{
    private const LOGIN_PATH = '/Security/AdminLogin.aspx';

    private const FRONT_DESK_PATH = '/BusinessOperations/reception.aspx';

    private CookieJar $cookies;

    private bool $loggedIn = false;

    public function __construct(private TestZonePageParser $parser)
    {
        $this->cookies = new CookieJar;
    }

    /**
     * Whether the sync is switched on and has credentials.
     */
    public static function isConfigured(): bool
    {
        return (bool) config('services.testzone.enabled')
            && filled(config('services.testzone.username'))
            && filled(config('services.testzone.password'));
    }

    /**
     * Log in with the configured account.
     *
     * @throws RuntimeException when the portal rejects the login or cannot be reached
     */
    public function login(): void
    {
        $loginPage = $this->get(self::LOGIN_PATH);

        $response = $this->post(self::LOGIN_PATH, [
            ...$this->parser->formFields($loginPage),
            'txtUserName' => (string) config('services.testzone.username'),
            'txtUserPassword' => (string) config('services.testzone.password'),
            'imgBtnLogin' => 'Login',
        ]);

        if ($this->parser->isLoginPage($response)) {
            throw new RuntimeException('Test Zone login failed: check TESTZONE_USERNAME and TESTZONE_PASSWORD.');
        }

        $this->loggedIn = true;
    }

    /**
     * Search the Front Desk for cases registered in the date range. Returns the result
     * page, which the case postbacks are made from.
     */
    public function searchCases(CarbonInterface $from, CarbonInterface $to): string
    {
        $this->ensureLoggedIn();

        $frontDesk = $this->get(self::FRONT_DESK_PATH);

        $html = $this->post(self::FRONT_DESK_PATH, [
            ...$this->parser->formFields($frontDesk),
            'ctl00$MainContent$txtdatefrom' => $from->format('d/m/Y').' 0:00 AM',
            'ctl00$MainContent$txtdateto' => $to->format('d/m/Y').' 11:59 PM',
            'ctl00$MainContent$btnSearch' => 'Search',
        ]);

        $this->guardAgainstLoggedOut($html);

        return $html;
    }

    /**
     * Expand one case from a search result page and return the page with its tests.
     */
    public function expandCase(string $searchPage, string $eventTarget): string
    {
        $html = $this->post(self::FRONT_DESK_PATH, [
            ...$this->parser->formFields($searchPage),
            '__EVENTTARGET' => $eventTarget,
            '__EVENTARGUMENT' => '',
        ]);

        $this->guardAgainstLoggedOut($html);

        return $html;
    }

    /**
     * Turn a report link from the portal into a full URL.
     */
    public function absoluteUrl(string $path): string
    {
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $path = preg_replace('#^(\.\./|\./)+#', '', $path);

        return $this->baseUrl().'/'.ltrim((string) $path, '/');
    }

    /**
     * Download a report PDF. The portal serves reports by their link alone.
     * Note: the portal records the report as printed when it is downloaded.
     *
     * @throws RuntimeException when the download is not a PDF
     */
    public function downloadReport(string $url): string
    {
        $body = $this->request()->get($this->absoluteUrl($url))->throw()->body();

        if (! str_starts_with($body, '%PDF')) {
            throw new RuntimeException('The partner lab did not return a PDF for this report.');
        }

        return $body;
    }

    private function ensureLoggedIn(): void
    {
        if (! $this->loggedIn) {
            $this->login();
        }
    }

    private function guardAgainstLoggedOut(string $html): void
    {
        if ($this->parser->isLoginPage($html)) {
            $this->loggedIn = false;

            throw new RuntimeException('Test Zone session ended unexpectedly.');
        }
    }

    private function get(string $path): string
    {
        return $this->request()->get($this->absoluteUrl($path))->throw()->body();
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function post(string $path, array $fields): string
    {
        return $this->request()->asForm()->post($this->absoluteUrl($path), $fields)->throw()->body();
    }

    private function request(): PendingRequest
    {
        return Http::withOptions(['cookies' => $this->cookies])
            ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) HMS-PartnerLabSync')
            ->timeout((int) config('services.testzone.timeout', 30))
            ->connectTimeout(10);
    }

    private function baseUrl(): string
    {
        return rtrim((string) config('services.testzone.base_url'), '/');
    }
}
