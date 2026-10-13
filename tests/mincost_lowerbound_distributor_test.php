<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_ratingallocate;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/ratingallocate/solver/mincost_lowerbound_distributor.php');

/**
 * Unit tests for the department-aware complete-allocation solver.
 *
 * @package    mod_ratingallocate
 * @group      mod_ratingallocate
 * @copyright  2026 Yurii Lysak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mincost_lowerbound_distributor
 */
final class mincost_lowerbound_distributor_test extends \basic_testcase {

    /**
     * Build a choice record.
     *
     * @param int $id
     * @param int $maxsize
     * @return \stdClass
     */
    private function choice($id, $maxsize) {
        return (object) ['id' => $id, 'maxsize' => $maxsize];
    }

    /**
     * Build a rating record.
     *
     * @param int $userid
     * @param int $choiceid
     * @param int $rating
     * @return \stdClass
     */
    private function rating($userid, $choiceid, $rating) {
        return (object) ['userid' => $userid, 'choiceid' => $choiceid, 'rating' => $rating];
    }

    /**
     * Flatten a distribution into the list of allocated user ids.
     *
     * @param array $distribution
     * @return array
     */
    private function assigned_users(array $distribution) {
        return array_merge([], ...array_values($distribution));
    }

    /**
     * A small instance must place every student and mix departments across groups.
     */
    public function test_small_instance_is_complete_and_mixed(): void {
        $choices = [$this->choice(1, 2), $this->choice(2, 2)];
        $userids = [10, 11, 12, 13];
        $deptof = [10 => 'X', 11 => 'X', 12 => 'Y', 13 => 'Y'];

        $solver = new \mincost_lowerbound_distributor();
        $result = $solver->compute_distribution($choices, [], $userids, $deptof, null);
        $dist = $result['distribution'];

        $this->assertCount(4, $this->assigned_users($dist));
        $this->assertCount(2, $dist[1]);
        $this->assertCount(2, $dist[2]);
        foreach ([1, 2] as $group) {
            $depts = array_map(fn($u) => $deptof[$u], $dist[$group]);
            $this->assertContains('X', $depts, "group $group should contain department X");
            $this->assertContains('Y', $depts, "group $group should contain department Y");
        }
    }

    /**
     * The real-world scenario: 400 students, 30 groups, 5 programmes (one with only 20 students),
     * everyone rating the same top-5 groups. Expect a complete, balanced allocation that maximises
     * department coverage, and a report flagging the mathematically impossible gap.
     */
    public function test_large_scenario_maximises_coverage_and_reports_gaps(): void {
        $numgroups = 30;
        $choices = [];
        for ($c = 1; $c <= $numgroups; $c++) {
            $choices[] = $this->choice($c, 14);
        }

        $userids = [];
        $deptof = [];
        $uid = 1000;
        foreach (['A' => 20, 'B' => 95, 'C' => 95, 'D' => 95, 'E' => 95] as $dept => $size) {
            for ($i = 0; $i < $size; $i++) {
                $userids[] = $uid;
                $deptof[$uid] = $dept;
                $uid++;
            }
        }

        // Everyone rates the same five popular groups; groups 6..30 are unrated.
        $ratings = [];
        foreach ($userids as $u) {
            foreach ([1, 2, 3, 4, 5] as $c) {
                $ratings[] = $this->rating($u, $c, 5);
            }
        }

        $solver = new \mincost_lowerbound_distributor();
        $result = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null);
        $dist = $result['distribution'];

        $assigned = $this->assigned_users($dist);
        $this->assertCount(400, $assigned, 'every student is allocated');
        $this->assertCount(400, array_unique($assigned), 'no student is allocated twice');

        foreach ($dist as $group => $members) {
            $this->assertGreaterThanOrEqual(13, count($members), "group $group is not underfilled");
            $this->assertLessThanOrEqual(14, count($members), "group $group is not overfilled");
        }

        $covered = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'E' => 0];
        foreach ($dist as $members) {
            foreach (array_unique(array_map(fn($u) => $deptof[$u], $members)) as $dept) {
                $covered[$dept]++;
            }
        }
        // A has only 20 students, so at most 20 of 30 groups can include it; B..E cover all 30.
        $this->assertSame(20, $covered['A']);
        $this->assertSame(30, $covered['B']);
        $this->assertSame(30, $covered['C']);
        $this->assertSame(30, $covered['D']);
        $this->assertSame(30, $covered['E']);
        $this->assertSame(140, array_sum($covered), 'maximum number of (group, department) pairs is covered');

        $impossible = array_values(array_filter($result['report'], fn($e) => $e['type'] === 'impossible'));
        $this->assertCount(1, $impossible, 'only programme A is impossible to fully cover');
        $this->assertSame('A', $impossible[0]['a']->value);
        $this->assertSame(20, $impossible[0]['a']->count);
        $this->assertSame(30, $impossible[0]['a']->groups);
    }

    /**
     * Student preferences are honoured wherever capacity allows.
     */
    public function test_preferences_are_honoured(): void {
        $choices = [$this->choice(1, 1), $this->choice(2, 1)];
        $userids = [1, 2];
        $deptof = [1 => 'Z', 2 => 'Z'];
        $ratings = [$this->rating(1, 1, 9), $this->rating(2, 2, 9)];

        $solver = new \mincost_lowerbound_distributor();
        $result = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null);
        $dist = $result['distribution'];

        $this->assertSame([1], $dist[1]);
        $this->assertSame([2], $dist[2]);
    }

    /**
     * Insufficient total capacity is reported clearly instead of failing cryptically.
     */
    public function test_insufficient_capacity_is_reported(): void {
        $choices = [$this->choice(1, 2), $this->choice(2, 2)];
        $userids = [1, 2, 3, 4, 5];
        $deptof = array_fill_keys($userids, '');

        $solver = new \mincost_lowerbound_distributor();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/diversityinfeasible_capacity/');
        $solver->compute_distribution($choices, [], $userids, $deptof, null);
    }

    /**
     * Group visibility (usegroups) eligibility is respected.
     */
    public function test_eligibility_restricts_placement(): void {
        $choices = [$this->choice(1, 2), $this->choice(2, 2)];
        $userids = [1, 2, 3, 4];
        $deptof = array_fill_keys($userids, '');
        // Student 2 may only be placed in group 1.
        $eligibility = [1 => [1, 2], 2 => [1], 3 => [1, 2], 4 => [1, 2]];

        $solver = new \mincost_lowerbound_distributor();
        $result = $solver->compute_distribution($choices, [], $userids, $deptof, $eligibility);
        $dist = $result['distribution'];

        $this->assertContains(2, $dist[1], 'restricted student is placed in their only eligible group');
        $this->assertCount(4, $this->assigned_users($dist));
    }

    /**
     * Only students with a positive rating survive the "skip students without ratings" filter.
     */
    public function test_filter_users_with_ratings(): void {
        $userids = [1, 2, 3, 4];
        $ratings = [
            $this->rating(1, 1, 5),
            $this->rating(2, 1, 0),
            $this->rating(2, 2, 0),
            $this->rating(4, 2, 1),
        ];

        $filtered = \mincost_lowerbound_distributor::filter_users_with_ratings($userids, $ratings);

        $this->assertSame([1, 4], $filtered,
            'a rating of 0 does not count as taking part, and order is preserved');
        $this->assertSame($userids, \mincost_lowerbound_distributor::filter_users_with_ratings($userids, [
            $this->rating(1, 1, 5), $this->rating(2, 1, 3), $this->rating(3, 1, 2), $this->rating(4, 1, 1),
        ]), 'nobody is dropped when everyone rated');
        $this->assertSame([], \mincost_lowerbound_distributor::filter_users_with_ratings($userids, []));
    }

    /**
     * A top-up steers the leftovers towards the groups still missing their field value.
     */
    public function test_top_up_covers_the_missing_values(): void {
        $choices = [$this->choice(1, 4), $this->choice(2, 4)];
        // Group 1 already holds two A's, group 2 two B's; one A and one B are left to place.
        $fixed = [1 => [11, 12], 2 => [21, 22]];
        $deptof = [11 => 'A', 12 => 'A', 21 => 'B', 22 => 'B', 31 => 'A', 32 => 'B'];

        $solver = new \mincost_lowerbound_distributor();
        $result = $solver->compute_distribution($choices, [], [31, 32], $deptof, null, $fixed);
        $dist = $result['distribution'];

        $this->assertContains(31, $dist[2], 'the leftover A goes where no A is seated yet');
        $this->assertContains(32, $dist[1], 'the leftover B goes where no B is seated yet');
        $this->assertCount(2, $this->assigned_users($dist), 'only the leftovers are placed');
    }

    /**
     * Seats already taken count towards the target sizes, so a full group receives nobody.
     */
    public function test_top_up_respects_seats_already_taken(): void {
        $choices = [$this->choice(1, 3), $this->choice(2, 3)];
        $fixed = [1 => [11, 12, 13], 2 => [21]];
        $deptof = [11 => 'A', 12 => 'A', 13 => 'A', 21 => 'B', 31 => 'A', 32 => 'A'];

        $solver = new \mincost_lowerbound_distributor();
        $result = $solver->compute_distribution($choices, [], [31, 32], $deptof, null, $fixed);

        $this->assertCount(0, $result['distribution'][1], 'the full group gets nobody');
        $this->assertCount(2, $result['distribution'][2]);
    }

    /**
     * Too few free places for the leftovers is reported as its own error.
     */
    public function test_top_up_without_room_is_reported(): void {
        $choices = [$this->choice(1, 2), $this->choice(2, 2)];
        $fixed = [1 => [11, 12], 2 => [21]];
        $deptof = [11 => 'A', 12 => 'A', 21 => 'B', 31 => 'A', 32 => 'B'];

        $solver = new \mincost_lowerbound_distributor();
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessageMatches('/diversityinfeasible_topup/');
        $solver->compute_distribution($choices, [], [31, 32], $deptof, null, $fixed);
    }

    /**
     * The size window answers "is there room for the leftovers?" exactly as the top-up itself does,
     * so the activity can refuse the action up front instead of queueing a task that fails.
     */
    public function test_size_window_matches_the_top_up_guard(): void {
        $choices = [$this->choice(1, 2), $this->choice(2, 2)];

        // Three of four seats taken, two users left: one place short, as in the test above.
        [, $max] = \mincost_lowerbound_distributor::size_window($choices, 2, [1 => 2, 2 => 1]);
        $this->assertSame([1 => 0, 2 => 1], $max);

        // One user left fits.
        [$min, $max] = \mincost_lowerbound_distributor::size_window($choices, 1, [1 => 2, 2 => 1]);
        $this->assertSame(1, array_sum($max));
        $this->assertSame([1 => 0, 2 => 1], $min);

        // Without seated users it is the plain balanced window.
        [$min, $max] = \mincost_lowerbound_distributor::size_window([$this->choice(1, 9), $this->choice(2, 9)], 5);
        $this->assertSame([1 => 2, 2 => 2], $min);
        $this->assertSame([1 => 3, 2 => 3], $max);
    }

    /**
     * With the filter applied, the balanced size window follows the smaller population.
     */
    public function test_skipping_unrated_shrinks_the_groups(): void {
        $choices = [$this->choice(1, 10), $this->choice(2, 10)];
        $userids = [1, 2, 3, 4, 5, 6];
        $deptof = array_fill_keys($userids, '');
        // Only four of the six rated anything.
        $ratings = [
            $this->rating(1, 1, 2), $this->rating(2, 1, 2),
            $this->rating(3, 2, 2), $this->rating(4, 2, 2),
        ];

        $participants = \mincost_lowerbound_distributor::filter_users_with_ratings($userids, $ratings);
        $solver = new \mincost_lowerbound_distributor();
        $result = $solver->compute_distribution($choices, $ratings, $participants, $deptof, null);
        $dist = $result['distribution'];

        $this->assertCount(4, $this->assigned_users($dist), 'only the students who rated are placed');
        $this->assertCount(2, $dist[1]);
        $this->assertCount(2, $dist[2]);
    }

    /**
     * Without equal sizes, students who rated stay within their own choices and other groups stay empty.
     */
    public function test_unequal_sizes_keep_raters_in_rated_choices(): void {
        $choices = [$this->choice(1, 4), $this->choice(2, 4)];
        $userids = [1, 2, 3, 4];
        $deptof = array_fill_keys($userids, '');
        $ratings = [];
        foreach ($userids as $userid) {
            $ratings[] = $this->rating($userid, 1, 2);
        }
        $solver = new \mincost_lowerbound_distributor();

        $equal = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null);
        $this->assertCount(2, $equal['distribution'][2], 'equal sizes push two students into the unrated group');

        $unequal = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null, null, false);
        $this->assertCount(4, $unequal['distribution'][1]);
        $this->assertCount(0, $unequal['distribution'][2]);
        $this->assertSame([], $unequal['unplaced']);
    }

    /**
     * Without equal sizes, a student whose rated choices are all full is left out, not moved elsewhere.
     */
    public function test_unequal_sizes_leave_out_who_does_not_fit(): void {
        $choices = [$this->choice(1, 2), $this->choice(2, 2)];
        $userids = [1, 2, 3];
        $deptof = array_fill_keys($userids, '');
        $ratings = [$this->rating(1, 1, 2), $this->rating(2, 1, 2), $this->rating(3, 1, 2)];

        $solver = new \mincost_lowerbound_distributor();
        $result = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null, null, false);

        $this->assertCount(2, $result['distribution'][1]);
        $this->assertCount(0, $result['distribution'][2], 'nobody is placed in a group they did not rate');
        $this->assertCount(1, $result['unplaced']);
    }

    /**
     * Without equal sizes, a student without any rating is still placed wherever there is room.
     */
    public function test_unequal_sizes_place_non_raters_anywhere(): void {
        $choices = [$this->choice(1, 2), $this->choice(2, 2)];
        $userids = [1, 2, 3];
        $deptof = array_fill_keys($userids, '');
        $ratings = [$this->rating(1, 1, 2), $this->rating(2, 1, 2)];

        $solver = new \mincost_lowerbound_distributor();
        $result = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null, null, false);

        $this->assertEqualsCanonicalizing([1, 2], $result['distribution'][1]);
        $this->assertSame([3], $result['distribution'][2]);
        $this->assertSame([], $result['unplaced']);
    }

    /**
     * A top-up without equal sizes seats the leftovers in any group with room, whatever they rated.
     */
    public function test_unequal_sizes_top_up_uses_any_room(): void {
        $choices = [$this->choice(1, 2), $this->choice(2, 5)];
        $fixed = [1 => [1, 2]];
        $deptof = [1 => '', 2 => '', 3 => ''];
        $ratings = [$this->rating(1, 1, 2), $this->rating(2, 1, 2), $this->rating(3, 1, 2)];

        $solver = new \mincost_lowerbound_distributor();
        $result = $solver->compute_distribution($choices, $ratings, [3], $deptof, null, $fixed, false);

        $this->assertSame([3], $result['distribution'][2]);
        $this->assertSame([], $result['unplaced']);

        // No target sizes: nothing has to be filled, and each choice may take up to its own maximum.
        [$min, $max] = \mincost_lowerbound_distributor::size_window($choices, 1, [1 => 2], false);
        $this->assertSame([1 => 0, 2 => 0], $min);
        $this->assertSame([1 => 0, 2 => 5], $max);
    }

    /**
     * The priority decides whether the mix or the ratings win when they pull in different directions.
     */
    public function test_ratings_first_reverses_the_priority(): void {
        $choices = [$this->choice(1, 2), $this->choice(2, 2)];
        $userids = [1, 2, 3, 4];
        $deptof = [1 => 'A', 2 => 'A', 3 => 'B', 4 => 'B'];
        // Both A's prefer choice 1, both B's prefer choice 2.
        $ratings = [
            $this->rating(1, 1, 2), $this->rating(1, 2, 1), $this->rating(2, 1, 2), $this->rating(2, 2, 1),
            $this->rating(3, 2, 2), $this->rating(3, 1, 1), $this->rating(4, 2, 2), $this->rating(4, 1, 1),
        ];
        $solver = new \mincost_lowerbound_distributor();

        $mixfirst = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null);
        foreach ($mixfirst['distribution'] as $members) {
            $this->assertEqualsCanonicalizing(['A', 'B'], array_map(fn($userid) => $deptof[$userid], $members));
        }

        $ratingsfirst = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null, null, true, true);
        $this->assertEqualsCanonicalizing([1, 2], $ratingsfirst['distribution'][1]);
        $this->assertEqualsCanonicalizing([3, 4], $ratingsfirst['distribution'][2]);
    }

    /**
     * A choice below the minimum is cancelled; its members move to another choice they rated or stay out.
     */
    public function test_minimum_cancels_small_choices(): void {
        $choices = [$this->choice(1, 5), $this->choice(2, 5), $this->choice(3, 5)];
        $userids = [1, 2, 3, 4, 5];
        $deptof = array_fill_keys($userids, '');
        $ratings = [
            $this->rating(1, 1, 2), $this->rating(2, 1, 2), $this->rating(3, 1, 2),
            // Prefers choice 2 but would be alone there; choice 1 is the fallback.
            $this->rating(4, 2, 2), $this->rating(4, 1, 1),
            // Rated nothing but choice 3, and would be alone there.
            $this->rating(5, 3, 2),
        ];
        $solver = new \mincost_lowerbound_distributor();

        $without = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null, null, false, true);
        $this->assertSame([4], $without['distribution'][2]);
        $this->assertSame([], $without['cancelled']);

        $with = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null, null, false, true, 2);
        $this->assertEqualsCanonicalizing([1, 2, 3, 4], $with['distribution'][1]);
        $this->assertCount(0, $with['distribution'][2]);
        $this->assertCount(0, $with['distribution'][3], 'nobody is left in a cancelled choice');
        $this->assertSame([5], $with['unplaced'], 'a cancelled choice does not turn its only rater into a non-rater');
        $this->assertEqualsCanonicalizing([2, 3], $with['cancelled']);

        // With equal sizes the minimum is ignored: every choice is filled anyway.
        $equal = $solver->compute_distribution($choices, $ratings, $userids, $deptof, null, null, true, true, 3);
        $this->assertSame([], $equal['cancelled']);
        $this->assertCount(5, $this->assigned_users($equal['distribution']));
    }
}
