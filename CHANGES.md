CHANGELOG
=========

Ratingallocate 5.0.0 +diversity.5 (fork)
------------------

- Add per-activity option `diversityequalsize` ("Group sizes" / "Fill all groups
  to the same size"), shown only while "Balance groups by" is set. Default on,
  which is the behaviour so far. Switched off, no choice has a minimum size, each
  takes at most its own maximum, and participants who rated are only placed in a
  choice they rated; the mix by profile field is still aimed for within those.
  Whoever fits into none of their rated choices stays unallocated instead of
  being placed elsewhere. Participants without any rating are placed wherever
  there is room, as before.
- "Distribute unallocated" follows the option as well: with equal sizes off it
  fills up to each choice's maximum and may use any choice with room.
- Add per-activity option `diversitypriority` ("Priority"), shown only while
  "Balance groups by" is set: "Mix of the groups first, then ratings" (default,
  the behaviour so far) or "Ratings first, then mix of the groups". With ratings
  first, the mix only decides between allocations that are equally good for the
  ratings.
- Add per-activity option `diversityminsize` ("Minimum group size"), available
  while equal sizes are off. A choice that would get fewer participants is
  cancelled and the allocation is solved again without it in the same run; its
  participants move to another choice they rated or stay unallocated. Choices
  below the minimum are listed on the activity page after the run.
- `compute_distribution()` and `size_window()` take an optional `$equalsize`
  argument, `compute_distribution()` optional `$ratingsfirst` and `$minmembers`
  ones, and it also returns the `unplaced` user ids and the `cancelled` choice
  ids; without the arguments nothing changes.

Ratingallocate 5.0.0 +diversity.4 (fork)
------------------

- The downloads of the ratings/allocations table and of the allocations table now
  always contain the profile field the activity balances by (Department or
  Institution), even when the site-wide "download user fields" setting does not
  include it. With balancing off the columns are as before.
- Fix "Distribute unallocated" locking the activity when balancing is switched on
  after a standard run that left more participants over than there are places:
  the room is now checked before the background task is queued and reported as an
  error notification, like the main algorithm does. If the task still meets the
  problem, it logs it and ends instead of failing and staying queued, which had
  kept the buttons disabled as "distribution running".

Ratingallocate 5.0.0 +diversity.3 (fork)
------------------

- "Distribute unallocated" now goes through the same department-aware solver
  while "Balance groups by" is set, instead of the upstream fill-up logic which
  ignores the profile field. Everyone already allocated stays where they are;
  only the leftovers are placed, into the groups still missing their value.
  Measured on a 428-student course after a voters-only run: 264 covered pairs
  with the old fill, 274 with this one, and group sizes 12-13 instead of 9-13.
- `compute_distribution()` takes an optional `$fixed` argument (choiceid =>
  already seated userids) for this; without it nothing changes.
- Hide "Distribute by filling up" while balancing is on: both actions run the
  same top-up there, so offering two buttons for one behaviour only confuses.
  With balancing off, both are shown and behave as before.

Ratingallocate 5.0.0 +diversity.2 (fork)
------------------

- Add per-activity option `diversityskipunrated` ("Students without ratings"),
  shown only while "Balance groups by" is set. When ticked, participants who
  submitted no rating are left unallocated instead of being placed, and can be
  distributed afterwards with "Distribute unallocated" or by hand. Default off.
- The coverage report now describes the population the allocation actually ran
  on, so skipped non-raters are not counted as missed coverage.
- Rewrite the "Balance groups by" help: it no longer claims unconditionally that
  every student is placed, states that coverage is ranked *above* ratings rather
  than weighted against them, and mentions the total-capacity requirement.
- Warn on the activity page *before* the algorithm is started when the choices
  cannot hold every participant, naming the shortfall.
- Report the two "not enough room" conditions as an error notification on the
  activity page instead of a fatal Moodle error page. Other exceptions still
  surface as before, so genuine faults are not hidden.
- Add the missing `diversityinfeasible` string: the solver could throw it, but it
  had no English text and would have been shown as `[[diversityinfeasible]]`.
- Add `lang/de` with German translations of the fork's own strings. The official
  German pack is loaded afterwards and still wins for every string it defines.
- Sort the `diversity*` strings alphabetically (moodle-cs lang file ordering).
- Note for site admins: on real data, skipping non-raters made the result *worse*
  for the students who did rate (measured on a 428-student course: 173 → 146
  first choices, 5 → 38 students placed in a group they had not rated). The
  option exists for course rules such as "no rating, no automatic place", not as
  a quality improvement; the help text says so.

Ratingallocate 5.0.0 +diversity.1 (fork)
------------------

> **This is a maintained fork of `learnweb/moodle-mod_ratingallocate`.**
> Do **not** reinstall or update it from the Moodle plugin directory — doing so
> will overwrite the department-aware allocation feature. Sync upstream fixes with
> `git merge upstream/main` instead (see README, "Maintained fork" section).

- Add opt-in per-activity option `diversityfield` (Off / Department / Institution).
  When enabled, distribution uses an exact minimum-cost-flow solver with lower
  bounds (`solver/mincost_lowerbound_distributor.php`): every student is placed and
  every group is filled to a balanced size, while maximising the number of groups
  that contain at least one member of each value of the chosen profile field.
- Report which (group, field-value) pairs could not be covered and why
  (mathematically impossible vs. group-visibility blocked).
- Default is Off, in which case allocation behaviour is unchanged from upstream.

Ratingallocate 5.0.0 (2026-02-25)
------------------

- New Versioning Scheme (starting with 5.0.0) (see README.md)
- Prevent errors because of faulty DB records (#308, #316, #330)
- Drop support for Moodle 4.1
- Support new course overview page in Moodle 5.0+ (see #333)
- Activity information for Moodle 5.1 activity chooser (see #335)
- Performance improvements (see #305)
- Adjust tint monologo icon color (#318)
- Overall maintenance work to improve code quality

Thanks to all contributors to this release:

- @bluetom (Thomas Niedermaier)
- @dlmsr (Daniel Meißner)
- @aneno-m-e (Noémie Ariste)
- @lucaboesch (Luca Bösch)
- @TamaroWalter (Tamaro Walter)
