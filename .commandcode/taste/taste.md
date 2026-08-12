# Taste

- Prefers strictly-scoped work: one thing at a time, no premature infrastructure (explicitly defers routes, auth, service providers, etc. until the plan is settled, e.g. "we are doing only one thing just preparing the apis ... not even adding the routes"). Confidence: 0.85
- When porting/rewriting existing functionality, wants the new version checked against the original's behavior and kept identical ("check the original report ... if its the same keep it"); flag discrepancies rather than silently changing behavior. Confidence: 0.75
- Wants new code to match the existing codebase's coding style and structure ("check the coding styile and how are they all built"). Confidence: 0.7
- Values honest critique and proactive improvement suggestions — asks "is it good right?" / "so there is no improvement?" and expects performance/quality issues to be surfaced and fixed, not glossed over. Confidence: 0.7
- Likes reviewing changed diffs before accepting work ("check the changed diffs please"). Confidence: 0.6
