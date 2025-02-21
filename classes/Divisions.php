<?php
/**
 * Policy Positions
 *
 * @package TheyWorkForYou
 */

namespace MySociety\TheyWorkForYou;

class Divisions {

    /**
     * Member
     */

    private $member;

    /**
     * DB handle
     */
    private $db;

    private $positions;
    private $policies;

    /**
     * Constructor
     *
     * @param Member   $member   The member to get positions for.
     */

    public function __construct(Member $member = NULL, PolicyPositions $positions = NULL, Policies $policies = NULL)
    {
        $this->member = $member;
        $this->positions = $positions;
        $this->policies = $policies;
        $this->db = new \ParlDB;
    }

    public static function getMostRecentDivisionDate() {
        $db = new \ParlDB;
        $q = $db->query(
            "SELECT policy_id, max(division_date) as recent
            FROM policydivisions
                JOIN divisions USING(division_id)
            GROUP BY policy_id"
        );

        $policy_maxes = array();
        foreach ($q as $row) {
            $policy_maxes[$row['policy_id']] = $row['recent'];
        }
        $policy_maxes['latest'] = $policy_maxes ? max(array_values($policy_maxes)) : '';
        return $policy_maxes;
    }

    /**
     * @param  int              $number  Number of divisions to return. Optional.
     * @param  string|string[]  $houses  House name (eg: "commons") or array of
     *                                   house names. Optional.
     */
    public function getRecentDivisions($number = 20, $houses = NULL) {
        $select = '';
        $where = '';
        $order = 'ORDER BY division_date DESC, division_number DESC';
        $limit = 'LIMIT :count';
        $params = array(
            ':count' => $number
        );

        if ( is_string($houses) ) {
            $houses = array( $houses );
        }

        if ( is_array($houses) && count($houses) > 0 ) {
            $where = 'WHERE house IN ("' . implode('", "', $houses) . '")';
        }

        if ( $this->member ) {
            $select = "SELECT divisions.*, vote FROM divisions
                LEFT JOIN persondivisionvotes ON divisions.division_id=persondivisionvotes.division_id AND person_id=:person_id";
            $params[':person_id'] = $this->member->person_id;
        } else {
            $select = "SELECT * FROM divisions";
        }

        $q = $this->db->query(
            sprintf("%s %s %s %s", $select, $where, $order, $limit),
            $params
        );

        $divisions = array();
        foreach ($q as $division) {
            $data = $this->getParliamentDivisionDetails($division);

            $mp_vote = '';
            if (array_key_exists('vote', $division)) {
                if ($division['vote'] == 'aye') {
                    $mp_vote = 'voted in favour';
                } elseif ($division['vote'] == 'tellaye') {
                    $mp_vote = 'voted (as a teller) in favour';
                } elseif ($division['vote'] == 'no') {
                    $mp_vote = 'voted against';
                } elseif ($division['vote'] == 'tellno') {
                    $mp_vote = 'voted (as a teller) against';
                } elseif ($division['vote'] == 'absent') {
                    $mp_vote = ' was absent';
                } elseif ($division['vote'] == 'both') {
                    $mp_vote = ' abstained';
                }
            }
            $data['mp_vote'] = $mp_vote;
            $house = Utility\House::division_house_name_to_number($division['house']);
            $data['members'] = \MySociety\TheyWorkForYou\Utility\House::house_to_members($house);
            $divisions[] = $data;
        }

        return array('divisions' => $divisions);
    }

    /**
     * @param  int              $number  Number of divisions to return. Optional.
     * @param  int|int[]        $majors  Major types (e.g. 1) or array of
     *                                   major types. Optional.
     */
    public function getRecentDebatesWithDivisions($number = 20, $majors = NULL) {
        global $hansardmajors;

        if (!is_array($majors)) {
            $majors = [$majors];
        }

        $where = '';
        if (count($majors) > 0) {
            $where = 'AND h.major IN (' . implode(', ', $majors) . ')';
        }

        # Fetch any division speech, its subsection gid for the link, and
        # section/subsection bodies to construct a debate title
        $q = $this->db->query(
            "SELECT eps.body as section_body, epss.body as subsection_body,
                ss.gid as debate_gid, h.gid, h.hdate, h.major, count(h.gid) AS c
            FROM hansard h, hansard ss, epobject eps, epobject epss
            WHERE h.section_id = eps.epobject_id
                AND h.subsection_id = epss.epobject_id
                AND h.subsection_id = ss.epobject_id
                AND h.htype=14
            $where
            GROUP BY h.subsection_id
            ORDER BY h.hdate DESC, h.hpos DESC
            LIMIT :count",
            array(':count' => $number)
        );

        $debates = array();
        foreach ($q as $debate) {
            $debate_gid = fix_gid_from_db($debate['debate_gid']);
            $anchor = '';
            if ($debate['c'] == 1) {
                $anchor = '#g' . gid_to_anchor(fix_gid_from_db($debate['gid']));
            }
            $url = new Url($hansardmajors[$debate['major']]['page']);
            $url->insert(array('gid' => $debate_gid));
            $debates[] = [
                'url' => $url->generate() . $anchor,
                'title' => "$debate[section_body] : $debate[subsection_body]",
                'date' => $debate['hdate'],
            ];
        }

        return $debates;
    }

    public function getRecentDivisionsForPolicies($policies, $number = 20) {
        $args = array(':number' => $number);

        $quoted = array();
        foreach ($policies as $policy) {
            $quoted[] = $this->db->quote($policy);
        }
        $policies_str = implode(',', $quoted);

        $q = $this->db->query(
            "SELECT divisions.*
            FROM policydivisions
                JOIN divisions USING(division_id)
            WHERE policy_id in ($policies_str)
            GROUP BY division_id
            ORDER by division_date DESC LIMIT :number",
            $args
        );

        $divisions = array();
        foreach ($q as $row) {
          $divisions[] = $this->getParliamentDivisionDetails($row);
        }

        return $divisions;
    }

    /**
     *
     * Get a list of division votes related to a policy
     *
     * Returns an array with one key ( the policyID ) containing a hash
     * with a policy_id key and a divisions key which contains an array
     * with details of all the divisions.
     *
     * Each division is a hash with the following fields:
     *    division_id, date, vote, gid, url, text, strong
     *
     * @param int|null $policyId The ID of the policy to get divisions for
     */

    public function getMemberDivisionsForPolicy($policyID = null) {
        $where_extra = '';
        $args = array(':person_id' => $this->member->person_id);
        if ( $policyID ) {
            $where_extra = 'AND policy_id = :policy_id';
            $args[':policy_id'] = $policyID;
        }
        $q = $this->db->query(
            "SELECT policy_id, division_id, division_title, yes_text, no_text, division_date, division_number, vote, gid, direction
            FROM policydivisions JOIN persondivisionvotes USING(division_id)
                JOIN divisions USING(division_id)
            WHERE person_id = :person_id AND direction <> 'abstention' $where_extra
            ORDER by policy_id, division_date DESC",
            $args
        );

        return $this->divisionsByPolicy($q);
    }

    public function getMemberDivisionDetails() {
        $args = array(':person_id' => $this->member->person_id);

        $policy_divisions = array();

        $q = $this->db->query(
            "SELECT policy_id, policy_vote, vote, count(division_id) as total,
            max(year(division_date)) as latest, min(year(division_date)) as earliest
            FROM policydivisions JOIN persondivisionvotes USING(division_id)
                JOIN divisions USING(division_id)
            WHERE person_id = :person_id AND direction <> 'abstention'
            GROUP BY policy_id, policy_vote, vote",
            $args
        );

        foreach ($q as $row) {
          $policy_id = $row['policy_id'];

          if (!array_key_exists($policy_id, $policy_divisions)) {
            $summary = array(
              'max' => $row['latest'],
              'min' => $row['earliest'],
              'total' => $row['total'],
              'for' => 0, 'against' => 0, 'absent' => 0, 'both' => 0, 'tell' => 0
            );

            $policy_divisions[$policy_id] = $summary;
          }

          $summary = $policy_divisions[$policy_id];

          $summary['total'] += $row['total'];
          if ($summary['max'] < $row['latest']) {
              $summary['max'] = $row['latest'];
          }
          if ($summary['min'] > $row['latest']) {
              $summary['min'] = $row['latest'];
          }

          $vote = $row['vote'];
          $policy_vote = str_replace('3', '', $row['policy_vote']);
          if ( $vote == 'absent' ) {
              $summary['absent'] += $row['total'];
          } else if ( $vote == 'both' ) {
              $summary['both'] += $row['total'];
          } else if ( strpos($vote, 'tell') !== FALSE ) {
              $summary['tell'] += $row['total'];
          } else if ( $policy_vote == $vote ) {
              $summary['for'] += $row['total'];
          } else if ( $policy_vote != $vote ) {
              $summary['against'] += $row['total'];
          }

          $policy_divisions[$policy_id] = $summary;
        }

        return $policy_divisions;
    }

    public function getDivisionByGid($gid) {
        $args = array(
            ':gid' => $gid
        );
        $q = $this->db->query("SELECT * FROM divisions WHERE gid = :gid", $args)->first();

        if (!$q) {
            return false;
        }

        return $this->_division_data($q);
    }

    public function getDivisionResults($division_id) {
        $args = array(
            ':division_id' => $division_id
        );
        $q = $this->db->query("SELECT * FROM divisions WHERE division_id = :division_id", $args)->first();

        if (!$q) {
            return false;
        }

        return $this->_division_data($q);

    }

    private function _division_data($row) {

        $details = $this->getParliamentDivisionDetails($row);

        $house = $row['house'];
        $args['division_id'] = $row['division_id'];
        $args['division_date'] = $row['division_date'];
        $args['house'] = \MySociety\TheyWorkForYou\Utility\House::division_house_name_to_number($house);

        $q = $this->db->query(
            "SELECT pdv.person_id, vote, proxy, title, given_name, family_name, lordofname, party
            FROM persondivisionvotes AS pdv JOIN person_names AS pn ON (pdv.person_id = pn.person_id)
            JOIN member AS m ON (pdv.person_id = m.person_id)
            WHERE division_id = :division_id
            AND house = :house AND entered_house <= :division_date AND left_house >= :division_date
            AND start_date <= :division_date AND end_date >= :division_date
            ORDER by family_name",
            $args
        );

        $votes = array(
          'yes_votes' => array(),
          'no_votes' => array(),
          'absent_votes' => array(),
          'both_votes' => array()
        );

        $party_breakdown = array(
          'yes_votes' => array(),
          'no_votes' => array(),
          'absent_votes' => array(),
          'both_votes' => array()
        );

        # Sort Lords specially
        $data = $q->fetchAll();
        if ($args['house'] == HOUSE_TYPE_LORDS) {
            uasort($data, 'by_peer_name');
        }

        foreach ($data as $vote) {
            $detail = array(
              'person_id' => $vote['person_id'],
              'name' => ucfirst(member_full_name($args['house'], $vote['title'], $vote['given_name'],
                    $vote['family_name'], $vote['lordofname'])),
              'party' => $vote['party'],
              'proxy' => false,
              'teller' => false
            );

            if (strpos($vote['vote'], 'tell') !== FALSE) {
                $detail['teller'] = true;
            }

            if ($vote['proxy']) {
                $q = $this->db->query(
                    "SELECT title, given_name, family_name, lordofname
                    FROM person_names AS pn
                    WHERE person_id = :person_id
                    AND start_date <= :division_date AND end_date >= :division_date",
                    [ ':person_id' => $vote['proxy'], ':division_date' => $row['division_date'] ]
                )->first();
                $detail['proxy'] = ucfirst(member_full_name(
                    HOUSE_TYPE_COMMONS, $q['title'], $q['given_name'],
                    $q['family_name'], $q['lordofname']));
            }

            if ($vote['vote'] == 'aye' or $vote['vote'] == 'tellaye') {
              $votes['yes_votes'][] = $detail;
              @$party_breakdown['yes_votes'][$detail['party']]++;
            } else if ($vote['vote'] == 'no' or $vote['vote'] == 'tellno') {
              $votes['no_votes'][] = $detail;
              @$party_breakdown['no_votes'][$detail['party']]++;
            } else if ($vote['vote'] == 'absent') {
              $votes['absent_votes'][] = $detail;
              @$party_breakdown['absent_votes'][$detail['party']]++;
            } else if ($vote['vote'] == 'both') {
              $votes['both_votes'][] = $detail;
              @$party_breakdown['both_votes'][$detail['party']]++;
            }
        }

        foreach ($votes as $vote => $count) { // array('yes_votes', 'no_votes', 'absent_votes', 'both_votes') as $vote) {
          $votes[$vote . '_by_party'] = $votes[$vote];
          usort($votes[$vote . '_by_party'], function ($a, $b) {
                return $a['party']>$b['party'];
            });
        }

        foreach ($party_breakdown as $vote => $parties) {
            $summary = array();
            foreach ($parties as $party => $count) {
                array_push($summary, "$party: $count");
            }

            sort($summary);
            $party_breakdown[$vote] = implode(', ', $summary);
        }

        $details = array_merge($details, $votes);
        $details['party_breakdown'] = $party_breakdown;
        $details['members'] = \MySociety\TheyWorkForYou\Utility\House::house_to_members($args['house']);
        $details['house'] = $house;
        $details['house_number'] = $args['house'];

        return $details;
    }

    public function getDivisionResultsForMember($division_id, $person_id) {
        $args = array(
            ':division_id' => $division_id,
            ':person_id' => $person_id
        );
        $q = $this->db->query(
            "SELECT division_id, division_title, yes_text, no_text, division_date, division_number, gid, vote
            FROM divisions JOIN persondivisionvotes USING(division_id)
            WHERE division_id = :division_id AND person_id = :person_id",
            $args
        )->first();

        // if the vote was before or after the MP was in Parliament
        // then there won't be a row
        if (!$q) {
            return false;
        }

        $details = $this->getDivisionDetails($q);
        return $details;
    }

    public function generateSummary($votes) {
        $max = $votes['max'];
        $min = $votes['min'];

        $actions = array(
            $votes['for'] . ' ' . make_plural('vote', $votes['for']) . ' for',
            $votes['against'] . ' ' . make_plural('vote', $votes['against']) . ' against'
        );

        if ( $votes['both'] ) {
            $actions[] = $votes['both'] . ' ' . make_plural('abstention', $votes['both']);
        }
        if ( $votes['absent'] ) {
            $actions[] = $votes['absent'] . ' ' . make_plural('absence', $votes['absent']);
        }
        if ($max == $min) {
            return join(', ', $actions) . ', in ' . $max;
        } else {
            return join(', ', $actions) . ', between ' . $min . '&ndash;' . $max;
        }
    }

    /**
     *
     * Get all the divisions a member has voted in keyed by policy
     *
     * Returns an array with keys for each policyID, each of these contains
     * the same structure as getMemberDivisionsForPolicy
     *
     */

    public function getAllMemberDivisionsByPolicy() {
        $policies = $this->getMemberDivisionsForPolicy();
        return Utility\Shuffle::keyValue($policies);
    }


    /**
     * Get the last n votes for a member
     *
     * @param $number int - How many divisions to return. Defaults to 20
     * @param $context string - The context of the page the results are being presented in.
     *    This affects the summary details and can either be 'Parliament' in which case the
     *    overall vote for all MPs is returned, plus additional information on how the MP passed
     *    in to the constructor voted, or the default of 'MP' which is just the vote of the
     *    MP passed in to the constructor.
     *
     * Returns an array of divisions
     */
    public function getRecentMemberDivisions($number = 20) {
        $args = array(':person_id' => $this->member->person_id, ':number' => $number);
        $q = $this->db->query(
            "SELECT *
            FROM persondivisionvotes
                JOIN divisions USING(division_id)
            WHERE person_id = :person_id
            ORDER by division_date DESC, division_id DESC LIMIT :number",
            $args
        );

        $divisions = array();
        foreach ($q as $row) {
            $divisions[] = $this->getDivisionDetails($row);
        }

        return $divisions;
    }


    private function constructYesNoVoteDescription($direction, $title, $short_text) {
        $text = ' voted ';
        if ( $short_text ) {
            $text .= $short_text;
        } else {
            $text .= "$direction on <em>$title</em>";
        }

        return $text;
    }


    private function constructVoteDescription($vote, $yes_text, $no_text, $division_title) {
        /*
         * for most post 2010 votes we have nice single sentence summaries of
         * what voting for or against means so we use that if it's there, however
         * we don't have anything nice for people being absent or for pre 2010
         * votes so we need to generate some text using the title of the division
         */

        switch ( strtolower($vote) ) {
            case 'yes':
            case 'aye':
                $description = $this->constructYesNoVoteDescription('yes', $division_title, $yes_text);
                break;
            case 'no':
                $description = $this->constructYesNoVoteDescription('no', $division_title, $no_text);
                break;
            case 'absent':
                $description = ' was absent for a vote on <em>' . $division_title . '</em>';
                break;
            case 'both':
                $description = ' abstained on a vote on <em>' . $division_title . '</em>';
                break;
            case 'tellyes':
            case 'tellno':
            case 'tellaye':
                $description = ' acted as teller for a vote on <em>' . $division_title . '</em>';
                break;
            default:
                $description = $division_title;
        }

        return $description;
    }

    private function getBasicDivisionDetails($row, $vote) {
        $yes_text = $row['yes_text'];
        $no_text = $row['no_text'];

        $division = array(
            'division_id' => $row['division_id'],
            'date' => $row['division_date'],
            'gid' => fix_gid_from_db($row['gid']),
            'number' => $row['division_number'],
            'text' => $this->constructVoteDescription($vote, $yes_text, $no_text, $row['division_title']),
            'has_description' => $yes_text && $no_text,
            'vote' => $vote,
        );

        if ($row['gid']) {
            $division['debate_url'] = $this->divisionUrlFromGid($row['gid']);
        }

        # Policy-related information

        if (array_key_exists('direction', $row)) {
            $division['direction'] = $row['direction'];
            if ( strpos( $row['direction'], 'strong') !== FALSE ) {
                $division['strong'] = TRUE;
            } else {
                $division['strong'] = FALSE;
            }
        }

        return $division;
    }

    private function getDivisionDetails($row) {
        return $this->getBasicDivisionDetails($row, $row['vote']);
    }

    private function getParliamentDivisionDetails($row) {
        $division = $this->getBasicDivisionDetails($row, $row['majority_vote']);

        $division['division_title'] = $row['division_title'];
        $division['for'] = $row['yes_total'];
        $division['against'] = $row['no_total'];
        $division['both'] = $row['both_total'];
        $division['absent'] = $row['absent_total'];

        return $division;
    }

    private function divisionsByPolicy($q) {
        $policies = array();

        foreach ($q as $row) {
            $policy_id = $row['policy_id'];

            if ( !array_key_exists($policy_id, $policies) ) {
                $policies[$policy_id] = array(
                    'policy_id' => $policy_id,
                    'weak_count' => 0,
                    'divisions' => array()
                );
                if ( $this->policies ) {
                    $policies[$policy_id]['desc'] = $this->policies->getPolicies()[$policy_id];
                    $policies[$policy_id]['header'] = $this->policies->getPolicyDetails($policy_id);
                }
                if ( $this->positions ) {
                    $policies[$policy_id]['position'] = $this->positions->positionsById[$policy_id];
                }
            }

            $division = $this->getDivisionDetails($row);

            if ( !$division['strong'] ) {
                $policies[$policy_id]['weak_count']++;
            }

            $policies[$policy_id]['divisions'][] = $division;
        };

        return $policies;
    }

    private function divisionUrlFromGid($gid) {
        global $hansardmajors;

        $gid = get_canonical_gid($gid);

        $q = $this->db->query("SELECT gid, major FROM hansard WHERE epobject_id = ( SELECT subsection_id FROM hansard WHERE gid = :gid )", array( ':gid' => $gid ))->first();
        if (!$q) {
            return '';
        }
        $parent_gid = fix_gid_from_db($q['gid']);
        $url = new Url($hansardmajors[$q['major']]['page']);
        $url->insert(array('gid' => $parent_gid));
        return $url->generate() . '#g' . gid_to_anchor(fix_gid_from_db($gid));
    }
}
