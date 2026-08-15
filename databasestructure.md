# Database Structure

> **Source of truth for agents.** Prefer this file over reading migrations.
> Keep it in sync whenever a migration is created or run (see `AGENTS.md`).
>
> Last reviewed against migrations through `2026_08_14_161607_add_ends_at_vitals_to_services_table`.

Conventions used below:

- `PK` = primary key
- `FK → table` = foreign key (cascade/null behavior noted when non-default)
- `UQ` = unique
- `IDX` = index
- All money fields are `decimal` unless noted
- `timestamps` means `created_at` + `updated_at`

---

## Auth & Users

### `users`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| name | string | |
| email | string | UQ |
| role | string | default `user` (`UserRole` enum) |
| email_verified_at | timestamp | nullable |
| password | string | |
| two_factor_secret | text | nullable |
| two_factor_recovery_codes | text | nullable |
| two_factor_confirmed_at | timestamp | nullable |
| remember_token | string | nullable |
| timestamps | | |

### `password_reset_tokens`
| Column | Type | Notes |
|--------|------|-------|
| email | string | PK |
| token | string | |
| created_at | timestamp | nullable |

### `sessions`
| Column | Type | Notes |
|--------|------|-------|
| id | string | PK |
| user_id | FK → users | nullable, IDX |
| ip_address | string(45) | nullable |
| user_agent | text | nullable |
| payload | longText | |
| last_activity | integer | IDX |

### `passkeys`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | FK → users | cascadeOnDelete, IDX |
| name | string | |
| credential_id | string | UQ |
| credential | json | |
| last_used_at | timestamp | nullable |
| timestamps | | |

### `personal_access_tokens` (Sanctum)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| tokenable_type / tokenable_id | morphs | IDX |
| name | text | |
| token | string(64) | UQ |
| abilities | text | nullable |
| last_used_at | timestamp | nullable |
| expires_at | timestamp | nullable, IDX |
| timestamps | | |

### `role_requests`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | FK → users | cascadeOnDelete |
| requested_role | string | |
| status | string | default `pending` (`RoleRequestStatus`) |
| message | text | nullable |
| admin_notes | text | nullable |
| processed_by | FK → users | nullable, nullOnDelete |
| processed_at | timestamp | nullable |
| timestamps | | |

---

## Core Clinical

### `families`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| phone | string | nullable, UQ |
| timestamps | | |

### `patients`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| family_id | FK → families | nullable, nullOnDelete |
| mrn | string | nullable, UQ |
| name | string | |
| husband_name | string | nullable |
| cnic | string | nullable |
| age | unsignedTinyInteger | nullable |
| gender | string | nullable |
| timestamps | | |

### `doctors`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| name | string | |
| specialization | string | |
| user_id | FK → users | nullable, UQ, nullOnDelete |
| duty_start_time | time | nullable |
| is_active | boolean | default true, IDX |
| payout_daily | boolean | default false |
| get_full_slips | boolean | default false |
| full_slips_count | unsignedInteger | default 0 |
| timestamps | | |

### `services`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| name | string | |
| is_standalone | boolean | default false |
| needs_vitals | boolean | default false |
| ends_at_vitals | boolean | default false — token is marked served after initial vitals |
| needs_medication | boolean | default false |
| follows_doctor_token | boolean | default false — display token is advanced by the doctor instead of the on-screen controls |
| is_drip | boolean | default false — doctor can suggest price during medication |
| appear_on_er | boolean | default false — patient appears on ER station page |
| token_reset_type | string | default `shift` (`TokenResetType`) |
| is_active | boolean | default true, IDX |
| timestamps | | |

### `service_prices`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| service_id | FK → services | |
| doctor_id | FK → doctors | nullable |
| price | decimal(10,2) | |
| doctor_share | decimal(5,2) | nullable |
| token_starts_from | unsignedInteger | default 1 — first token number for this service+doctor queue |
| is_file_check | boolean | default false — tokens for this price show in the TV file-check panel |
| display_layout | string | default `board` (`TokenDisplayLayout`: `board` / `single_token`) — TV layout for this service+doctor queue |
| timestamps | | |

### `drip_charges`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| patient_id | FK → patients | cascadeOnDelete |
| queue_token_id | FK → queue_tokens | nullable, nullOnDelete |
| medication_order_id | FK → medication_orders | nullable, nullOnDelete |
| service_id | FK → services | cascadeOnDelete (`is_drip` service) |
| doctor_id | FK → doctors | nullable, nullOnDelete — share recipient |
| suggested_price | decimal(10,2) | doctor-suggested amount |
| doctor_share | decimal(5,2) | nullable, % snapshot at suggest time |
| status | string | `pending` / `paid`, IDX |
| invoice_id | FK → invoices | nullable, nullOnDelete |
| suggested_by | FK → users | cascadeOnDelete |
| paid_by | FK → users | nullable, nullOnDelete |
| paid_at | timestamp | nullable |
| timestamps | | |

Pending drip bills from doctor medication; reception marks paid on walk-in (invoice + print slip).

### `lab_tests`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| test_name | string | |
| test_code | string | nullable (unique dropped) |
| test_price | decimal(10,2) | |
| sample | string | nullable |
| time_required | string | nullable |
| is_in_house | boolean | default true |
| is_active | boolean | default true, IDX |
| timestamps | | |

### `lab_doctor_shares`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| doctor_id | FK → doctors | UQ, cascadeOnDelete |
| share_percent | decimal(5,2) | |
| timestamps | | |

---

## Billing — OPD / Services

### `invoices`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| patient_id | FK → patients | |
| invoice_number | string | UQ |
| total | decimal(10,2) | |
| status | string | default `pending` |
| payment_mode | string | `cash` or `online`, defaults to `cash` |
| created_by | FK → users | nullable |
| shift_id | FK → shifts | |
| timestamps | | |

### `invoice_items`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| invoice_id | FK → invoices | |
| service_id | FK → services | |
| doctor_id | FK → doctors | nullable |
| service_name | string | snapshot |
| doctor_name | string | nullable, snapshot |
| price | decimal(10,2) | |
| doctor_share | decimal(5,2) | nullable |
| timestamps | | |

---

## Billing — Lab

### `lab_invoices`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| patient_id | FK → patients | |
| invoice_number | string | UQ |
| subtotal | decimal(10,2) | |
| discount_percentage | decimal(5,2) | default 0 |
| discount_amount | decimal(10,2) | default 0 |
| total | decimal(10,2) | |
| status | string | default `pending` |
| payment_mode | string | `cash` or `online`, defaults to `cash` |
| created_by | FK → users | nullable |
| shift_id | FK → shifts | |
| referred_by_doctor_id | FK → doctors | nullable, nullOnDelete |
| doctor_share | decimal(5,2) | nullable, % snapshot at save |
| timestamps | | |

### `lab_invoice_items`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| lab_invoice_id | FK → lab_invoices | |
| lab_test_id | FK → lab_tests | |
| test_name | string | snapshot |
| test_code | string | nullable, snapshot |
| sample | string | nullable, snapshot |
| time_required | string | nullable, snapshot |
| is_in_house | boolean | default true |
| price | decimal(10,2) | |
| timestamps | | |

### `lab_invoice_number_sequences`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| date | date | UQ |
| last_number | unsignedInteger | default 1000 |
| timestamps | | |

### `lab_api_logs`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| lab_invoice_id | FK → lab_invoices | cascadeOnDelete, IDX |
| status | string | IDX |
| request_payload | json | nullable |
| response_body | text | nullable |
| http_status | unsignedSmallInteger | nullable |
| error_message | text | nullable |
| sent_at | timestamp | nullable |
| lab_case_url | string | nullable |
| timestamps | | |

---

## Shifts & Cash

### `shifts`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | FK → users | IDX |
| opened_at | timestamp | |
| closed_at | timestamp | nullable |
| opening_balance | decimal(12,2) | |
| closing_balance | decimal(12,2) | nullable |
| status | string | |
| open_status | generated (MySQL) | see note |
| timestamps | | |

**Constraint:** only one shift may have `status = 'open'` at a time.
- MySQL/MariaDB: generated stored column `open_status` + unique index `shifts_status_open_unique`
- SQLite/PostgreSQL: partial unique index `WHERE status = 'open'`

### `expenses`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| shift_id | FK → shifts | cascadeOnDelete |
| user_id | FK → users | cascadeOnDelete |
| name | string | |
| amount | decimal(12,2) | |
| timestamps | | |
| | | IDX `(shift_id, user_id)` |

### `monthly_expenses`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | FK → users | cascadeOnDelete |
| name | string | |
| amount | decimal(12,2) | |
| expense_date | date | IDX |
| notes | text | nullable |
| timestamps | | |
| | | IDX `(expense_date, user_id)` |

Overhead expenses (electricity, rent, etc.) for monthly reporting. Not linked to shifts and do not affect shift balances.

### `doctor_payouts`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| doctor_id | FK → doctors | |
| date | date | |
| from_date | date | nullable |
| to_date | date | nullable |
| total_amount | decimal(10,2) | |
| share_amount | decimal(10,2) | |
| paid_at | timestamp | |
| created_by | FK → users | nullable |
| shift_id | FK → shifts | nullable, nullOnDelete |
| timestamps | | |

---

## Queue / Tokens

### `service_queues`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| service_id | FK → services | |
| doctor_id | FK → doctors | nullable |
| shift_id | FK → shifts | |
| date | date | |
| reset_type | string | |
| opened_at | timestamp | |
| closed_at | timestamp | nullable |
| status | string | |
| last_token_number | unsignedInteger | default 0 |
| timestamps | | |
| | | IDX `(service_id, doctor_id, status)` |
| | | IDX `(service_id, doctor_id, shift_id, status)` |
| | | IDX `(service_id, doctor_id, date, status)` |

### `queue_tokens`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| service_queue_id | FK → service_queues | |
| invoice_item_id | FK → invoice_items | nullable |
| patient_id | FK → patients | nullable, IDX |
| token_number | unsignedInteger | |
| status | string | default `waiting` |
| origin | string | default `walk_in` |
| arrived_at | timestamp | nullable |
| displayed_at | timestamp | nullable |
| timestamps | | |
| | | UQ `(service_queue_id, token_number)` |
| | | IDX `invoice_item_id` |

### `patient_calls`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| queue_token_id | FK → queue_tokens | IDX |
| called_by | FK → users | IDX |
| called_at | timestamp | IDX |
| notes | text | nullable |
| timestamps | | |

### `vitals`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| queue_token_id | FK → queue_tokens | cascadeOnDelete, IDX (multiple rows per token for rechecks) |
| patient_id | FK → patients | cascadeOnDelete |
| recorded_by | FK → users | cascadeOnDelete |
| temperature | decimal(4,1) | °F |
| bp_systolic | unsignedSmallInteger | mmHg |
| bp_diastolic | unsignedSmallInteger | mmHg |
| bsr | unsignedSmallInteger | nullable, mg/dL (blood sugar random) |
| timestamps | | |

One or more vitals rows per queue token (initial capture plus doctor-requested rechecks). Presence of any row means initial vitals are done. The token status is normally unchanged, but becomes `served` after initial capture when its service has `ends_at_vitals` enabled. Latest row is the current reading.

### `doctor_rechecks`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| queue_token_id | FK → queue_tokens | cascadeOnDelete, IDX with acknowledged_at |
| patient_id | FK → patients | cascadeOnDelete |
| set_by | FK → users | cascadeOnDelete |
| minutes | unsignedSmallInteger | timer length |
| note | string | nullable, e.g. BP recheck |
| due_at | timestamp | IDX |
| notified_at | timestamp | nullable — toast fired once |
| acknowledged_at | timestamp | nullable — doctor cleared |
| vitals_redone_at | timestamp | nullable — reception re-recorded vitals after due |
| timestamps | | |
| | | IDX `(due_at, acknowledged_at, notified_at)` |

Doctor-set minute timers for rechecking a patient (e.g. BP again). Due items toast on the medication page and show **Again** in the queue list. Admin monitors timers and whether vitals were redone after due.

### `medicines`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| name | string | |
| short_form | string | nullable, e.g. `PCM` for search shortcuts |
| unit | string | nullable, e.g. tablet, syrup |
| default_dose | string | default `1-0-0` (`MedicineDose`) |
| default_days | unsignedSmallInteger | default 3 |
| is_active | boolean | default true, IDX |
| timestamps | | |

### `injections`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| name | string | |
| short_form | string | nullable, e.g. `DIC` for search shortcuts |
| default_administration_type | string | default `im` (`InjectionAdministrationType`) |
| is_active | boolean | default true, IDX |
| timestamps | | |

### `drip_bases`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| name | string | |
| default_volume_ml | decimal(8,2) | |
| show_on_er | boolean | default false; displays the drip on its medication order's ER slip |
| is_active | boolean | default true, IDX |
| timestamps | | |

### `health_aides`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| name | string | |
| pin | string | hashed PIN (unique among active aides at validation) |
| is_active | boolean | default true |
| timestamps | | |

Separate from `users` / staff profiles. Used for kiosk PIN identity when delivering medicines, injections, and drips.

### `station_sessions`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| station | string | UQ — `er` / `drip` (`StationType`) |
| health_aide_id | FK → health_aides | nullable, nullOnDelete |
| authenticated_at | timestamp | nullable |
| expires_at | timestamp | nullable |
| last_seen_at | timestamp | nullable |
| timestamps | | |

One row per kiosk station. Updated when a health aide unlocks ER or Drip with PIN; expired when `expires_at <= now()`.

### `medication_orders`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| queue_token_id | FK → queue_tokens | UQ, cascadeOnDelete |
| patient_id | FK → patients | cascadeOnDelete |
| doctor_id | FK → doctors | nullable, nullOnDelete |
| prescribed_by | FK → users | cascadeOnDelete |
| status | string | default `pending` (`MedicationOrderStatus`), IDX |
| complaint_or_diagnosis | text | nullable |
| notes | text | nullable |
| administered_by | FK → users | nullable, nullOnDelete (legacy) |
| administered_by_health_aide_id | FK → health_aides | nullable, nullOnDelete |
| administered_at | timestamp | nullable |
| timestamps | | |

One medication order per queue token. `doctor_id` is null for standalone services (e.g. general checkup) with no assigned doctor. Status becomes `administered` when all medicines and injections are delivered via the health aide kiosk (drips tracked separately).

### `medication_order_medicines`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| medication_order_id | FK → medication_orders | cascadeOnDelete |
| medicine_id | FK → medicines | nullable, restrictOnDelete — null when the doctor writes a medicine not in the catalog |
| dose | string | `MedicineDose` e.g. `1-0-0`, `1-0-1`, `1-1-1` |
| name | string | snapshot of catalog name, or the written custom medicine name |
| delivered_at | timestamp | nullable |
| delivered_by_health_aide_id | FK → health_aides | nullable, nullOnDelete |
| timestamps | | |

### `medication_order_injections`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| medication_order_id | FK → medication_orders | cascadeOnDelete |
| injection_id | FK → injections | nullable, restrictOnDelete — null when the doctor writes an injection not in the catalog |
| administration_type | string | `InjectionAdministrationType` (`im`, `iv`) |
| comment | string | nullable |
| name | string | snapshot of catalog name, or the written custom injection name |
| delivered_at | timestamp | nullable |
| delivered_by_health_aide_id | FK → health_aides | nullable, nullOnDelete |
| timestamps | | |

### `medication_order_drips`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| medication_order_id | FK → medication_orders | cascadeOnDelete |
| drip_base_id | FK → drip_bases | restrictOnDelete |
| name | string | snapshot |
| status | string | default `pending` (`DripLineStatus`: pending/started/done), IDX |
| started_at | timestamp | nullable |
| started_by_health_aide_id | FK → health_aides | nullable, nullOnDelete |
| check_due_at | timestamp | nullable, IDX — started_at + 30 minutes |
| check_notified_at | timestamp | nullable — toast fired once when due |
| done_at | timestamp | nullable |
| done_by_health_aide_id | FK → health_aides | nullable, nullOnDelete |
| done_by_user_id | FK → users | nullable, nullOnDelete — admin mark done |
| timestamps | | |

### `medication_order_drip_additives`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| medication_order_drip_id | FK → medication_order_drips | cascadeOnDelete |
| injection_id | FK → injections | nullable, restrictOnDelete — null when the doctor writes an injection not in the catalog |
| name | string | snapshot of catalog name, or the written custom injection name |
| timestamps | | |

### `sms_logs`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| doctor_id | FK → doctors | nullable, nullOnDelete |
| phone | string | |
| token_number | unsignedInteger | |
| message | text | nullable |
| status | string | IDX |
| provider_response | text | nullable |
| sent_at | timestamp | nullable |
| timestamps | | |
| | | IDX `created_at` |

---

## Procedures / Admissions

### `procedure_types`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| name | string | UQ |
| is_active | boolean | default true |
| requires_birth_certificate | boolean | default false |
| requires_fetal_heart | boolean | default false |
| note_style | string | `operation` or `delivery`, default `operation` |
| timestamps | | |

### `procedure_type_documents`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_type_id | FK → procedure_types | cascadeOnDelete |
| path | string | |
| original_name | string | |
| mime_type | string | |
| sort_order | unsignedInteger | default 0 |
| timestamps | | |

### `rooms`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| number | string | UQ |
| is_active | boolean | default true |
| timestamps | | |

### `procedures`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| patient_id | FK → patients | cascadeOnDelete |
| procedure_type_id | FK → procedure_types | nullable, nullOnDelete |
| name | string | legacy/display name |
| expected_delivery_date | date | nullable |
| full_amount | decimal(12,2) | |
| room_number | string | nullable (legacy; prefer `room_id`) |
| room_id | FK → rooms | nullable, nullOnDelete |
| admitted_at | timestamp | nullable |
| file_printed_at | timestamp | nullable |
| file_printed_by | FK → users | nullable, nullOnDelete |
| consent_completed_at | timestamp | nullable |
| pre_op_completed_at | timestamp | nullable |
| pre_op_done_by | string | nullable |
| pre_op_completed_by | FK → users | nullable, nullOnDelete |
| operation_started_at | timestamp | nullable |
| operation_completed_at | timestamp | nullable |
| post_op_completed_at | timestamp | nullable |
| post_op_completed_by | FK → users | nullable, nullOnDelete |
| discharged_at | timestamp | nullable |
| discharged_by | FK → users | nullable, nullOnDelete |
| doctor_id | FK → doctors | nullable, nullOnDelete |
| created_by | FK → users | cascadeOnDelete |
| shift_id | FK → shifts | cascadeOnDelete |
| timestamps | | |

### `procedure_payments`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | cascadeOnDelete |
| amount | decimal(12,2) | |
| mode | string | `cash` or `online`, defaults to `cash` |
| created_by | FK → users | cascadeOnDelete |
| shift_id | FK → shifts | nullable, nullOnDelete — null when marked paid off-shift |
| timestamps | | |

### `procedure_attachments`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | cascadeOnDelete |
| type | string | `consent`, `pre_op`, `operation`, `post_op`, `anaesthesia`, `other` |
| path | string | local disk |
| original_name | string | |
| mime_type | string | nullable |
| uploaded_by | FK → users | cascadeOnDelete |
| timestamps | | |

### `procedure_vitals`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | cascadeOnDelete |
| recorded_at | timestamp | |
| pulse / bp_systolic / bp_diastolic / resp_rate | unsignedSmallInteger | nullable |
| temp | decimal(4,1) | nullable |
| cvp / iv_fluid / oral_ng / urine / stool / aspirate / drain | string | nullable |
| notes | text | nullable |
| recorded_by | FK → users | cascadeOnDelete |
| timestamps | | |

### `procedure_fetal_hearts`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | cascadeOnDelete |
| recorded_at | timestamp | |
| fhr | unsignedSmallInteger | |
| notes | text | nullable |
| recorded_by | FK → users | cascadeOnDelete |
| timestamps | | |

### `procedure_pre_op_orders`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | UQ, cascadeOnDelete |
| give_bath / provide_hospital_dress / mark_operation_site / shave_and_prepare | boolean | default false |
| npo_from / send_to_ot_at / completed_at | timestamp | nullable |
| blood_pints | unsignedTinyInteger | nullable |
| investigations / pre_medication / other_orders | text | nullable |
| operation_site / done_by | string | nullable |
| completed_by | FK → users | nullable, nullOnDelete |
| timestamps | | |

### `procedure_operation_notes`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | UQ, cascadeOnDelete |
| operated_on | date | nullable |
| started_at / ended_at | time | nullable |
| operation / surgeon / nurse / anaesthesia | string | nullable |
| findings / procedure_text / closure / drain / biopsy | text | nullable |
| recorded_by | FK → users | nullable, nullOnDelete |
| timestamps | | |

### `procedure_delivery_notes`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | UQ, cascadeOnDelete |
| labour_type / procedure_name / obstetrician / assistant / analgesia | string | nullable |
| delivered_at | timestamp | nullable |
| delivery_details / complications | text | nullable |
| labour_first_stage / labour_second_stage / labour_third_stage | string | nullable |
| baby_sex / baby_weight / apgar_score / resuscitated_by | string | nullable |
| recorded_by | FK → users | nullable, nullOnDelete |
| timestamps | | |

### `procedure_post_op_orders`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | UQ, cascadeOnDelete |
| maintain_intake_output | boolean | default false |
| npo_till / completed_at | timestamp | nullable |
| antibiotics / iv_fluids / analgesics / antiemetics / biopsy / others | text | nullable |
| done_by | string | nullable |
| completed_by | FK → users | nullable, nullOnDelete |
| timestamps | | |

### `procedure_progress_notes`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | cascadeOnDelete |
| noted_at | timestamp | |
| note | text | |
| doctor_user_id | FK → users | cascadeOnDelete |
| timestamps | | |

### `procedure_medications`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | cascadeOnDelete |
| form | string | `tab`, `inj`, `drip` |
| medicine_id / injection_id / drip_base_id | FK | nullable, nullOnDelete |
| custom_name / dose / route | string | nullable (custom when outside catalog) |
| notes | text | nullable |
| schedule_type | string | `once_now`, `once_at`, `every_hour`, `now_and_at`, `at_times` |
| schedule_times | json | nullable |
| interval_hours | unsignedSmallInteger | nullable |
| starts_at / ends_at | timestamp | nullable |
| status | string | default `active` |
| prescribed_by | FK → users | cascadeOnDelete |
| timestamps | | |

### `procedure_medication_doses`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_medication_id | FK → procedure_medications | cascadeOnDelete |
| due_at | timestamp | |
| status | string | `pending`, `given`, `skipped` |
| given_at | timestamp | nullable |
| given_by | FK → users | nullable, nullOnDelete |
| notes | text | nullable |
| timestamps | | |

### `procedure_documents`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | cascadeOnDelete |
| kind | string | `discharge_certificate`, `birth_certificate`, `bill` |
| generated_at / printed_at | timestamp | nullable |
| generated_by / printed_by | FK → users | nullable, nullOnDelete |
| path | string | nullable |
| timestamps | | |
| unique | (procedure_id, kind) | |

### `procedure_discharge_details`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| procedure_id | FK → procedures | UQ, cascadeOnDelete |
| blood_group / indication / parity / baby_sex / baby_weight / baby_condition | string | nullable |
| procedure_time | timestamp | nullable |
| rx_text / outcome_summary | text | nullable |
| stitch_removal_date | date | nullable |
| timestamps | | |

---

## Ultrasound

### `ultrasound_reports`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| queue_token_id | FK → queue_tokens | UQ |
| patient_id | FK → patients | |
| doctor_id | FK → doctors | nullable |
| service_queue_id | FK → service_queues | |
| report_date | date | |
| name | string | |
| age | unsignedTinyInteger | nullable |
| fetus_status | string | nullable |
| bpd_meas / bpd_age | string | nullable |
| femur_meas / femur_age | string | nullable |
| ac_meas / ac_age | string | nullable |
| crl_meas / crl_age | string | nullable |
| gest_age | string | nullable |
| edd | string | nullable |
| heart_motion | string | nullable |
| placenta | string | nullable |
| placenta_grade | string | nullable |
| amniotic_fluid | string | nullable |
| presentation | string | nullable |
| lt_ventricular | boolean | default false |
| bpd_level | boolean | default false |
| feral_stomach | boolean | default false |
| kidneys | boolean | default false |
| bladder | boolean | default false |
| spine | boolean | default false |
| bpp | string | nullable |
| conclusion_line1 / conclusion_line2 | string | nullable |
| timestamps | | |

---

## Print Jobs

### `print_jobs`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| invoice_id | FK → invoices | nullable, nullOnDelete |
| lab_invoice_id | FK → lab_invoices | nullable, nullOnDelete |
| shift_id | FK → shifts | nullable, nullOnDelete |
| status | string | default `pending` (`PrintJobStatus`) |
| payload | json | |
| attempts | unsignedTinyInteger | default 0 |
| printed_at | timestamp | nullable |
| failed_at | timestamp | nullable |
| error_message | text | nullable |
| timestamps | | |
| | | IDX `(status, created_at)` |

### `pdf_print_jobs`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | FK → users | cascadeOnDelete (uploader) |
| original_filename | string | |
| disk_path | string | path on `local` disk under `pdf-print-jobs/` |
| copies | unsignedTinyInteger | default 1 |
| status | string | default `pending` (`PrintJobStatus`) |
| attempts | unsignedTinyInteger | default 0 |
| printed_at | timestamp | nullable |
| failed_at | timestamp | nullable |
| error_message | text | nullable |
| timestamps | | |
| | | IDX `(status, created_at)` |

### `drive_folders`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| parent_id | FK → drive_folders | nullable, cascadeOnDelete |
| name | string | |
| created_by | FK → users | cascadeOnDelete |
| timestamps | | |
| | | IDX `parent_id`, UNIQUE `(parent_id, name)` |

### `drive_files`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| folder_id | FK → drive_folders | nullable, cascadeOnDelete (null = root) |
| name | string | display name |
| original_filename | string | |
| disk_path | string | path on `local` disk under `hms-drive/` |
| mime_type | string | |
| size | unsignedBigInteger | bytes |
| tags | json | nullable |
| created_by | FK → users | cascadeOnDelete |
| timestamps | | |
| | | IDX `folder_id`, IDX `name` |

---

## Admin / Ops

### `admin_notifications`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | FK → users | nullable, nullOnDelete |
| type | string | |
| title | string | |
| message | text | |
| read_at | timestamp | nullable |
| actionable_url | string | nullable |
| metadata | json | nullable |
| timestamps | | |
| | | IDX `(type, read_at)`, IDX `created_at` |

### `admin_reports`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| created_by | FK → users | cascadeOnDelete |
| subject | string | |
| status | string | default `open` |
| last_message_at | timestamp | nullable |
| timestamps | | |
| | | IDX `(status, last_message_at)` |

### `admin_report_messages`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| admin_report_id | FK → admin_reports | cascadeOnDelete |
| user_id | FK → users | cascadeOnDelete |
| body | text | |
| timestamps | | |
| | | IDX `(admin_report_id, created_at)` |

### `reception_memos`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| created_by | FK → users | cascadeOnDelete |
| title | string | |
| body | text | |
| color | string | default `amber` |
| timestamps | | |
| | | IDX `created_at` |

### `reception_memo_reads`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| reception_memo_id | FK → reception_memos | cascadeOnDelete |
| user_id | FK → users | cascadeOnDelete |
| read_at | timestamp | |
| timestamps | | |
| | | UQ `(reception_memo_id, user_id)` |

### `kanban_items`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| title | string | |
| description | text | nullable |
| status | string | |
| position | unsignedInteger | default 0 |
| created_by | FK → users | cascadeOnDelete |
| timestamps | | |
| | | IDX `(status, position)` |

### `kanban_item_comments`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| kanban_item_id | FK → kanban_items | cascadeOnDelete |
| user_id | FK → users | cascadeOnDelete |
| content | text | |
| timestamps | | |
| | | IDX `(kanban_item_id, created_at)` |

### `policy_journals`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| created_by | FK → users | cascadeOnDelete |
| title | string | |
| incident | text | |
| resolution | text | |
| policy | text | |
| category | string | nullable, IDX |
| tags | json | nullable |
| effective_date | date | nullable, IDX |
| review_date | date | nullable |
| status | string | default `active`, IDX |
| attachments | json | nullable |
| timestamps | | |

---

## Supervisor Checklist

### `supervisor_checklist_questions`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| question_text | text | |
| sort_order | unsignedInteger | default 0 |
| is_active | boolean | default true |
| timestamps | | |

### `supervisor_checklist_options`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| question_id | FK → supervisor_checklist_questions | cascadeOnDelete |
| option_text | text | |
| is_no | boolean | default false |
| sort_order | unsignedInteger | default 0 |
| is_active | boolean | default true |
| timestamps | | |

### `supervisor_checklist_entries`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| user_id | FK → users | cascadeOnDelete |
| block_starts_at | timestamp | |
| block_ends_at | timestamp | |
| submitted_at | timestamp | |
| timestamps | | |
| | | UQ `(user_id, block_starts_at)` |

### `supervisor_checklist_responses`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| entry_id | FK → supervisor_checklist_entries | cascadeOnDelete |
| question_id | FK → supervisor_checklist_questions | cascadeOnDelete |
| remarks | text | nullable |
| timestamps | | |
| | | UQ `(entry_id, question_id)` |

### `supervisor_checklist_response_option` (pivot)
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| response_id | FK → supervisor_checklist_responses | cascadeOnDelete |
| option_id | FK → supervisor_checklist_options | cascadeOnDelete |
| timestamps | | |
| | | UQ `(response_id, option_id)` as `scr_response_option_unique` |

---

## HR / Employees

### `employees`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| name | string | |
| father_name | string | nullable |
| cnic | string | nullable, UQ |
| date_of_birth | date | nullable |
| sex | string | nullable |
| religion_sect | string | nullable |
| caste | string | nullable |
| marital_status | string | nullable |
| email | string | nullable |
| phone | string | nullable |
| current_address | text | nullable |
| permanent_address | text | nullable |
| emergency_contact | string | nullable |
| languages | string | nullable |
| distance_time_from_hospital | string | nullable |
| designation | string | nullable |
| department | string | nullable, IDX |
| joining_date | date | nullable |
| employment_type | string | default `full_time` |
| status | string | default `active`, IDX |
| notes | text | nullable |
| photo_path | string | nullable |
| undertaking_accepted | boolean | default false |
| undertaking_accepted_at | timestamp | nullable |
| user_id | FK → users | nullable, nullOnDelete |
| doctor_id | FK → doctors | nullable, nullOnDelete |
| created_by | FK → users | |
| timestamps | | |

### `employee_qualifications`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| employee_id | FK → employees | cascadeOnDelete |
| course | string | course / degree / specialization |
| passing_year | unsignedSmallInteger | nullable |
| institution | string | nullable |
| document_path | string | nullable |
| original_name | string | nullable |
| created_by | FK → users | |
| timestamps | | |

### `employee_experiences`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| employee_id | FK → employees | cascadeOnDelete |
| company | string | |
| date_of_joining | date | |
| date_of_leaving | date | nullable |
| reason_for_leaving | text | nullable |
| timestamps | | |

### `employee_documents`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| employee_id | FK → employees | cascadeOnDelete |
| title | string | |
| type | string | default `other`, IDX |
| file_path | string | |
| original_name | string | nullable |
| notes | text | nullable |
| issue_date | date | nullable |
| expiry_date | date | nullable, IDX |
| created_by | FK → users | |
| timestamps | | |

### `employee_todos`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| employee_id | FK → employees | cascadeOnDelete |
| title | string | |
| description | text | nullable |
| due_date | date | |
| completed_at | timestamp | nullable |
| completed_by | FK → users | nullable, nullOnDelete |
| created_by | FK → users | |
| timestamps | | |
| | | IDX `(due_date, completed_at)` |

### `employee_leaves`
| Column | Type | Notes |
|--------|------|-------|
| id | bigint | PK |
| employee_name | string | |
| leave_date | date | |
| replacement_name | string | nullable |
| duty_start_time | time | nullable |
| duty_end_time | time | nullable |
| is_informed | boolean | default false |
| informed_by | string | nullable |
| notes | text | nullable |
| created_by | FK → users | |
| timestamps | | |
| | | UQ `(employee_name, leave_date)` |

---

## Framework (Laravel)

### `cache` / `cache_locks`
Standard Laravel cache tables (`key` PK, `value`/`owner`, `expiration`).

### `jobs` / `job_batches` / `failed_jobs`
Standard Laravel queue tables.

---

## Entity Relationship Overview

```
users ──┬── shifts ──┬── invoices ──── invoice_items ──┬── services
        │            │                                 └── doctors
        │            ├── lab_invoices ──┬── lab_invoice_items ── lab_tests
        │            │                 └── doctors (referred_by)
        │            ├── expenses
        │            ├── doctor_payouts ── doctors
        │            ├── procedures ──┬── patients ── families
        │            │                ├── procedure_types ── procedure_type_documents
        │            │                ├── rooms
        │            │                ├── procedure_payments
        │            │                ├── procedure_attachments
        │            │                ├── procedure_vitals / procedure_fetal_hearts
        │            │                ├── procedure_pre_op_orders / procedure_post_op_orders
        │            │                ├── procedure_operation_notes / procedure_delivery_notes
        │            │                ├── procedure_progress_notes
        │            │                ├── procedure_medications ── procedure_medication_doses
        │            │                ├── procedure_documents
        │            │                └── procedure_discharge_details
        │            ├── service_queues ── queue_tokens ──┬── patient_calls
        │            │                                    ├── vitals
        │            │                                    ├── ultrasound_reports
        │            │                                    ├── drip_charges
        │            │                                    ├── doctor_rechecks
        │            │                                    └── medication_orders ──┬── medication_order_medicines ── medicines / health_aides
        │            │                                                           ├── medication_order_injections ── injections / health_aides
        │            │                                                           └── medication_order_drips ──┬── drip_bases / health_aides / users
        │            │                                                                                       └── medication_order_drip_additives ── injections
        │            └── print_jobs
        │
        ├── health_aides
        ├── station_sessions ── health_aides
        ├── pdf_print_jobs
        ├── drive_folders ──┬── drive_folders (parent)
        │                   └── drive_files
        ├── monthly_expenses
        ├── doctors (optional user_id link) ── lab_doctor_shares
        ├── medicines / injections / drip_bases (catalogs)
        ├── employees ──┬── employee_documents
        │               ├── employee_todos
        │               ├── employee_qualifications
        │               └── employee_experiences
        ├── role_requests
        ├── admin_notifications / admin_reports / reception_memos
        ├── kanban_items ── kanban_item_comments
        ├── policy_journals
        └── supervisor_checklist_entries ── responses ── options
```
