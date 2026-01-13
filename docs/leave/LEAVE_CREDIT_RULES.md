# Leave Credit Rules & Business Logic

This document describes the leave credit accrual, eligibility, and carryover rules implemented in the PrimeHub system.

## Table of Contents

1. [Credit Accrual](#credit-accrual)
2. [Eligibility Rules](#eligibility-rules)
3. [Regularization & Probation](#regularization--probation)
4. [Carryover Rules](#carryover-rules)
5. [First Regularization Transfer](#first-regularization-transfer)
6. [Edge Cases](#edge-cases)
7. [System Commands](#system-commands)
8. [Troubleshooting](#troubleshooting)

---

## Credit Accrual

### Monthly Rates

Leave credits are accrued monthly based on the employee's role:

| Role Type | Monthly Rate | Annual Total |
|-----------|-------------|--------------|
| Manager Roles (Super Admin, Admin, Team Lead, HR) | 1.5 credits | 18 credits |
| Employee Roles (Agent, IT, Utility) | 1.25 credits | 15 credits |

### Accrual Timing

- **Probationary Employees**: Credits accrue on their **anniversary date** (same day of month as hire date)
- **Regular Employees**: Credits accrue on the **last day of each month**

### Example

An agent hired on March 15, 2025:
- **March 2025**: First credit (1.25) accrues on March 15
- **April 2025**: Credit accrues on April 15 (still probationary)
- **September 2025**: Credit accrues on September 15 (regularization date)
- **October 2025**: Credit accrues on October 31 (now regular, monthly accrual)

---

## Eligibility Rules

### Credit Usage Eligibility

Employees become **eligible to use** their leave credits after completing **6 months** from their hire date.

- **Probationary Period**: First 6 months from hire date
- **Regularization Date**: Hire date + 6 months
- **Eligibility**: Can use accumulated credits after regularization

### Credit Accrual vs Usage

| Period | Credits Accrual | Credits Usage |
|--------|----------------|---------------|
| Probationary (first 6 months) | ✅ Yes | ❌ No |
| After regularization | ✅ Yes | ✅ Yes |

---

## Regularization & Probation

### Key Dates

```
Hire Date --> (6 months) --> Regularization Date
```

### Regularization Status

- **Probationary**: Employee has not yet completed 6 months
- **Regularized**: Employee has completed 6 months from hire date

### Example Timeline

**Employee hired July 10, 2025:**
- Hire Date: July 10, 2025
- Regularization Date: January 10, 2026
- Status on Dec 31, 2025: Probationary
- Status on Jan 10, 2026: Regularized

---

## Carryover Rules

### Regular Year-End Carryover

For employees who are **already regularized** at year-end:

- **Maximum Carryover**: 4 credits
- **Forfeiture**: Any credits above 4 are forfeited
- **Cash Conversion**: Carryover credits can be converted to cash by March 31st

### Example

Employee with 10 credits at end of 2025:
- Carryover to 2026: 4 credits (capped)
- Forfeited: 6 credits

Employee with 3 credits at end of 2025:
- Carryover to 2026: 3 credits (no forfeiture)

### Carryover Timeline

```
Year End (Dec 31) --> Carryover Processed --> Available for Leave/Cash (until Mar 31)
```

---

## First Regularization Transfer

### Different Rules for First-Time Regularization

When an employee regularizes **in a different year** than they were hired:

- **NO CAP**: All accumulated credits transfer (no forfeiture)
- **No Year-End Carryover**: These employees skip the regular carryover process
- **One-Time Only**: First regularization transfer happens only once

### Scenarios

#### Scenario A: Same-Year Hire & Regularization
**Employee hired March 15, 2025** (regularizes September 15, 2025):
- Both events occur in 2025
- At year-end 2025: Regular carryover rules apply (max 4)
- No special first regularization transfer needed

#### Scenario B: Different-Year Hire & Regularization
**Employee hired August 20, 2025** (regularizes February 20, 2026):
- Hired in 2025, regularizes in 2026
- At year-end 2025: **SKIPPED** (still probationary)
- Upon regularization (Feb 20, 2026): **ALL** 2025 credits transfer (no cap)

### Transfer Timing

```
Hire (Year X) --> Regularization (Year X+1) --> First Reg Transfer (all credits)
```

### Key Business Logic

1. Employees hired in Year X who regularize in Year X+1 do NOT get year-end carryover
2. Instead, they get first regularization transfer when they regularize
3. First regularization transfer = ALL accumulated credits (no 4-credit cap)
4. This only happens once per employee

---

## Edge Cases

### Termination

- Inactive employees (`is_active = false`) are excluded from:
  - Regular carryover processing
  - First regularization transfers
  - Monthly accrual jobs
- Unused credits remain in the system for audit purposes

### Hire Date Changes

When an admin corrects an employee's hire date:

1. **Validation**: System checks for existing credits and carryovers
2. **Warnings**: Admin is notified of potential impacts
3. **Recalculation**: Credits may need to be recalculated
4. **First Regularization**: May need to be deleted and reprocessed

### Rehires

Currently, the system does not track rehire status. If an employee is terminated and rehired:
- They should be set up as a new employee
- Previous leave credits are not restored
- New probationary period begins

---

## System Commands

### Daily Scheduled Jobs

| Command | Schedule | Description |
|---------|----------|-------------|
| `leave-credits:accrue` | Daily at 6 AM | Accrues monthly credits for all eligible users |
| `leave-credits:first-regularization` | Daily at 6:30 AM | Processes first regularization transfers |

### Year-End Processing

```bash
# Process year-end carryover (run manually or scheduled)
php artisan leave-credits:carryover --from-year=2025

# Dry run to see what would be processed
php artisan leave-credits:carryover --from-year=2025 --dry-run
```

### Audit Command

```bash
# Run full audit for current year
php artisan leave-credits:audit

# Audit specific year
php artisan leave-credits:audit --year=2025

# Audit specific user
php artisan leave-credits:audit --user=123

# Detailed output
php artisan leave-credits:audit --detailed

# Attempt to fix issues
php artisan leave-credits:audit --fix
```

### Audit Checks

1. **Pending + Carryover Conflict**: Users shouldn't have both pending first reg AND carryover
2. **Carryover Cap Violations**: Non-first-reg carryovers should not exceed 4 credits
3. **Missing Credits**: Users missing expected credit records
4. **Duplicate Carryovers**: Multiple carryovers for same year combination
5. **Invalid First Regularization**: First reg flag for same-year hire/regularization
6. **Negative Balances**: Credit balances should never be negative
7. **Inactive Users with Credits**: Informational check for terminated employees with credits

---

## Troubleshooting

### User Shows Wrong Carryover Amount

1. Check if user was hired in a different year than regularization
2. If yes, they should have first regularization (no cap)
3. If carryover shows capped amount, run first regularization command

```bash
php artisan leave-credits:first-regularization --user=USER_ID
```

### Year-End Carryover Not Processed

1. Verify year-end carryover command was run
2. Check if user was probationary (should be skipped)
3. Check if user was inactive (should be skipped)

### Credits Not Accruing

1. Verify user has `hired_date` set
2. Verify user is `is_approved = true`
3. Verify user is `is_active = true`
4. Check accrual date logic (anniversary vs end-of-month)

### First Regularization Not Applied

1. Check if user meets criteria:
   - Hired in Year X
   - Regularized in Year X+1
   - Has reached regularization date
   - No existing first regularization record
2. Run first regularization command manually if needed

---

## Database Tables

### leave_credits

Stores monthly credit accrual records.

| Column | Description |
|--------|-------------|
| user_id | Employee ID |
| year | Year of credit |
| month | Month of credit (1-12) |
| credits_earned | Credits accrued |
| credits_used | Credits used |
| credits_balance | Net balance |

### leave_credit_carryovers

Stores carryover and first regularization transfers.

| Column | Description |
|--------|-------------|
| user_id | Employee ID |
| from_year | Source year |
| to_year | Destination year |
| credits_from_previous_year | Total credits from source year |
| carryover_credits | Credits carried over |
| forfeited_credits | Credits forfeited (if capped) |
| is_first_regularization | True for first-time regularization transfer |
| regularization_date | Date of regularization (if applicable) |
| cash_converted | True if converted to cash |

---

## Activity Logging

All credit operations are logged using Spatie Activity Log:

| Event | Description |
|-------|-------------|
| `credit_accrued` | Monthly credit accrual |
| `credits_deducted` | Leave request approved |
| `credits_restored` | Leave request cancelled |
| `year_end_carryover` | Year-end carryover processed |
| `first_regularization_transfer` | First regularization transfer created |
| `first_regularization_transfer_updated` | Existing carryover converted to first reg |

Logs can be viewed in the Activity Logs admin page.

---

## Related Documentation

- [Notification System](../notification/NOTIFICATION_SYSTEM.md)
- [Authorization Quick Reference](../authorization/QUICK_REFERENCE.md)
- [Database Schema](../database/)

---

*Last updated: January 2026*
