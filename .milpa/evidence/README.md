# Evidence

What this house **measured**, one file per measured change. Nothing writes here for you, on purpose: a
record the house generates without a control proves nothing. Number the files in order:
`0001-what-you-measured.md`, then `0002-...`.

Each file has four parts:

- **Frame**: what changed and the question it answers.
- **Check**: the exact command and what it printed, for example `php bin/coa test`, a `curl` to a route,
  or `php bin/coa routes:list`.
- **Positive control**: the same check against a state where it *must* fail, showing that the check can
  see the defect. `php bin/coa test:baseline` before a change and `php bin/coa test:delta` after it give
  you both halves for the suite.
- **Verdict**: what the check and the control together let you say, and what they do not.

The choices themselves (who decided what, and why) go in `../decisions/`. See GUIDE.md, step 10.
