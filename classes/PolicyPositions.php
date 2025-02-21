<?php
/**
 * Policy Positions
 *
 * @package TheyWorkForYou
 */

namespace MySociety\TheyWorkForYou;

/**
 * Policy Positions
 *
 * Provides a list of policy positions of a given Member, plus supplementary
 * information such as additional links.
 */

class PolicyPositions {

    /**
     * Member
     */

    private $member;

    /**
     * Policies
     */

    private $policies;

    /**
     * Summary of a person's votes by policy
     */

    private $summaries;

    /**
     * Positions
     *
     * Array of positions held by the member.
     */

    public $positions = array();

    public $positionsById = array();

    /**
     * 'Since' String
     */
    public $sinceString;

    /**
     * 'More Links' String
     */
    public $moreLinksString;

    /**
     * Constructor
     *
     * @param Policies $policies The list of policies to get the positions for.
     * @param Member   $member   The member to get positions for.
     * @param int      $limit    The number of policies to limit the list to.
     */

    public function __construct(Policies $policies, Member $member, $options = array())
    {
        $this->policies = $policies;
        $this->member = $member;
        $this->summaries = isset($options['summaries']) ? $options['summaries'] : array();
        $this->divisions = new \MySociety\TheyWorkForYou\Divisions($member);

        $limit = isset($options['limit']) ? $options['limit'] : NULL;

        // Do the actual getting of positions
        $this->getMemberPolicyPositions($limit);
    }

    /**
     * Person Voting Record
     *
     * Populates this object's policy positions array.
     *
     * @param int $limit The number of results to limit the output to.
     */

    private function getMemberPolicyPositions ($limit = NULL) {

        // Make sure member info has actually been set.
        if (count($this->member->extra_info) === 0) {
            throw new \Exception('Member extra information has not been loaded; cannot find policy positions.');
        }

        $policies = $this->policies->getPoliciesData();

        $member_houses = $this->member->houses();

        // Determine the policy limit.
        if ($limit !== NULL AND is_int($limit))
        {
            $policy_limit = $limit;
        } else {
            $policy_limit = count($policies);
        }

        // Set the current policy count to 0
        $i = 0;

        $this->positions = array();

        // Loop around all the policies.
        foreach ($policies as $policy) {
            if ($i >= $policy_limit) {
                // We're over the policy limit, no sense still going, break out of the foreach.
                break;
            }

            if ($policy['commons_only'] && !in_array(HOUSE_TYPE_COMMONS, $member_houses)) {
                continue;
            }

            # If we've been passed in vote summaries and there isn't one for this
            # policy, skip as it means the person did not vote in this policy, or
            # the relevant policy votes are all abstentions.
            if ($this->summaries && !array_key_exists($policy['id'], $this->summaries)) {
                continue;
            }

            $votes_summary = array_key_exists($policy['id'], $this->summaries) ? $this->summaries[$policy['id']] : array();
            $dream_info = $this->displayDreamComparison($policy['id'], $policy['text'], $votes_summary);

            // don't return votes where they haven't voted on a strong division
            // if we're limiting the number of votes
            if ( $limit && !empty($dream_info) && !$dream_info['has_strong'] ) {
                continue;
            }

            // Make sure the dream actually exists
            if (!empty($dream_info)) {
                $summary = $votes_summary ? $this->divisions->generateSummary($votes_summary) : '';
                $this->positions[] = array(
                    'policy_id' => $policy['id'],
                    'policy' => $policy['text'],
                    'desc' => $dream_info['full_sentence'],
                    'has_strong' => $dream_info['has_strong'],
                    'position' => $dream_info['position'],
                    'summary' => $summary
                );
                $this->positionsById[$policy['id']] = array(
                    'policy_id' => $policy['id'],
                    'policy' => $policy['text'],
                    'desc' => $dream_info['full_sentence'],
                    'position' => $dream_info['position'],
                    'has_strong' => $dream_info['has_strong'],
                    'score' => $dream_info['score'],
                    'summary' => $summary
                );
                $i++;
            }
        }

        // Set the 'since' string
        $this->sinceString = $this->generateSinceString();

        // Generate the 'more' links
        $this->moreLinksString = $this->generateMoreLinksString();

    }

    /**
     * displayDreamComparison
     *
     * Returns an array with keys "full_sentence", "score", "position", "has_strong".
     *
     * The "full_sentence" element is a string, beginning with a lower case
     * letter, suitable for either displaying after a person’s name, eg:
     *
     *     "Lord Lordson consistently voted against [some policy]"
     *
     * or being passed into ucfirst() and displayed as a sentence on its
     * own, where the person's name is implied by context, eg:
     *
     *     "Consistently voted against [some policy]"
     *
     */

    private function displayDreamComparison($dreamid, $policy_description, $votes_summary) {
        $out = array();

        $extra_info = $this->member->extra_info();

        if (isset($extra_info["public_whip_dreammp${dreamid}_distance"])) {
            if ($extra_info["public_whip_dreammp${dreamid}_both_voted"] == 0) {
                $consistency = 'has never voted on';
                $dmpscore = -1;
            } else {
                $dmpscore = floatval($extra_info["public_whip_dreammp${dreamid}_distance"]);
                $consistency = score_to_strongly($dmpscore);

                if ($votes_summary && ($votes_summary['for'] == 0 || $votes_summary['against'] == 0) && preg_match('#mixture#', $consistency)) {
                    $consistency = ($dmpscore > 0.5) ? 'voted against' : 'voted for';
                }

                if ($extra_info["public_whip_dreammp${dreamid}_both_voted"] == 1) {
                    $consistency = preg_replace('#(consistently|almost always|generally) #', '', $consistency);
                }
            }
            $has_strong = 0;
            if (isset($extra_info["public_whip_dreammp${dreamid}_has_strong_vote"]) && $extra_info["public_whip_dreammp${dreamid}_has_strong_vote"] == 1) {
                $has_strong = 1;
            }
            $full_sentence = $consistency . ' ' . $policy_description;
            $out = array( 'full_sentence' => $full_sentence, 'score' => $dmpscore, 'position' => $consistency, 'has_strong' => $has_strong );
        }

        return $out;
    }

    private function generateSinceString()
    {

        $member_houses = $this->member->houses();
        $entered_house = $this->member->entered_house();
        $current_member = $this->member->current_member();

        if (count($this->policies->getPolicies()) > 0) {
            if (in_array(HOUSE_TYPE_COMMONS, $member_houses) AND
                $entered_house[HOUSE_TYPE_COMMONS]['date'] > '2001-06-07'
            ) {
                $since = '';
            } elseif (!in_array(HOUSE_TYPE_COMMONS, $member_houses) AND
                in_array(HOUSE_TYPE_LORDS, $member_houses) AND
                $entered_house[HOUSE_TYPE_LORDS]['date'] > '2001-06-07'
            ) {
                $since = '';
            } elseif ($this->member->isDead()) {
                $since = '';
            } else {
                $since = ' since 2001';
            }
            # If not current MP/Lord, but current MLA/MSP, need to say voting record is when MP
            if (!$current_member[HOUSE_TYPE_COMMONS] AND
                !$current_member[HOUSE_TYPE_LORDS] AND
                ( $current_member[HOUSE_TYPE_SCOTLAND] OR $current_member[HOUSE_TYPE_NI] )
            ) {
                $since .= ' whilst an MP';
            }

            return $since;
        }
    }

    private function generateMoreLinksString()
    {

        $extra_info = $this->member->extra_info;

        // Links to full record at Guardian and Public Whip
        $record = array();
        if (isset($extra_info['guardian_howtheyvoted'])) {
            $record[] = '<a href="' . $extra_info['guardian_howtheyvoted'] .
                '" title="At The Guardian">well-known issues</a> <small>(from the Guardian)</small>';
        }
        if (
            ( isset($extra_info['public_whip_division_attendance']) AND
            $extra_info['public_whip_division_attendance'] != 'n/a' )
            OR
            ( isset($extra_info['Lpublic_whip_division_attendance']) AND
            $extra_info['Lpublic_whip_division_attendance'] != 'n/a' )
        ) {
            $record[] = '<a href="https://www.publicwhip.org.uk/mp.php?id=uk.org.publicwhip/member/' .
                $this->member->member_id() .
                '&amp;showall=yes#divisions" title="At Public Whip">their full voting record on Public Whip</a>';
        }

        if (count($record) > 0) {
            return 'More on ' . implode(' &amp; ', $record);
        } else {
            return '';
        }
    }

}
