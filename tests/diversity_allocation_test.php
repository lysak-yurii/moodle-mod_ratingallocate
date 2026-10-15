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

use mod_ratingallocate\db as this_db;
use mod_ratingallocate\task\distribute_unallocated_task;
use mod_ratingallocate_generator;
use PHPUnit\Framework\Attributes\CoversClass;
use stdClass;

defined('MOODLE_INTERNAL') || die();
require_once(__DIR__ . '/generator/lib.php');
require_once(__DIR__ . '/../locallib.php');

/**
 * Integration tests for allocation balanced by a user profile field: the activity settings, the
 * stored allocation, the top-up of unallocated users and the notices shown to the teacher.
 *
 * The solver itself is covered by mincost_lowerbound_distributor_test; these tests go through a
 * real activity, so they also catch mistakes in how the data is loaded and saved.
 *
 * @package    mod_ratingallocate
 * @category   test
 * @group      mod_ratingallocate
 * @copyright  2026 Yurii Lysak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \mod_ratingallocate\ratingallocate
 * @covers     \mincost_lowerbound_distributor
 */
#[CoversClass(ratingallocate::class)]
#[CoversClass(\mincost_lowerbound_distributor::class)]
final class diversity_allocation_test extends \advanced_testcase {

    /** @var stdClass the course */
    private stdClass $course;
    /** @var stdClass editing teacher, who runs the distribution */
    private stdClass $teacher;
    /** @var stdClass the activity record */
    private stdClass $instance;
    /** @var array map of label ('A1', 'B2', ...) => user record */
    private array $students = [];
    /** @var array map of choice title => choice id */
    private array $choiceids = [];

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course();
        $this->teacher = mod_ratingallocate_generator::create_user_and_enrol($this, $this->course, true);
    }

    /**
     * Create the activity, its choices and the students.
     *
     * @param array $settings activity settings, e.g. ['diversityfield' => 'department']
     * @param array $maxsizes map choice title => maxsize
     * @param array $students map label => department; the labels name the users in $this->students
     */
    private function create_activity(array $settings, array $maxsizes, array $students): void {
        $choicedata = [];
        foreach ($maxsizes as $title => $maxsize) {
            $choicedata[] = [
                'title' => $title,
                'explanation' => "Explain $title",
                'maxsize' => $maxsize,
                'active' => true,
            ];
        }
        $this->instance = mod_ratingallocate_generator::create_instance_with_choices(
            $this,
            array_merge(['course' => $this->course], $settings),
            $choicedata
        );
        foreach ($this->get_ratingallocate()->get_rateable_choices() as $choice) {
            $this->choiceids[$choice->title] = (int) $choice->id;
        }
        foreach ($students as $label => $department) {
            $user = $this->getDataGenerator()->create_user(['department' => $department]);
            $this->getDataGenerator()->enrol_user($user->id, $this->course->id, 'student');
            $this->students[$label] = $user;
        }
        $this->setUser($this->teacher);
    }

    /**
     * A fresh activity object for the teacher, read from the database, so changed settings are seen.
     *
     * @return ratingallocate
     */
    private function get_ratingallocate(): ratingallocate {
        global $DB;
        $record = $DB->get_record(this_db\ratingallocate::TABLE, ['id' => $this->instance->id]);
        return mod_ratingallocate_generator::get_ratingallocate($record);
    }

    /**
     * Change activity settings directly in the database.
     *
     * @param array $settings
     */
    private function update_settings(array $settings): void {
        global $DB;
        $DB->update_record(this_db\ratingallocate::TABLE, (object) array_merge(['id' => $this->instance->id], $settings));
    }

    /**
     * Save a "yes" for each of the given choices.
     *
     * @param string $label student label
     * @param string[] $titles choice titles
     */
    private function rate(string $label, array $titles): void {
        $data = [];
        foreach ($titles as $title) {
            $data[] = ['choiceid' => $this->choiceids[$title], 'rating' => 1];
        }
        $this->get_ratingallocate()->save_ratings_to_db($this->students[$label]->id, $data);
    }

    /**
     * Allocate a student by hand.
     *
     * @param string $label
     * @param string $title
     */
    private function allocate(string $label, string $title): void {
        $this->get_ratingallocate()->add_allocation($this->choiceids[$title], $this->students[$label]->id);
    }

    /**
     * The stored allocation as choice title => sorted student labels.
     *
     * @return array
     */
    private function allocation_by_choice(): array {
        $labelof = [];
        foreach ($this->students as $label => $user) {
            $labelof[$user->id] = $label;
        }
        $titleof = array_flip($this->choiceids);
        $result = array_fill_keys(array_keys($this->choiceids), []);
        foreach ($this->get_ratingallocate()->get_allocations() as $allocation) {
            $result[$titleof[$allocation->choiceid]][] = $labelof[$allocation->userid];
        }
        foreach ($result as &$labels) {
            sort($labels);
        }
        return $result;
    }

    /**
     * The departments present in each choice, as choice title => sorted unique letters.
     *
     * @return array
     */
    private function departments_by_choice(): array {
        $result = [];
        foreach ($this->allocation_by_choice() as $title => $labels) {
            $departments = array_unique(array_map(fn($label) => $label[0], $labels));
            sort($departments);
            $result[$title] = $departments;
        }
        return $result;
    }

    /**
     * The algorithm status as stored, not as cached in an activity object.
     *
     * @return int
     */
    private function stored_algorithm_status(): int {
        global $DB;
        return (int) $DB->get_field(this_db\ratingallocate::TABLE, 'algorithmstatus', ['id' => $this->instance->id]);
    }

    /**
     * The settings are read from the activity record with their documented defaults, and values
     * the feature does not support switch it off.
     */
    public function test_settings_getters(): void {
        $this->create_activity([], ['G1' => 5], []);

        $ratingallocate = $this->get_ratingallocate();
        $this->assertSame('', $ratingallocate->get_diversity_field(), 'off by default');
        $this->assertFalse($ratingallocate->get_diversity_skip_unrated());
        $this->assertTrue($ratingallocate->get_diversity_equal_size());
        $this->assertFalse($ratingallocate->get_diversity_ratings_first());
        $this->assertSame(0, $ratingallocate->get_diversity_min_size());

        $this->update_settings([
            'diversityfield' => 'institution',
            'diversityskipunrated' => 1,
            'diversityequalsize' => 0,
            'diversitypriority' => 1,
            'diversityminsize' => 4,
        ]);
        $ratingallocate = $this->get_ratingallocate();
        $this->assertSame('institution', $ratingallocate->get_diversity_field());
        $this->assertTrue($ratingallocate->get_diversity_skip_unrated());
        $this->assertFalse($ratingallocate->get_diversity_equal_size());
        $this->assertTrue($ratingallocate->get_diversity_ratings_first());
        $this->assertSame(4, $ratingallocate->get_diversity_min_size());

        $this->update_settings(['diversityminsize' => -3]);
        $this->assertSame(0, $this->get_ratingallocate()->get_diversity_min_size(), 'a negative minimum means none');

        $this->update_settings(['diversityminsize' => 4, 'diversityequalsize' => 1]);
        $this->assertSame(0, $this->get_ratingallocate()->get_diversity_min_size(), 'equal sizes ignore the minimum');

        $this->update_settings(['diversityequalsize' => 0, 'diversityfield' => 'city']);
        $ratingallocate = $this->get_ratingallocate();
        $this->assertSame('', $ratingallocate->get_diversity_field(), 'an unsupported field switches balancing off');
        $this->assertSame(0, $ratingallocate->get_diversity_min_size(), 'no minimum while balancing is off');
    }

    /**
     * Running the distribution with balancing on stores a complete, mixed allocation and finishes.
     */
    public function test_distribution_places_everyone_in_mixed_groups(): void {
        $this->create_activity(['diversityfield' => 'department'], ['G1' => 4, 'G2' => 4],
            ['A1' => 'A', 'A2' => 'A', 'A3' => 'A', 'B1' => 'B', 'B2' => 'B', 'B3' => 'B']);
        // Every A wants G1 and every B wants G2: only the balancing mixes them.
        foreach (['A1', 'A2', 'A3'] as $label) {
            $this->rate($label, ['G1']);
        }
        foreach (['B1', 'B2', 'B3'] as $label) {
            $this->rate($label, ['G2']);
        }

        $this->get_ratingallocate()->distribute_choices();

        $allocation = $this->allocation_by_choice();
        $this->assertCount(3, $allocation['G1']);
        $this->assertCount(3, $allocation['G2']);
        $this->assertSame(['G1' => ['A', 'B'], 'G2' => ['A', 'B']], $this->departments_by_choice());
        $this->assertSame([], $this->get_ratingallocate()->get_undistributed_users());
        $this->assertSame(algorithm_status::FINISHED, $this->stored_algorithm_status());
        $this->assertSame([], $this->get_ratingallocate()->get_diversity_coverage_report());
        $this->assertSame('', $this->get_ratingallocate()->render_diversity_coverage_notice());
    }

    /**
     * Ratings first keeps everyone in the choice they rated, even though that loses the mix.
     */
    public function test_ratings_first_setting_reaches_the_solver(): void {
        $this->create_activity(['diversityfield' => 'department', 'diversitypriority' => 1], ['G1' => 2, 'G2' => 2],
            ['A1' => 'A', 'A2' => 'A', 'B1' => 'B', 'B2' => 'B']);
        $this->rate('A1', ['G1']);
        $this->rate('A2', ['G1']);
        $this->rate('B1', ['G2']);
        $this->rate('B2', ['G2']);

        $this->get_ratingallocate()->distribute_choices();

        $this->assertSame(['G1' => ['A1', 'A2'], 'G2' => ['B1', 'B2']], $this->allocation_by_choice());
    }

    /**
     * Without balancing the old algorithm runs and nobody is pushed into a choice they did not rate.
     */
    public function test_balancing_off_keeps_the_old_algorithm(): void {
        $this->create_activity([], ['G1' => 4, 'G2' => 4],
            ['A1' => 'A', 'A2' => 'A', 'B1' => 'B', 'B2' => 'B']);
        foreach (['A1', 'A2', 'B1', 'B2'] as $label) {
            $this->rate($label, ['G1']);
        }

        $this->get_ratingallocate()->distribute_choices();

        $this->assertSame(['G1' => ['A1', 'A2', 'B1', 'B2'], 'G2' => []], $this->allocation_by_choice());
    }

    /**
     * Too little room for everyone fails the run, marks it as failed and is flagged up front.
     */
    public function test_insufficient_capacity_fails_the_run(): void {
        $this->create_activity(['diversityfield' => 'department'], ['G1' => 2, 'G2' => 2],
            ['A1' => 'A', 'A2' => 'A', 'A3' => 'A', 'B1' => 'B', 'B2' => 'B']);

        $warning = $this->get_ratingallocate()->render_diversity_capacity_warning();
        $this->assertStringContainsString(get_string('diversitycapacitywarning', 'ratingallocate',
            (object) ['capacity' => 4, 'users' => 5, 'missing' => 1]), $warning);

        try {
            $this->get_ratingallocate()->distribute_choices();
            $this->fail('the distribution should not succeed');
        } catch (\moodle_exception $e) {
            $this->assertSame('diversityinfeasible_capacity', $e->errorcode);
        }
        $this->assertSame(algorithm_status::FAILURE, $this->stored_algorithm_status(), 'not left "running"');
        $this->assertSame([], $this->get_ratingallocate()->get_allocations());
    }

    /**
     * Skipping non-raters leaves them out of the run, the capacity check and the coverage report.
     */
    public function test_skip_unrated_leaves_non_raters_out(): void {
        $this->create_activity(['diversityfield' => 'department', 'diversityskipunrated' => 1], ['G1' => 2, 'G2' => 2],
            ['A1' => 'A', 'A2' => 'A', 'B1' => 'B', 'B2' => 'B', 'C1' => 'C']);
        foreach (['A1', 'A2', 'B1', 'B2'] as $label) {
            $this->rate($label, ['G1', 'G2']);
        }
        $ratingallocate = $this->get_ratingallocate();
        $this->assertEqualsCanonicalizing(
            [$this->students['A1']->id, $this->students['A2']->id, $this->students['B1']->id, $this->students['B2']->id],
            array_map('intval', $ratingallocate->get_userids_with_ratings())
        );
        // Five participants but only four seats: fine, because C1 is not part of the run.
        $this->assertSame('', $ratingallocate->render_diversity_capacity_warning());

        $ratingallocate->distribute_choices();

        $this->assertSame(['G1' => ['A', 'B'], 'G2' => ['A', 'B']], $this->departments_by_choice());
        $this->assertSame([(int) $this->students['C1']->id], array_map('intval', $this->get_ratingallocate()->get_undistributed_users()));
        // C is in no group, but C1 did not take part, so this is not reported as a gap.
        $this->assertSame([], $this->get_ratingallocate()->get_diversity_coverage_report());
    }

    /**
     * A value with fewer students than groups is reported as impossible to cover everywhere.
     */
    public function test_coverage_report_names_values_too_rare_for_every_group(): void {
        $this->create_activity(['diversityfield' => 'department'], ['G1' => 3, 'G2' => 3],
            ['A1' => 'A', 'A2' => 'A', 'B1' => 'B', 'B2' => 'B', 'C1' => 'C']);

        $this->get_ratingallocate()->distribute_choices();

        $report = $this->get_ratingallocate()->get_diversity_coverage_report();
        $this->assertCount(1, $report);
        $this->assertSame('impossible', $report[0]['type']);
        $this->assertEquals((object) ['value' => 'C', 'count' => 1, 'groups' => 2], $report[0]['a']);
        $this->assertStringContainsString(get_string('diversityreport_heading', 'ratingallocate'),
            $this->get_ratingallocate()->render_diversity_coverage_notice());
    }

    /**
     * Group restrictions are read from real group membership, whoever runs the distribution, and a
     * choice no student of a value may join is reported as blocked.
     */
    public function test_group_restrictions_are_respected_and_reported(): void {
        $this->create_activity(['diversityfield' => 'department'], ['G1' => 2, 'G2' => 2],
            ['A1' => 'A', 'A2' => 'A', 'B1' => 'B', 'B2' => 'B']);
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->students['A1']->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->students['A2']->id]);
        // Only the A's may join G1.
        global $DB;
        $DB->set_field(this_db\ratingallocate_choices::TABLE, 'usegroups', 1, ['id' => $this->choiceids['G1']]);
        $ratingallocate = $this->get_ratingallocate();
        $ratingallocate->update_choice_groups($this->choiceids['G1'], [$group->id]);

        $choices = $ratingallocate->get_rateable_choices();
        $eligibility = $ratingallocate->get_choice_eligibility($choices, [$this->students['A1']->id, $this->students['B1']->id]);
        $this->assertTrue($eligibility['restricted']);
        $this->assertEqualsCanonicalizing([$this->choiceids['G1'], $this->choiceids['G2']],
            $eligibility['map'][$this->students['A1']->id]);
        $this->assertSame([$this->choiceids['G2']], array_values($eligibility['map'][$this->students['B1']->id]));

        $ratingallocate->distribute_choices();

        $this->assertSame(['G1' => ['A1', 'A2'], 'G2' => ['B1', 'B2']], $this->allocation_by_choice());
        $report = $this->get_ratingallocate()->get_diversity_coverage_report();
        $this->assertCount(1, $report);
        $this->assertSame('blocked', $report[0]['type']);
        $this->assertEquals((object) ['value' => 'B', 'choice' => 'G1'], $report[0]['a']);
    }

    /**
     * Without any group restriction everybody may join every choice.
     */
    public function test_eligibility_without_restrictions(): void {
        $this->create_activity([], ['G1' => 2, 'G2' => 2], ['A1' => 'A']);
        $ratingallocate = $this->get_ratingallocate();
        $userid = $this->students['A1']->id;

        $eligibility = $ratingallocate->get_choice_eligibility($ratingallocate->get_rateable_choices(), [$userid]);

        $this->assertFalse($eligibility['restricted']);
        $this->assertEqualsCanonicalizing(array_values($this->choiceids), $eligibility['map'][$userid]);
    }

    /**
     * Without equal sizes, a choice below the minimum is cancelled and named in the notice.
     */
    public function test_minimum_size_cancels_and_is_shown(): void {
        $this->create_activity(['diversityfield' => 'department', 'diversityequalsize' => 0, 'diversityminsize' => 2],
            ['G1' => 5, 'G2' => 5], ['A1' => 'A', 'A2' => 'A', 'B1' => 'B']);
        $this->rate('A1', ['G1']);
        $this->rate('A2', ['G1']);
        $this->rate('B1', ['G2']);

        $this->get_ratingallocate()->distribute_choices();

        $this->assertSame(['G1' => ['A1', 'A2'], 'G2' => []], $this->allocation_by_choice());
        $notice = $this->get_ratingallocate()->render_diversity_min_size_notice();
        $this->assertStringContainsString('G2 (0)', $notice);
        $this->assertStringNotContainsString('G1', $notice);
    }

    /**
     * Distributing the unallocated users with balancing on seats the leftovers where their value is
     * missing and never moves anybody who is already allocated.
     */
    public function test_top_up_fills_the_missing_values(): void {
        $this->create_activity(['diversityfield' => 'department'], ['G1' => 3, 'G2' => 3],
            ['A1' => 'A', 'A2' => 'A', 'A3' => 'A', 'B1' => 'B', 'B2' => 'B', 'B3' => 'B']);
        $this->allocate('A1', 'G1');
        $this->allocate('A2', 'G1');
        $this->allocate('B1', 'G2');
        $this->allocate('B2', 'G2');
        $this->assertNull($this->get_ratingallocate()->get_top_up_capacity_problem());

        $this->get_ratingallocate()->distribute_users_without_choice(ACTION_DISTRIBUTE_UNALLOCATED_FILL);

        $this->assertSame(['G1' => ['A1', 'A2', 'B3'], 'G2' => ['A3', 'B1', 'B2']], $this->allocation_by_choice());
    }

    /**
     * Too little room for the leftovers is caught before queueing, and the task gives up quietly
     * instead of failing and staying queued.
     */
    public function test_top_up_without_room(): void {
        $this->create_activity(['diversityfield' => 'department'], ['G1' => 2, 'G2' => 2],
            ['A1' => 'A', 'A2' => 'A', 'A3' => 'A', 'B1' => 'B', 'B2' => 'B']);
        $this->allocate('A1', 'G1');
        $this->allocate('A2', 'G1');
        $this->allocate('B1', 'G2');

        $this->assertSame(get_string('diversityinfeasible_topup', 'ratingallocate', (object) ['capacity' => 1, 'users' => 2]),
            $this->get_ratingallocate()->get_top_up_capacity_problem());

        $task = new distribute_unallocated_task();
        $task->set_custom_data((object) [
            'courseid' => $this->course->id,
            'cmid' => get_coursemodule_from_instance('ratingallocate', $this->instance->id)->id,
            'distributionalgorithm' => ACTION_DISTRIBUTE_UNALLOCATED_EQUALLY,
        ]);
        $this->expectOutputRegex('/giving up/');
        $task->execute();

        $this->assertCount(3, $this->get_ratingallocate()->get_allocations(), 'nobody was added');
    }

    /**
     * No capacity problem is reported while balancing is off: the old top-up places whoever fits.
     */
    public function test_top_up_check_is_skipped_without_balancing(): void {
        $this->create_activity([], ['G1' => 1], ['A1' => 'A', 'A2' => 'A']);
        $this->assertNull($this->get_ratingallocate()->get_top_up_capacity_problem());
    }

    /**
     * The balancing field is added to the downloads once, on top of the site's selection.
     */
    public function test_download_userfields_include_the_balancing_field(): void {
        global $CFG;
        $this->create_activity([], ['G1' => 2], []);
        $CFG->ratingallocate_download_userfields = 'id,username';

        $this->assertSame(['id', 'username'], $this->get_ratingallocate()->get_download_userfields());

        $this->update_settings(['diversityfield' => 'department']);
        $this->assertSame(['id', 'username', 'department'], $this->get_ratingallocate()->get_download_userfields());

        $CFG->ratingallocate_download_userfields = 'department,id';
        $this->assertSame(['department', 'id'], $this->get_ratingallocate()->get_download_userfields(), 'not added twice');

        $CFG->ratingallocate_download_userfields = '';
        $this->assertSame(['department'], $this->get_ratingallocate()->get_download_userfields());
    }
}
