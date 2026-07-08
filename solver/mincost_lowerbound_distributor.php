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
     * Compute a department-aware complete allocation.
     *
     * @param array $choicerecords choice records; each needs ->id and ->maxsize
     * @param array $ratings rating records; each needs ->userid, ->choiceid, ->rating (>0 = preferred)
     * @param int[] $userids all participating user ids
     * @param array $deptof map userid => field value ('' means "no value", filled but not a coverage target)
     * @param array|null $eligibility map userid => list of choiceids the user may be placed in;
     *        null means every user is eligible for every choice
     * @return array [ 'distribution' => array(choiceid => [userid, ...]), 'report' => array of messages ]
     * @throws \moodle_exception when the configured capacities cannot accommodate every student
     */
    public function compute_distribution(array $choicerecords, array $ratings, array $userids,
            array $deptof, ?array $eligibility = null) {

        $choices = [];
        foreach ($choicerecords as $record) {
            $choices[$record->id] = $record;
        }
        $choiceids = array_keys($choices);
        $n = count($userids);
        $k = count($choiceids);

        if ($n === 0 || $k === 0) {
            return ['distribution' => array_fill_keys($choiceids, []), 'report' => []];
        }

        // Balanced target sizes: aim for floor(N/K)..ceil(N/K), capped by each choice's configured maxsize.
        $baseshare = intdiv($n, $k);
        $minsize = [];
        $maxsize = [];
        $totalmax = 0;
        foreach ($choiceids as $cid) {
            $cap = (int) $choices[$cid]->maxsize;
            $max = min($baseshare + 1, $cap);
            $max = max($max, 0);
            $minsize[$cid] = min($baseshare, $max);
            $maxsize[$cid] = $max;
            $totalmax += $max;
        }

        // Feasibility guard: there must be room for everyone. A clear message beats a cryptic solver failure.
        if ($totalmax < $n) {
            throw new \moodle_exception('diversityinfeasible_capacity', 'ratingallocate', '',
                (object) ['capacity' => $totalmax, 'users' => $n]);
        }

        // Ratings, keyed by "userid|choiceid" for O(1) lookup, and the maximum rating value.
        $ratingof = [];
        $maxrating = 1;
        foreach ($ratings as $rating) {
            if ($rating->rating > 0) {
                $ratingof[$rating->userid . '|' . $rating->choiceid] = $rating->rating;
                $maxrating = max($maxrating, (int) $rating->rating);
            }
        }

        // BONUS must dominate every preference cost so that covering one more (group, dept) pair always
        // outweighs any preference loss (total preference cost is bounded by N * maxrating).
        $bonus = $n * $maxrating + 1;

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

        // Source -> user: exactly one unit (every student assigned).
        foreach ($userids as $uid) {
            $this->add_edge_lb($source, $usernode[$uid], 1, 1, 0);
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
                // Higher rating -> lower cost; unrated choices cost more than any rated choice.
                $cost = $rating === null ? $maxrating : ($maxrating - $rating);
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
                        $this->add_edge($node, $choicenode[$cid], 1, 0);
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
        foreach ($userids as $uid) {
            foreach ($this->adj[$usernode[$uid]] as $eid) {
                if ($this->ecap[$eid] > 0 && $this->eflow[$eid] > 0 && isset($nodechoice[$this->eto[$eid]])) {
                    $distribution[$nodechoice[$this->eto[$eid]]][] = $uid;
                    break;
                }
            }
        }

        $report = $this->build_coverage_report($choiceids, $cdnode, $choicenode, $deptusers, $eligibility);

        return ['distribution' => $distribution, 'report' => $report];
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
     * @return array list of ['type' => impossible|blocked, 'a' => stdClass] messages
     */
    private function build_coverage_report(array $choiceids, array $cdnode, array $choicenode,
            array $deptusers, ?array $eligibility) {
        $k = count($choiceids);
        $report = [];

        foreach ($deptusers as $dept => $members) {
            $count = count($members);
            // Which groups did this department actually reach?
            $coveredgroups = 0;
            foreach ($choiceids as $cid) {
                $key = $cid . '|' . $dept;
                if (isset($cdnode[$key]) && $this->flow_into_choice($cdnode[$key], $choicenode[$cid]) > 0) {
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
     * Entry point used by the ratingallocate instance: load data, solve, persist the allocation.
     *
     * @param ratingallocate $ratingallocate
     * @return array coverage report (list of messages) produced by the solver
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

        // Respect choice group visibility (usegroups) when deciding where a student may be placed.
        $eligibility = $ratingallocate->get_choice_eligibility($choicerecords, $userids);

        $result = $this->compute_distribution($choicerecords, $ratings, $userids, $deptof,
            $eligibility['restricted'] ? $eligibility['map'] : null);

        $transaction = $ratingallocate->db->start_delegated_transaction();
        $ratingallocate->clear_all_allocations();
        foreach ($result['distribution'] as $choiceid => $users) {
            foreach ($users as $userid) {
                $ratingallocate->add_allocation($choiceid, $userid, $ratingallocate->ratingallocate->id);
            }
        }
        $transaction->allow_commit();

        return $result['report'];
    }
}
