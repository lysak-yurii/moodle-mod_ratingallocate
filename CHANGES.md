CHANGELOG
=========

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
