# Admin dashboard card layout plan (#2293)

## Outcome

Keep dashboard entity cards readable at narrow widths without weakening the
admin shell's minimum touch-target rule for ordinary authenticated links.

## Work

1. Reproduce the Sheguiandah mobile failure in a real browser and pin the
   computed flex cascade plus title/description geometry.
2. Make the dashboard component explicitly own its card-link axis and child
   alignment with a selector stronger than the shell's generic link rule.
3. Run focused Chromium and Firefox coverage, the admin unit/static gates,
   rebuild the committed distribution, and verify the complete framework.

## Boundaries

- No application CSS override or downstream workaround.
- No change to the global authenticated-link touch-target contract.
- No release, merge, or downstream deployment.
