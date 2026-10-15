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

/**
 * Department-aware "complete balanced allocation" solver.
 *
 * Unlike the default {@see solver_edmonds_karp} (which only supports upper capacities, leaves unrated
 * choices empty and may leave students unallocated), this solver performs a *complete* allocation:
 * every participant is placed and every group is filled to a balanced size, while giving as many
 * groups as possible at least one member of each value of a chosen user profile field (e.g. the
 * study programme stored in the Department field).
 *
 * It is modelled as a minimum-cost flow with lower bounds:
 *   Source -> user           (exactly 1: every student assigned)
 *   user   -> (choice, dept)  (<=1, cost = preference; cheaper for higher ratings)
 *   (choice, dept) -> choice  (first unit cost 0, further same-dept units cost BONUS -> spreads depts)
 *   choice -> Sink            ([min, max]: balanced sizing, every group filled)
 *
 * Because the total number of assigned students is fixed at N, penalising every "second or later"
 * member of a department in the same group (cost BONUS) is equivalent to rewarding coverage: the
 * min-cost solution maximises the number of covered (group, department) pairs, then optimises
 * preferences among those solutions. All edge costs are non-negative, so the flow network has no
 * negative cycles and can be solved with successive shortest paths using Johnson potentials + Dijkstra.
 *
 * With equal group sizes switched off the minimum per group is dropped and a participant who rated
 * is only ever placed in a choice they rated; whoever does not fit into any of them stays unallocated
 * (a costly user -> Sink bypass edge), so nobody is moved somewhere they did not ask for.
 *
 * The order of the two goals can be reversed: with ratings first, the preference costs are scaled up
 * until one step of preference outweighs all coverage together, and BONUS shrinks to 1, so coverage
 * only decides between allocations that are equally good for the ratings.
 *
 * A minimum number of members (without equal sizes only) is not part of the flow: a choice that ends
 * up below it is cancelled and the flow is solved again without it, until none is left. Its members
 * move to another choice they rated, or stay unallocated.
 *
 * @package    mod_ratingallocate
 * @copyright  2026 Yurii Lysak
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_ratingallocate\ratingallocate;

defined('MOODLE_INTERNAL') || die();

/**
 * Minimum-cost flow with lower bounds, used for department-aware complete allocation.
 *
 * @package mod_ratingallocate
 */
class mincost_lowerbound_distributor {

    /** @var int A large value standing in for "infinite" capacity, kept well below PHP_INT_MAX. */
    const INF = 0x0fffffffffffff;

    /** @var int[] Edge head node, indexed by edge id (forward and backward edges are paired id, id^1). */
    private $eto = [];
    /** @var int[] Residual capacity, indexed by edge id. */
    private $ecap = [];
    /** @var int[] Cost, indexed by edge id. */
    private $ecost = [];
    /** @var int[] Current flow, indexed by edge id. */
    private $eflow = [];
    /** @var array<int, int[]> Adjacency list: node => list of edge ids. */
    private $adj = [];
    /** @var int[] Node imbalance introduced by edge lower bounds. */
    private $excess = [];
    /** @var int Number of nodes currently allocated. */
    private $nodecount = 0;

    /**
     * Allocate a fresh node id.
     *
     * @return int
     */
    private function new_node() {
        $node = $this->nodecount++;
        $this->adj[$node] = [];
        $this->excess[$node] = 0;
        return $node;
    }

    /**
     * Add a directed edge together with its (implicit) zero-capacity reverse edge.
     *
     * @param int $u tail node
     * @param int $v head node
     * @param int $cap capacity
     * @param int $cost cost per unit of flow
     * @return int id of the forward edge
     */
    private function add_edge($u, $v, $cap, $cost) {
        $id = count($this->eto);
        $this->eto[$id] = $v;
        $this->ecap[$id] = $cap;
        $this->ecost[$id] = $cost;
        $this->eflow[$id] = 0;
        $this->adj[$u][] = $id;

        $this->eto[$id + 1] = $u;
        $this->ecap[$id + 1] = 0;
        $this->ecost[$id + 1] = -$cost;
        $this->eflow[$id + 1] = 0;
        $this->adj[$v][] = $id + 1;

        return $id;
    }

    /**
     * Add a directed edge with a lower bound, recording the mandatory flow as node imbalance.
     *
     * @param int $u tail node
     * @param int $v head node
     * @param int $low lower bound (mandatory flow)
     * @param int $high upper bound (capacity)
     * @param int $cost cost per unit of flow
     * @return int id of the forward edge (carrying the flow above the lower bound)
     */
    private function add_edge_lb($u, $v, $low, $high, $cost) {
        $this->excess[$v] += $low;
        $this->excess[$u] -= $low;
        return $this->add_edge($u, $v, $high - $low, $cost);
    }

    /**
     * Keep only those users who gave at least one positive rating.
     *
     * A rating of 0 (or a row with no rating at all) never becomes an edge in the graph, so it does
     * not count as taking part here either.
     *
     * @param int[] $userids
     * @param array $ratings rating records; each needs ->userid and ->rating
     * @return int[] the subset of $userids that rated something, in the original order
     */
    public static function filter_users_with_ratings(array $userids, array $ratings) {
        $rated = [];
        foreach ($ratings as $rating) {
            if ($rating->rating > 0) {
                $rated[$rating->userid] = true;
            }
        }
        return array_values(array_filter($userids, function($userid) use ($rated) {
            return isset($rated[$userid]);
        }));
    }

    /**
     * How many more participants each choice has to take and may take in a run that places $n users.
     *
     * Balanced target sizes for the whole cohort: floor(T/K)..ceil(T/K), capped by each choice's
     * configured maxsize. On a top-up, T counts the users already seated as well, so the targets
     * describe the finished allocation rather than this run alone.
     *
     * Without equal sizes there is no target at all: nothing has to be filled, and each choice may
     * take whatever its own maxsize still allows.
     *
     * @param array $choicerecords choice records; each needs ->id and ->maxsize
     * @param int $n number of users the run has to place
     * @param array $seated map choiceid => number of seats already taken (top-up runs only)
     * @param bool $equalsize whether every choice is filled to the same size
     * @return array [ array(choiceid => min still to fill), array(choiceid => max still to fill) ]
     */
    public static function size_window(array $choicerecords, int $n, array $seated = [], bool $equalsize = true) {
        $caps = [];
        foreach ($choicerecords as $record) {
            $caps[$record->id] = (int) $record->maxsize;
        }
        if (empty($caps)) {
            return [[], []];
        }
        $taken = [];
        foreach ($caps as $cid => $cap) {
            $taken[$cid] = (int) ($seated[$cid] ?? 0);
        }

        $minsize = [];
        $maxsize = [];
        if (!$equalsize) {
            foreach ($caps as $cid => $cap) {
                $minsize[$cid] = 0;
                $maxsize[$cid] = max($cap - $taken[$cid], 0);
            }
            return [$minsize, $maxsize];
        }

        $baseshare = intdiv($n + array_sum($taken), count($caps));
        foreach ($caps as $cid => $cap) {
            $max = max(min($baseshare + 1, $cap), 0);
            $min = min($baseshare, $max);
            // What is left to fill in this run.
            $maxsize[$cid] = max($max - $taken[$cid], 0);
            $minsize[$cid] = min(max($min - $taken[$cid], 0), $maxsize[$cid]);
        }
        return [$minsize, $maxsize];
    }

    /**
     * Compute a department-aware complete allocation.
     *
     * @param array $choicerecords choice records; each needs ->id and ->maxsize
     * @param array $ratings rating records; each needs ->userid, ->choiceid, ->rating (>0 = preferred)
     * @param int[] $userids all participating user ids
     * @param array $deptof map userid => field value ('' means "no value", filled but not a coverage target)
     * @param array|null $eligibility map userid => list of choiceids the user may be placed in;
     *        null means every user is eligible for every choice
     * @param array|null $fixed map choiceid => list of userids already placed in that choice, for a
     *        top-up run. Their seats are subtracted from the target sizes, and a (choice, field value)
     *        pair they already cover is not rewarded again. $deptof must cover these users as well.
     *        Only the users in $userids are placed; the ones in $fixed are never moved.
     * @param bool $equalsize whether every choice is filled to the same size. When false, no choice
     *        has a minimum, and outside a top-up a user who rated is placed in a rated choice or not
     *        at all. A top-up is the teacher asking for the leftovers to be seated, so there they may
     *        go wherever there is room.
     * @param bool $ratingsfirst whether the ratings rank above the coverage of field values instead of
     *        below it
     * @param int $minmembers a choice that would get fewer members than this (but not none) is
     *        cancelled and gets nobody. Only applies without equal sizes and outside a top-up, where
     *        nobody is used to fill a group; 0 or 1 means no minimum.
     * @return array [ 'distribution' => array(choiceid => [userid, ...]), 'report' => array of messages,
     *        'unplaced' => list of userids that could not be seated,
     *        'unplacedreasons' => array(userid => reason), see {@see explain_unplaced()},
     *        'cancelled' => list of choiceids emptied for staying below $minmembers,
     *        'cancelledsizes' => array(choiceid => members it had when it was cancelled) ]
     * @throws \moodle_exception when the configured capacities cannot accommodate every student
     */
    public function compute_distribution(array $choicerecords, array $ratings, array $userids,
            array $deptof, ?array $eligibility = null, ?array $fixed = null, bool $equalsize = true,
            bool $ratingsfirst = false, int $minmembers = 0) {

        $checkminimum = $minmembers > 1 && !$equalsize && $fixed === null;
        $cancelled = [];
        $cancelledsizes = [];
        // Every round cancels at least one more choice, so this ends after at most one round per choice.
        do {
            $result = $this->solve($choicerecords, $ratings, $userids, $deptof, $eligibility, $fixed,
                $equalsize, $ratingsfirst, $cancelled);
            $toosmall = [];
            if ($checkminimum) {
                foreach ($result['distribution'] as $cid => $members) {
                    if (!empty($members) && count($members) < $minmembers) {
                        $toosmall[] = $cid;
                        $cancelledsizes[$cid] = count($members);
                    }
                }
            }
            $cancelled = array_merge($cancelled, $toosmall);
        } while (!empty($toosmall));

        $result['cancelled'] = $cancelled;
        $result['cancelledsizes'] = $cancelledsizes;
        $result['unplacedreasons'] = self::explain_unplaced($result['unplaced'], $choicerecords, $ratings,
            $eligibility, $cancelled);
        return $result;
    }

    /**
     * Say for each user who could not be seated why not, so the teacher can be told after the run.
     *
     * Exactly one reason per user, checked in this order:
     *  - 'restricted': the group restrictions leave no choice the user may join that they rated
     *    (or, without any rating, no choice at all);
     *  - 'noroom': the user rated no active choice, and no choice had a seat left for them;
     *  - 'minimum': every choice the user rated and may join was cancelled for staying below the minimum;
     *  - 'full': the choices the user rated were full.
     *
     * @param int[] $unplaced ids of the users that could not be seated
     * @param array $choicerecords choice records; each needs ->id
     * @param array $ratings rating records; each needs ->userid, ->choiceid, ->rating
     * @param array|null $eligibility map userid => list of choiceids, or null for "everyone everywhere"
     * @param int[] $cancelled ids of the choices cancelled for staying below the minimum
     * @return array map userid => reason
     */
    public static function explain_unplaced(array $unplaced, array $choicerecords, array $ratings,
            ?array $eligibility, array $cancelled) {
        if (empty($unplaced)) {
            return [];
        }
        $choiceids = [];
        foreach ($choicerecords as $record) {
            $choiceids[$record->id] = true;
        }
        $rated = [];
        foreach ($ratings as $rating) {
            if ($rating->rating > 0 && isset($choiceids[$rating->choiceid])) {
                $rated[$rating->userid][$rating->choiceid] = true;
            }
        }
        $cancelledset = array_flip($cancelled);

        $reasons = [];
        foreach ($unplaced as $uid) {
            $allowed = $eligibility === null ? $choiceids : array_flip($eligibility[$uid] ?? []);
            $own = $rated[$uid] ?? [];
            if (empty($own)) {
                $reasons[$uid] = empty($allowed) ? 'restricted' : 'noroom';
                continue;
            }
            $open = array_intersect_key($own, $allowed);
            if (empty($open)) {
                $reasons[$uid] = 'restricted';
            } else if (empty(array_diff_key($open, $cancelledset))) {
                $reasons[$uid] = 'minimum';
            } else {
                $reasons[$uid] = 'full';
            }
        }
        return $reasons;
    }

    /**
     * How well the groups of an allocation are mixed by the profile field.
     *
     * A group counts as fully mixed when it holds every field value present among the participants.
     * A value with fewer participants than there are filled groups cannot be in all of them.
     *
     * @param array $distribution map choiceid => list of userids
     * @param array $deptof map userid => field value, for every participant ('' = no value)
     * @return array [ 'groups' => filled groups, 'mixed' => fully mixed groups, 'values' => number of
     *        distinct values, 'scarce' => array(value => participants) for the values too rare for every
     *        filled group, 'novalue' => participants without a value ]
     */
    public static function mix_statistics(array $distribution, array $deptof) {
        $count = [];
        $novalue = 0;
        foreach ($deptof as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                $novalue++;
            } else {
                $count[$value] = ($count[$value] ?? 0) + 1;
            }
        }

        $groups = 0;
        $mixed = 0;
        foreach ($distribution as $members) {
            if (empty($members)) {
                continue;
            }
            $groups++;
            $present = [];
            foreach ($members as $uid) {
                $value = trim((string) ($deptof[$uid] ?? ''));
                if ($value !== '') {
                    $present[$value] = true;
                }
            }
            if (!empty($count) && count($present) === count($count)) {
                $mixed++;
            }
        }

        $scarce = array_filter($count, fn($n) => $n < $groups);
        ksort($scarce);
        return ['groups' => $groups, 'mixed' => $mixed, 'values' => count($count), 'scarce' => $scarce,
            'novalue' => $novalue];
    }

    /**
     * Solve one min-cost flow for {@see compute_distribution()}, which documents the shared parameters.
     *
     * @param array $choicerecords
     * @param array $ratings
     * @param int[] $userids
     * @param array $deptof
     * @param array|null $eligibility
     * @param array|null $fixed
     * @param bool $equalsize
     * @param bool $ratingsfirst
     * @param int[] $cancelled ids of choices that take nobody in this round. They stay in the graph,
     *        so that somebody who rated only those still counts as having rated.
     * @return array [ 'distribution' => ..., 'report' => ..., 'unplaced' => ... ]
     * @throws \moodle_exception when the configured capacities cannot accommodate every student
     */
    private function solve(array $choicerecords, array $ratings, array $userids, array $deptof,
            ?array $eligibility, ?array $fixed, bool $equalsize, bool $ratingsfirst, array $cancelled) {

        $choices = [];
        foreach ($choicerecords as $record) {
            $choices[$record->id] = $record;
        }
        $choiceids = array_keys($choices);
        $n = count($userids);
        $k = count($choiceids);

        if ($n === 0 || $k === 0) {
            return ['distribution' => array_fill_keys($choiceids, []), 'report' => [], 'unplaced' => []];
        }

        // Seats that are already taken, per choice, when topping up an existing allocation.
        $seated = array_fill_keys($choiceids, 0);
        $fixedcovered = [];
        if ($fixed !== null) {
            foreach ($fixed as $cid => $members) {
                if (!isset($choices[$cid])) {
                    continue;
                }
                $seated[$cid] = count($members);
                foreach ($members as $uid) {
                    $dept = isset($deptof[$uid]) ? trim((string) $deptof[$uid]) : '';
                    if ($dept !== '') {
                        // Already represented here, so seating another one of them covers nothing new.
                        $fixedcovered[$cid . '|' . $dept] = true;
                    }
                }
            }
        }

        [$minsize, $maxsize] = self::size_window($choices, $n, $seated, $equalsize);
        $totalmax = array_sum($maxsize);

        // Feasibility guard: there must be room for everyone. A clear message beats a cryptic solver failure.
        if ($totalmax < $n) {
            throw new \moodle_exception($fixed === null ? 'diversityinfeasible_capacity' : 'diversityinfeasible_topup',
                'ratingallocate', '', (object) ['capacity' => $totalmax, 'users' => $n]);
        }
        // Checked against the configured sizes above: whoever a cancelled choice leaves without a
        // seat stays unallocated instead of stopping the run.
        foreach ($cancelled as $cid) {
            $maxsize[$cid] = 0;
        }

        // Ratings, keyed by "userid|choiceid" for O(1) lookup, and the maximum rating value.
        $ratingof = [];
        $hasrated = [];
        $maxrating = 1;
        foreach ($ratings as $rating) {
            if ($rating->rating > 0) {
                $ratingof[$rating->userid . '|' . $rating->choiceid] = $rating->rating;
                $maxrating = max($maxrating, (int) $rating->rating);
                if (isset($choices[$rating->choiceid])) {
                    $hasrated[$rating->userid] = true;
                }
            }
        }

        // Without equal sizes nobody is needed to fill a group, so a rater is kept to their own choices.
        $ratedonly = !$equalsize && $fixed === null;

        // BONUS must dominate every preference cost so that covering one more (group, dept) pair always
        // outweighs any preference loss (total preference cost is bounded by N * maxrating).
        $bonus = $n * $maxrating + 1;
        $ratingscale = 1;
        if ($ratingsfirst) {
            // The other way round: at most N units ever pay BONUS, so N + 1 per step of preference
            // outweighs them all.
            $bonus = 1;
            $ratingscale = $n + 1;
        }
        // Leaving somebody out must in turn cost more than everything a full allocation could add up to.
        $unplacedcost = $n * ($bonus + $maxrating * $ratingscale) + 1;

        // Build the graph. Node layout: source, users, choices, sink, then (choice, dept) nodes lazily.
        $this->reset_graph();
        $source = $this->new_node();
        $usernode = [];
        foreach ($userids as $uid) {
            $usernode[$uid] = $this->new_node();
        }
        $choicenode = [];
        foreach ($choiceids as $cid) {
            $choicenode[$cid] = $this->new_node();
        }
        $sink = $this->new_node();

        // node id => choiceid, for both choice nodes and (choice, dept) nodes, used during extraction.
        $nodechoice = [];
        foreach ($choiceids as $cid) {
            $nodechoice[$choicenode[$cid]] = $cid;
        }

        // (choice, dept) nodes, created on demand; keyed "choiceid|dept".
        $cdnode = [];
        // Which users belong to each department, and how many groups each department could reach.
        $deptusers = [];
        foreach ($userids as $uid) {
            $dept = isset($deptof[$uid]) ? trim((string) $deptof[$uid]) : '';
            if ($dept !== '') {
                $deptusers[$dept][] = $uid;
            }
        }
        // The report talks about the whole cohort, so on a top-up the seated members count too -
        // otherwise a large programme would look "impossible" just because few of it are left to place.
        if ($fixed !== null) {
            foreach ($fixed as $members) {
                foreach ($members as $uid) {
                    $dept = isset($deptof[$uid]) ? trim((string) $deptof[$uid]) : '';
                    if ($dept !== '') {
                        $deptusers[$dept][] = $uid;
                    }
                }
            }
        }

        // Source -> user: exactly one unit (every student assigned).
        foreach ($userids as $uid) {
            $this->add_edge_lb($source, $usernode[$uid], 1, 1, 0);
        }

        // user -> Sink: the way out for a user none of whose own choices has a seat left.
        if ($ratedonly) {
            foreach ($userids as $uid) {
                $this->add_edge($usernode[$uid], $sink, 1, $unplacedcost);
            }
        }

        // user -> (choice, dept) [or -> choice for users with no field value].
        foreach ($userids as $uid) {
            $dept = isset($deptof[$uid]) ? trim((string) $deptof[$uid]) : '';
            $allowed = $eligibility === null ? $choiceids : ($eligibility[$uid] ?? []);
            foreach ($allowed as $cid) {
                if (!isset($choices[$cid])) {
                    continue;
                }
                $rating = $ratingof[$uid . '|' . $cid] ?? null;
                if ($rating === null && $ratedonly && isset($hasrated[$uid])) {
                    continue;
                }
                // Higher rating -> lower cost; unrated choices cost more than any rated choice.
                $cost = ($rating === null ? $maxrating : ($maxrating - $rating)) * $ratingscale;
                if ($dept === '') {
                    $this->add_edge($usernode[$uid], $choicenode[$cid], 1, $cost);
                } else {
                    $key = $cid . '|' . $dept;
                    if (!isset($cdnode[$key])) {
                        $node = $this->new_node();
                        $cdnode[$key] = $node;
                        $nodechoice[$node] = $cid;
                        // (choice, dept) -> choice: first member free, further members of the same dept
                        // in the same group cost BONUS (equivalently: reward the first = maximise coverage).
                        // On a top-up, a pair the seated members already cover gets no free edge - it is
                        // not a discovery any more.
                        if (!isset($fixedcovered[$key])) {
                            $this->add_edge($node, $choicenode[$cid], 1, 0);
                        }
                        $this->add_edge($node, $choicenode[$cid], self::INF, $bonus);
                    }
                    $this->add_edge($usernode[$uid], $cdnode[$key], 1, $cost);
                }
            }
        }

        // choice -> sink: balanced [min, max].
        foreach ($choiceids as $cid) {
            $this->add_edge_lb($choicenode[$cid], $sink, $minsize[$cid], $maxsize[$cid], 0);
        }

        // Lower-bound -> circulation transformation: close the loop, then satisfy imbalances via a
        // super-source/super-sink and run a min-cost max-flow.
        $this->add_edge($sink, $source, self::INF, 0);
        $supersource = $this->new_node();
        $supersink = $this->new_node();
        $demand = 0;
        for ($node = 0; $node < $this->nodecount; $node++) {
            if ($node === $supersource || $node === $supersink) {
                continue;
            }
            if ($this->excess[$node] > 0) {
                $this->add_edge($supersource, $node, $this->excess[$node], 0);
                $demand += $this->excess[$node];
            } else if ($this->excess[$node] < 0) {
                $this->add_edge($node, $supersink, -$this->excess[$node], 0);
            }
        }

        $sentflow = $this->min_cost_flow($supersource, $supersink);
        if ($sentflow < $demand) {
            // Lower bounds cannot all be met (e.g. group visibility restrictions leave a student with
            // no reachable group). Should not happen once the capacity guard passes and no usegroups
            // restrictions apply, but report rather than allocate a partial result silently.
            throw new \moodle_exception('diversityinfeasible', 'ratingallocate');
        }

        // Extract the allocation: the single forward edge out of each user carrying flow gives the choice.
        $distribution = array_fill_keys($choiceids, []);
        $unplaced = [];
        foreach ($userids as $uid) {
            $placed = false;
            foreach ($this->adj[$usernode[$uid]] as $eid) {
                if ($this->ecap[$eid] > 0 && $this->eflow[$eid] > 0 && isset($nodechoice[$this->eto[$eid]])) {
                    $distribution[$nodechoice[$this->eto[$eid]]][] = $uid;
                    $placed = true;
                    break;
                }
            }
            if (!$placed) {
                $unplaced[] = $uid;
            }
        }

        $report = $this->build_coverage_report($choiceids, $cdnode, $choicenode, $deptusers, $eligibility,
            $fixedcovered);

        return ['distribution' => $distribution, 'report' => $report, 'unplaced' => $unplaced];
    }

    /**
     * Reset all graph state so the solver instance can be reused.
     */
    private function reset_graph() {
        $this->eto = [];
        $this->ecap = [];
        $this->ecost = [];
        $this->eflow = [];
        $this->adj = [];
        $this->excess = [];
        $this->nodecount = 0;
    }

    /**
     * Successive-shortest-paths min-cost max-flow using Johnson potentials + Dijkstra.
     *
     * All original edge costs are non-negative, so potentials start at zero and every reduced cost
     * stays non-negative across augmentations.
     *
     * @param int $s source node
     * @param int $t sink node
     * @return int total flow sent from $s to $t
     */
    private function min_cost_flow($s, $t) {
        $v = $this->nodecount;
        $potential = array_fill(0, $v, 0);
        $totalflow = 0;

        while (true) {
            $dist = array_fill(0, $v, self::INF);
            $dist[$s] = 0;
            $prevedge = array_fill(0, $v, -1);
            $visited = array_fill(0, $v, false);

            $pq = new \SplPriorityQueue();
            $pq->insert($s, 0);
            while (!$pq->isEmpty()) {
                $u = $pq->extract();
                if ($visited[$u]) {
                    continue;
                }
                $visited[$u] = true;
                foreach ($this->adj[$u] as $eid) {
                    if ($this->ecap[$eid] - $this->eflow[$eid] <= 0) {
                        continue;
                    }
                    $to = $this->eto[$eid];
                    $reduced = $this->ecost[$eid] + $potential[$u] - $potential[$to];
                    if ($dist[$u] + $reduced < $dist[$to]) {
                        $dist[$to] = $dist[$u] + $reduced;
                        $prevedge[$to] = $eid;
                        $pq->insert($to, -$dist[$to]);
                    }
                }
            }

            if ($dist[$t] >= self::INF) {
                break;
            }

            // Update potentials to keep reduced costs non-negative.
            for ($i = 0; $i < $v; $i++) {
                if ($dist[$i] < self::INF) {
                    $potential[$i] += $dist[$i];
                }
            }

            // Find the bottleneck along the found path and push flow.
            $bottleneck = self::INF;
            $node = $t;
            while ($node !== $s) {
                $eid = $prevedge[$node];
                $bottleneck = min($bottleneck, $this->ecap[$eid] - $this->eflow[$eid]);
                $node = $this->eto[$eid ^ 1];
            }
            $node = $t;
            while ($node !== $s) {
                $eid = $prevedge[$node];
                $this->eflow[$eid] += $bottleneck;
                $this->eflow[$eid ^ 1] -= $bottleneck;
                $node = $this->eto[$eid ^ 1];
            }
            $totalflow += $bottleneck;
        }

        return $totalflow;
    }

    /**
     * Classify each uncovered (group, department) pair so the UI can explain the gaps.
     *
     * @param int[] $choiceids
     * @param array $cdnode map "choiceid|dept" => node id
     * @param array $choicenode map choiceid => node id
     * @param array $deptusers map dept => list of userids
     * @param array|null $eligibility map userid => list of choiceids, or null for "everyone everywhere"
     * @param array $fixedcovered set of "choiceid|value" pairs already covered by members seated
     *        before this run
     * @return array list of ['type' => impossible|blocked, 'a' => stdClass] messages
     */
    private function build_coverage_report(array $choiceids, array $cdnode, array $choicenode,
            array $deptusers, ?array $eligibility, array $fixedcovered = []) {
        $k = count($choiceids);
        $report = [];

        foreach ($deptusers as $dept => $members) {
            $count = count($members);
            // Which groups did this department actually reach?
            $coveredgroups = 0;
            foreach ($choiceids as $cid) {
                $key = $cid . '|' . $dept;
                if (isset($fixedcovered[$key])) {
                    $coveredgroups++;
                } else if (isset($cdnode[$key]) && $this->flow_into_choice($cdnode[$key], $choicenode[$cid]) > 0) {
                    $coveredgroups++;
                }
            }
            if ($coveredgroups >= $k) {
                continue;
            }
            if ($count < $k) {
                // Pigeonhole: too few students to seed every group. Unavoidable.
                $report[] = [
                    'type' => 'impossible',
                    'a' => (object) ['value' => $dept, 'count' => $count, 'groups' => $k],
                ];
            }
        }

        // Groups a department could not reach at all because of group (usegroups) visibility.
        if ($eligibility !== null) {
            foreach ($deptusers as $dept => $members) {
                $reachable = [];
                foreach ($members as $uid) {
                    foreach (($eligibility[$uid] ?? []) as $cid) {
                        $reachable[$cid] = true;
                    }
                }
                foreach ($choiceids as $cid) {
                    if (!isset($reachable[$cid])) {
                        $report[] = [
                            'type' => 'blocked',
                            'a' => (object) ['value' => $dept, 'choice' => $cid],
                        ];
                    }
                }
            }
        }

        return $report;
    }

    /**
     * Total flow routed from a (choice, dept) node into its choice node.
     *
     * @param int $cdnode
     * @param int $choicenode
     * @return int
     */
    private function flow_into_choice($cdnode, $choicenode) {
        $flow = 0;
        foreach ($this->adj[$cdnode] as $eid) {
            if ($this->ecap[$eid] > 0 && $this->eto[$eid] === $choicenode) {
                $flow += $this->eflow[$eid];
            }
        }
        return $flow;
    }

    /**
     * Place the participants who have no allocation yet, without moving anyone who already has one.
     *
     * Used for the "distribute unallocated users" actions while balancing by a profile field is on:
     * the seated members are handed to the solver as fixed, so the leftovers are steered towards the
     * groups that are still missing their study programme instead of wherever there happens to be room.
     *
     * @param ratingallocate $ratingallocate
     * @return int the number of participants placed
     */
    public function top_up_users(ratingallocate $ratingallocate) {
        $field = $ratingallocate->get_diversity_field();

        $choicerecords = $ratingallocate->get_rateable_choices();
        $ratings = $ratingallocate->get_ratings_for_rateable_choices();
        shuffle($ratings);

        $deptof = [];
        foreach ($ratingallocate->get_raters_in_course() as $rater) {
            $deptof[$rater->id] = isset($rater->{$field}) ? trim((string) $rater->{$field}) : '';
        }

        $fixed = [];
        foreach ($ratingallocate->get_allocations() as $allocation) {
            $fixed[$allocation->choiceid][] = $allocation->userid;
        }

        $userids = array_values($ratingallocate->get_undistributed_users());
        if (empty($userids)) {
            return 0;
        }

        $eligibility = $ratingallocate->get_choice_eligibility($choicerecords, $userids);

        $result = $this->compute_distribution($choicerecords, $ratings, $userids, $deptof,
            $eligibility['restricted'] ? $eligibility['map'] : null, $fixed,
            $ratingallocate->get_diversity_equal_size(), $ratingallocate->get_diversity_ratings_first());

        $placed = 0;
        $transaction = $ratingallocate->db->start_delegated_transaction();
        foreach ($result['distribution'] as $choiceid => $users) {
            foreach ($users as $userid) {
                $ratingallocate->add_allocation($choiceid, $userid, $ratingallocate->ratingallocate->id);
                $placed++;
            }
        }
        $transaction->allow_commit();

        return $placed;
    }

    /**
     * Entry point used by the ratingallocate instance: load data, solve, persist the allocation.
     *
     * @param ratingallocate $ratingallocate
     * @return array summary of the run for the teacher: [ 'total' => participants the run had to place,
     *        'placed' => participants seated, 'skipped' => participants left out for not rating (not part
     *        of 'total'),
     *        'unplaced' => array(reason => count), 'cancelled' => array(choiceid => members it had),
     *        'minsize' => minimum group size, 'mix' => {@see mix_statistics()}, 'field' => profile field,
     *        'ratingsfirst' => bool, 'report' => coverage report produced by the solver ]
     */
    public function distribute_users(ratingallocate $ratingallocate) {
        $field = $ratingallocate->get_diversity_field();

        $choicerecords = $ratingallocate->get_rateable_choices();
        $ratings = $ratingallocate->get_ratings_for_rateable_choices();
        shuffle($ratings);

        $raters = $ratingallocate->get_raters_in_course();
        $userids = [];
        $deptof = [];
        foreach ($raters as $rater) {
            $userids[] = $rater->id;
            $deptof[$rater->id] = isset($rater->{$field}) ? trim((string) $rater->{$field}) : '';
        }

        // Optional course rule: only students who rated take part. Everyone else stays unallocated
        // and can be placed afterwards with "Distribute unallocated" or by hand.
        if ($ratingallocate->get_diversity_skip_unrated()) {
            $userids = self::filter_users_with_ratings($userids, $ratings);
        }

        // Respect choice group visibility (usegroups) when deciding where a student may be placed.
        $eligibility = $ratingallocate->get_choice_eligibility($choicerecords, $userids);

        $result = $this->compute_distribution($choicerecords, $ratings, $userids, $deptof,
            $eligibility['restricted'] ? $eligibility['map'] : null, null,
            $ratingallocate->get_diversity_equal_size(), $ratingallocate->get_diversity_ratings_first(),
            $ratingallocate->get_diversity_min_size());

        $transaction = $ratingallocate->db->start_delegated_transaction();
        $ratingallocate->clear_all_allocations();
        $placed = 0;
        foreach ($result['distribution'] as $choiceid => $users) {
            foreach ($users as $userid) {
                $ratingallocate->add_allocation($choiceid, $userid, $ratingallocate->ratingallocate->id);
                $placed++;
            }
        }
        $transaction->allow_commit();

        $unplaced = [];
        foreach ($result['unplacedreasons'] as $reason) {
            $unplaced[$reason] = ($unplaced[$reason] ?? 0) + 1;
        }
        return [
            'total' => count($userids),
            'placed' => $placed,
            'skipped' => count($raters) - count($userids),
            'unplaced' => $unplaced,
            'cancelled' => $result['cancelledsizes'],
            'minsize' => $ratingallocate->get_diversity_min_size(),
            'mix' => self::mix_statistics($result['distribution'], array_intersect_key($deptof, array_flip($userids))),
            'field' => $field,
            'ratingsfirst' => $ratingallocate->get_diversity_ratings_first(),
            'report' => $result['report'],
        ];
    }
}
