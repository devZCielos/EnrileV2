-- ============================================================
-- Migration 013: Fixed Philippine holidays for leave counting
-- Recurring dates are evaluated by Employee/_shared/helpers.php.
-- Movable and proclamation-dependent holidays must be added by HR
-- as one-time entries in the Holidays administration page.
-- ============================================================

INSERT IGNORE INTO holidays (holiday_date, description, is_recurring) VALUES
  ('2026-01-01', 'New Year''s Day', 1),
  ('2026-04-09', 'Araw ng Kagitingan', 1),
  ('2026-06-12', 'Independence Day', 1),
  ('2026-08-21', 'Ninoy Aquino Day', 1),
  ('2026-11-30', 'Bonifacio Day', 1),
  ('2026-12-08', 'Feast of the Immaculate Conception of Mary', 1),
  ('2026-12-25', 'Christmas Day', 1),
  ('2026-12-30', 'Rizal Day', 1);