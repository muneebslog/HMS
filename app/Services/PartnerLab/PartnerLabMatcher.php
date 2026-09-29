<?php

namespace App\Services\PartnerLab;

use App\Models\LabInvoiceItem;
use App\Models\PartnerLabReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Suggests which of our outsourced tests a partner lab report belongs to. The partner
 * retypes names ("KANIZ BB" becomes "KANEEZ BIBI") and often leaves out the age, so
 * this scores name, sex, age, timing and test name, and only suggests; staff confirm.
 */
class PartnerLabMatcher
{
    /**
     * Scores at or above this count as a likely match.
     */
    public const LIKELY_SCORE = 60;

    /**
     * How many days before the partner registered the sample our test may have been billed.
     */
    public const BILLED_WITHIN_DAYS = 5;

    /**
     * Name prefixes the two labs add or drop.
     *
     * @var list<string>
     */
    private const NAME_PREFIXES = ['MRS', 'MR', 'MS', 'MISS', 'M', 'MUHAMMAD', 'MUHAMMED', 'MOHAMMAD', 'MOHAMMED', 'SYED', 'SYEDA', 'DR', 'BABY', 'BIBI', 'BB'];

    /**
     * Outsourced tests a report could belong to: billed shortly before the partner
     * registered the sample, on cases that were not returned.
     *
     * @return Builder<LabInvoiceItem>
     */
    public function candidateQuery(?PartnerLabReport $report = null): Builder
    {
        $registeredAt = $report?->registered_at ? CarbonImmutable::parse($report->registered_at) : null;

        return LabInvoiceItem::query()
            ->where('is_in_house', false)
            ->tracked()
            ->whereHas('labInvoice', fn ($invoice) => $invoice->where('status', '!=', 'returned'))
            ->when($registeredAt, fn ($query) => $query->whereBetween('created_at', [
                $registeredAt->subDays(self::BILLED_WITHIN_DAYS)->startOfDay(),
                $registeredAt->endOfDay(),
            ]))
            ->with('labInvoice.patient');
    }

    /**
     * Candidate tests for a report, best match first, each with its score (0–100).
     *
     * @param  Collection<int, LabInvoiceItem>|null  $candidates
     * @return Collection<int, array{item: LabInvoiceItem, score: int}>
     */
    public function rank(PartnerLabReport $report, ?Collection $candidates = null): Collection
    {
        $candidates ??= $this->candidateQuery($report)->get();

        return $candidates
            ->map(fn (LabInvoiceItem $item) => ['item' => $item, 'score' => $this->score($report, $item)])
            ->sortByDesc('score')
            ->values();
    }

    /**
     * The likely match for a report, if one stands out.
     *
     * @param  Collection<int, LabInvoiceItem>|null  $candidates
     * @return array{item: LabInvoiceItem, score: int}|null
     */
    public function suggest(PartnerLabReport $report, ?Collection $candidates = null): ?array
    {
        $best = $this->rank($report, $candidates)->first();

        return $best !== null && $best['score'] >= self::LIKELY_SCORE ? $best : null;
    }

    /**
     * How well an outsourced test fits a report, 0–100.
     */
    public function score(PartnerLabReport $report, LabInvoiceItem $item): int
    {
        $patient = $item->labInvoice?->patient;
        $score = $this->nameSimilarity((string) $report->patient_name, (string) $patient?->name) * 0.6;

        $gender = $report->normalizedGender();

        if ($gender !== null && filled($patient?->gender)) {
            $score += $gender === strtolower((string) $patient->gender) ? 10 : -30;
        }

        $age = $report->ageInYears();

        if ($age !== null && $patient?->age !== null) {
            $score += abs($age - (int) $patient->age) <= 1 ? 10 : -15;
        }

        if ($report->registered_at !== null && $item->created_at !== null) {
            $registeredAt = CarbonImmutable::parse($report->registered_at);
            $sentAt = CarbonImmutable::parse($item->given_at ?? $item->created_at);
            $score += $sentAt->lte($registeredAt->addHours(2)) && $sentAt->gte($registeredAt->subDays(3)) ? 10 : 0;
        }

        $score += $this->nameSimilarity((string) $report->test_name, (string) $item->test_name) * 0.1;

        return (int) max(0, min(100, round($score)));
    }

    /**
     * Similarity of two names, 0–100, ignoring case, punctuation, common prefixes and
     * spelling differences ("SHAIKH SAB" / "SHAIK SAB", "AWAS" / "AWAIS").
     */
    public function nameSimilarity(string $first, string $second): float
    {
        $firstTokens = $this->nameTokens($first);
        $secondTokens = $this->nameTokens($second);

        if ($firstTokens === [] || $secondTokens === []) {
            return 0.0;
        }

        $whole = $this->similarity(implode('', $firstTokens), implode('', $secondTokens));

        [$shorter, $longer] = count($firstTokens) <= count($secondTokens) ? [$firstTokens, $secondTokens] : [$secondTokens, $firstTokens];
        $tokenScores = array_map(
            fn (string $token) => max(array_map(fn (string $other) => $this->similarity($token, $other), $longer)),
            $shorter,
        );
        $tokens = array_sum($tokenScores) / count($tokenScores);

        return max($whole, $tokens * 0.95);
    }

    /**
     * @return list<string>
     */
    private function nameTokens(string $name): array
    {
        $tokens = preg_split('/\s+/', trim((string) preg_replace('/[^A-Z0-9 ]+/', ' ', strtoupper($name)))) ?: [];
        $tokens = array_values(array_filter($tokens, fn (string $token) => $token !== ''));
        $withoutPrefixes = array_values(array_filter($tokens, fn (string $token) => ! in_array($token, self::NAME_PREFIXES, true)));

        return $withoutPrefixes !== [] ? $withoutPrefixes : $tokens;
    }

    /**
     * Similarity of two words, 0–100, from edit distance and how they sound.
     */
    private function similarity(string $first, string $second): float
    {
        if ($first === $second) {
            return 100.0;
        }

        $length = max(strlen($first), strlen($second));
        $byEdits = (1 - $this->editDistance($first, $second) / $length) * 100;
        $bySound = metaphone($first) !== '' && metaphone($first) === metaphone($second) ? 90.0 : 0.0;

        return max($byEdits, $bySound);
    }

    /**
     * Edit distance counting two swapped neighbouring letters ("KHNA" / "KHAN") as one typo.
     */
    private function editDistance(string $first, string $second): int
    {
        $firstLength = strlen($first);
        $secondLength = strlen($second);
        $distances = [];

        for ($i = 0; $i <= $firstLength; $i++) {
            $distances[$i][0] = $i;
        }

        for ($j = 0; $j <= $secondLength; $j++) {
            $distances[0][$j] = $j;
        }

        for ($i = 1; $i <= $firstLength; $i++) {
            for ($j = 1; $j <= $secondLength; $j++) {
                $cost = $first[$i - 1] === $second[$j - 1] ? 0 : 1;
                $distances[$i][$j] = min($distances[$i - 1][$j] + 1, $distances[$i][$j - 1] + 1, $distances[$i - 1][$j - 1] + $cost);

                if ($i > 1 && $j > 1 && $first[$i - 1] === $second[$j - 2] && $first[$i - 2] === $second[$j - 1]) {
                    $distances[$i][$j] = min($distances[$i][$j], $distances[$i - 2][$j - 2] + 1);
                }
            }
        }

        return $distances[$firstLength][$secondLength];
    }
}
