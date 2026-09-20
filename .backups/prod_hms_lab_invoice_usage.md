# Production HMS — lab test usage counts

Source: live production database at `muneeb@192.168.100.104:/var/www/hms` (`lab_invoice_items` joined to `lab_tests`), read on 2026-09-21. Ordered by number of times each test has been ordered, most to least. Total lab invoice items to date: **1,036**.

| Rank | Test | Code | Times ordered | % of total |
|------|------|------|---------------:|-----------:|
| 1 | CBC | 1300 | 360 | 34.7% |
| 2 | Urine C/E | 1122 | 97 | 9.4% |
| 3 | Blood Sugar F/R | 1400 | 75 | 7.2% |
| 4 | LFT's | 1509 | 70 | 6.8% |
| 5 | HbsAg | 4235 | 63 | 6.1% |
| 6 | BLOOD GROUP | 2830 | 59 | 5.7% |
| 6 | Anti HCV | 4235 | 59 | 5.7% |
| 8 | HB | 1301 | 33 | 3.2% |
| 9 | RFT's | 1618 | 29 | 2.8% |
| 10 | CRP | 3407 | 23 | 2.2% |
| 11 | Uric Acid | 2704 | 22 | 2.1% |
| 11 | Lipid Profile | 1900 | 22 | 2.1% |
| 13 | Urine | 1122 | 19 | 1.8% |
| 14 | HBA1C | 4802 | 17 | 1.6% |
| 15 | Screening | 4235 | 14 | 1.4% |
| 16 | MP | 1316 | 12 | 1.2% |
| 17 | H. Pylori | 4250 | 11 | 1.1% |
| 18 | Cross Match | 2831 | 9 | 0.9% |
| 19 | Ferritin | — | 7 | 0.7% |
| 20 | Blood For C/S | — | 5 | 0.5% |
| 20 | TSH | — | 5 | 0.5% |
| 22 | VIT D3 | — | 3 | 0.3% |
| 23 | Cholesterol | — | 2 | 0.2% |
| 23 | ESR | — | 2 | 0.2% |
| 23 | PT, INR | 1303 | 2 | 0.2% |
| 23 | IRON | — | 2 | 0.2% |
| 23 | Calcium | 1705 | 2 | 0.2% |
| 28 | FSH | — | 1 | 0.1% |
| 28 | Beta HCG | — | 1 | 0.1% |
| 28 | T3 | — | 1 | 0.1% |
| 28 | T4 | — | 1 | 0.1% |
| 28 | HB. ELECTROPHORSIS | — | 1 | 0.1% |
| 28 | VDRL | — | 1 | 0.1% |
| 28 | HIV (leading space) | — | 1 | 0.1% |
| 28 | PCR - HbsAg (QNT) | — | 1 | 0.1% |
| 28 | PCR - Anti HCV (QNT) | — | 1 | 0.1% |
| 28 | RA. FACTOR | 4500 | 1 | 0.1% |
| 28 | BT (Bleeding Time) | 1306 | 1 | 0.1% |
| 28 | Urine Albumin/Protein | — | 1 | 0.1% |
| 28 | ALT (SGPT) | — | 1 | 0.1% |

## Data-quality flags (same pattern as the HbsAg duplicate we already fixed)

- **Code 4235 is shared by three different tests**: HbsAg (63), Anti HCV (59), and Screening (14) — combined 136 orders under one code. These are legitimately different tests, so this isn't a duplicate to merge like `HbsAg`/` HbsAg` was — just a reminder the code field alone can't disambiguate them.
- **"Urine" (19) and "Urine C/E" (97) both use code 1122** — 116 orders combined. Worth checking whether these are meant to be the same test (another accidental split, like the HbsAg one) or genuinely different (e.g. routine urine vs. urine culture & examination panel).
- **" HIV" has a leading space** in its name (only 1 order) — likely another stray duplicate like ` HbsAg` was, low-impact since barely used.

## Cross-reference with the old lab software's usage

Same top tests dominate both systems: CBC is #1 in both (36.5% old vs 34.7% new), Urine/Blood Sugar/Blood Group/LFT round out the top tier in both. This is a good sanity check that the new HMS lab-entry flow is being used consistently with historical ordering patterns.
