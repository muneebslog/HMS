# Old lab software — test usage counts

Source: live database at `mohsinmedicalcomplex.com/public_html/lab` (`patient_test` joined to `tests`), read on 2026-09-21. Ordered by number of times each test was ordered, most to least. Total orders across all tests: **14,149**.

| Rank | Test | Code | Times ordered | % of total |
|------|------|------|---------------:|-----------:|
| 1 | Complete Blood Count | 1300 | 5,166 | 36.5% |
| 2 | Urine | 1122 | 1,876 | 13.3% |
| 3 | Blood Sugar | 1400 | 1,179 | 8.3% |
| 4 | Blood Group | 2830 | 1,055 | 7.5% |
| 5 | Liver Function Test | 1509 | 1,037 | 7.3% |
| 6 | Screening | 4235 | 957 | 6.8% |
| 7 | Uric Acid | 2704 | 622 | 4.4% |
| 8 | Renal Function Test | 1618 | 454 | 3.2% |
| 9 | Hemoglobin | 1301 | 403 | 2.8% |
| 10 | Lipid Profile | 1900 | 353 | 2.5% |
| 11 | C-reactive Protein | 3407 | 308 | 2.2% |
| 12 | Hepatitis B Surface Antigen | 4232 | 191 | 1.4% |
| 13 | Helicobactor Pylori | 4250 | 156 | 1.1% |
| 14 | Calcium | 1705 | 91 | 0.6% |
| 15 | Cross Match with Screening | 2831 | 67 | 0.5% |
| 16 | Malaria Parasite | 1316 | 66 | 0.5% |
| 17 | Glycated Hemoglobin (HbA1c) | 4802 | 45 | 0.3% |
| 18 | Typhidot | 4801 | 31 | 0.2% |
| 19 | Rheumatoid Factor Test | 4500 | 25 | 0.2% |
| 20 | Coagulation Profile | 1305 | 22 | 0.2% |
| 21 | Anti HCV | 4233 | 15 | 0.1% |
| 22 | Widal Test | 2802 | 7 | 0.05% |
| 23 | Anti HEV IgM | 4201 | 5 | 0.04% |
| 23 | APTT | 1304 | 5 | 0.04% |
| 25 | Bleeding Time | 1306 | 4 | 0.03% |
| 25 | Anti HAV IgM | 4200 | 4 | 0.03% |
| 27 | Clotting Time | 1307 | 3 | 0.02% |
| 28 | INR | 1303 | 1 | 0.01% |
| 28 | Patient's Blood Group | 2010 | 1 | 0.01% |

## Notes

- The top 6 tests (CBC, Urine, Blood Sugar, Blood Group, LFT, Screening) account for **81%** of all historical orders — these are the highest-value ones to fully build out fields/ranges for first in the new HMS lab module.
- "Screening" (rank 6, 957 orders) shares code 4235 with HbsAg and Anti HCV in production HMS, per the earlier duplicate-code finding — worth confirming which of those historical "Screening" entries actually correspond to which real test before using this data for import matching.
- The bottom third of the list (APTT, Bleeding Time, Anti HAV, Clotting Time, INR, Patient's Blood Group) were rarely ordered (≤5 times each) — low priority for detailed field setup.
