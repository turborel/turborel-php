<!--
This description is the series' cover letter (CONTRIBUTING.md, Pull
requests). Each commit message still stands alone, in Linux's form.
Base the series on main, keep it rebased, and on revising say what
changed in a comment, not here.
-->

## What and why

<!-- The problem and its impact, then the approach and the choices made.
Link the issue: "Closes #N". -->

## The series

<!-- One line per commit, in order: what it does. Each passes
`composer verify` on its own. -->

1.

## Testing

<!-- The tests added and what each verifies, by the spec's section or
turborel-client's feature. For a change to the codec, the bytes checked;
for one to connecting, the live tests run against a server. -->

- [ ] `composer verify` passes
- [ ] `composer coverage` passes, the floor held

## Compatibility

<!-- What a program using the driver sees change, and what a server of the
same release sees; "none" if nothing changes. -->

## Performance

<!-- If the change claims any: what was measured, before and after, with
the numbers. Otherwise "none claimed". -->

## Checklist (RULES.md §7)

- [ ] One logical change per commit, each passing `composer verify`
- [ ] The spec's sections cited in the code (R-DOC-4) and in the tests (`Verifies:`, R-TRACE-1)
- [ ] Normal and robustness tests (R-TEST-2), a failing-first test for a fix (R-TEST-4)
- [ ] Every failure a `TurborelException` with its SQLSTATE and SQLCODE (R-ERR-1); no new dependency without a decision (R-DEP-1)
- [ ] CONFORMANCE.md, RULES.md, TODO.md and doc/ updated where they change
