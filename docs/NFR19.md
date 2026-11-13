# NFR19 — Controlled Election Result Disclosure

## Reopening policy updated on 2026-10-28

At the user's explicit request, administrators may reopen closed elections with recorded votes or abstentions. The earlier blanket prohibition based on participation has been removed.

Reopening still requires a valid administrator approval request for the exact future deadline and a passing vote-integrity check. It preserves existing votes, ballots, receipts, signatures and the prior signed closing report. Previously recorded voters cannot vote again. Results are unpublished during the additional voting period and require explicit release after closure. Cancelled elections cannot reopen.

The regular disclosure controls remain in force: publication, closed-election state, minimum participation and position-level privacy filtering govern result visibility. Authorization and report/export restrictions continue to apply.

**Limitation:** hiding results cannot retract values already viewed or downloaded. Reopening and later releasing updated totals can enable comparison across voting periods and inference about additional votes. The system no longer claims to prevent that comparison by blocking reopening. NFR19's overall acceptance must be evaluated against this revised policy; passing the reopening tests is not a full NFR19 privacy certification.

Earlier regression reports are historical evidence. Any earlier assertion that reopening with participation returns `PRIVACY_REOPEN_BLOCKED` no longer describes the current implementation. Current tests check required approval and verify that an approved reopening succeeds while preserving records.

## Verification after restoring reopening

The updated application suite passed **128 HTTP checks** in a fresh disposable environment. It verified hidden and released result controls across APIs, reports and exports, receipt isolation, duplicate-vote rejection, abstention protections, and the required approval for reopening. See the [supplemental verification report](../REOPENING_TEST_REPORT_2026-10-28.md#supplemental-nfr19-application-verification), [results](evidence/nfr19-reopening-followup-2026-10-28/RESULTS.json), and [log](evidence/nfr19-reopening-followup-2026-10-28/TEST.log).

The application-only run excluded backup/migration/rollback exercises. It does not remove the comparison limitation above or constitute full NFR19 certification.
