<?php
namespace mod_astra\summary;

defined('MOODLE_INTERNAL') || die;

/**
 * Class to gather the results of one student in a course for templates.
 * The number of database queries is minimized to improve performance.
 *
 * Derived from A+ (a-plus/exercise/presentation/summary.py).
 */
class user_course_summary {
    
    protected $user;
    protected $course;
    protected $exerciseRounds;
    protected $moduleSummariesByRoundId;
    protected $exerciseCount = 0; // number of exercises in the course
    protected $categorySummaries;
    
    /**
     * Create a summary of the user's status in a course.
     * @param \stdClass $course
     * @param \stdClass $user
     */
    public function __construct(\stdClass $course, $user) {
        $this->course = $course;
        $this->user = $user;
        
        // all exercise rounds in the course
        $this->exerciseRounds = \mod_astra_exercise_round::getExerciseRoundsInCourse($course->id);
        
        // user_module_summary objects indexed by exercise round IDs
        $this->moduleSummariesByRoundId = array();
        // user_category_summary objects
        $this->categorySummaries = array();
        
        $this->generate();
    }
    
    protected function generate() {
        global $DB;
        
        // all exercise rounds, exercises and categories in the course 
        $roundIds = array(); // visible rounds
        foreach ($this->exerciseRounds as $exround) {
            $roundIds[] = $exround->getId();
        }
        
        if (empty($roundIds)) {
            $exerciseRecords = array();
            $chapterRecords = array();
        } else {
            $where = ' WHERE lob.roundid IN ('. \implode(',', $roundIds) .')';
            $exerciseRecords = $DB->get_records_sql(
                    \mod_astra_learning_object::getSubtypeJoinSQL(\mod_astra_exercise::TABLE) .
                    $where);
            $chapterRecords = $DB->get_records_sql(
                    \mod_astra_learning_object::getSubtypeJoinSQL(\mod_astra_chapter::TABLE) .
                    $where);
        }
        $this->exerciseCount = \count($exerciseRecords);
        $exercisesByRoundId = array(); // exercises and chapters
        $exerciseIds = array(); // only submittable exercises
        $categories = \mod_astra_category::getCategoriesInCourse($this->course->id); // only visible categories
        foreach ($roundIds as $roundId) {
            // initialize before the next foreach, needed if some rounds have no exercises
            $exercisesByRoundId[$roundId] = array();
        }
        foreach ($exerciseRecords as $exrecord) {
            // append exercises
            // filter out hidden exercises or exercises in hidden categories
            if ($exrecord->status != \mod_astra_learning_object::STATUS_HIDDEN &&
                    isset($categories[$exrecord->categoryid])) {
                $exercisesByRoundId[$exrecord->roundid][] = new \mod_astra_exercise($exrecord);
                $exerciseIds[] = $exrecord->lobjectid;
            }
        }
        foreach ($chapterRecords as $exrecord) {
            // append chapters
            if ($exrecord->status != \mod_astra_learning_object::STATUS_HIDDEN &&
                    isset($categories[$exrecord->categoryid])) {
                $exercisesByRoundId[$exrecord->roundid][] = new \mod_astra_chapter($exrecord);
            }
        }
        
        $exerciseSummariesByCategoryId = array();
        foreach ($categories as $cat) {
            $exerciseSummariesByCategoryId[$cat->getId()] = array();
        }
        
        // initialize array for holding the best submissions
        $submissionsByExerciseId = array();
        foreach ($roundIds as $rid) {
            foreach ($exercisesByRoundId[$rid] as $ex) {
                if ($ex->isSubmittable()) {
                    $submissionsByExerciseId[$ex->getId()] = array(
                            'count' => 0,
                            'best'  => null,
                            'all'   => array(),
                    );
                }
            }
        }
        
        // all submissions from the user in any visible exercise in the course
        $sql =
            'SELECT id, status, submissiontime, exerciseid, submitter, grader,
                 assistfeedback, grade, gradingtime, latepenaltyapplied, servicepoints, servicemaxpoints
             FROM {'. \mod_astra_submission::TABLE .'} 
             WHERE submitter = ? AND exerciseid IN ('. implode(',', $exerciseIds) .')
             ORDER BY submissiontime DESC';
        
        if (!empty($exerciseIds)) {
            $submissions = $DB->get_recordset_sql($sql, array($this->user->id));
            // find best submissions
            foreach ($submissions as $record) {
                $sbms = new \mod_astra_submission($record);
                $exerciseBest = &$submissionsByExerciseId[$record->exerciseid];
                $exerciseBest['all'][] = $sbms;
                $best = $exerciseBest['best'];
                if ($best === null || $sbms->getGrade() > $best->getGrade() ||
                        ($sbms->getGrade() == $best->getGrade() &&
                         $sbms->getSubmissionTime() < $best->getSubmissionTime())) {
                    $exerciseBest['best'] = $sbms;
                }
                $exerciseBest['count'] += 1;
            }
            
            $submissions->close();
        }
        
        // make summary objects
        foreach ($this->exerciseRounds as $exround) {
            $exerciseSummaries = array(); // user_exercise_summary objects for one exercise round
            foreach ($exercisesByRoundId[$exround->getId()] as $ex) {
                if ($ex->isSubmittable()) {
                    $exerciseBest = &$submissionsByExerciseId[$ex->getId()];
                    $exerciseSummary = new user_exercise_summary($ex, $this->user,
                            $exerciseBest['count'], $exerciseBest['best'], $exerciseBest['all'],
                            $categories[$ex->getCategoryId()], false);
                    $exerciseSummaries[] = $exerciseSummary;
                    $exerciseSummariesByCategoryId[$ex->getCategoryId()][] = $exerciseSummary;
                }
            }
            $this->moduleSummariesByRoundId[$exround->getId()] = new user_module_summary(
                    $exround, $this->user, $exerciseSummaries,
                    $exercisesByRoundId[$exround->getId()], false);
        }
        
        foreach ($categories as $cat) {
            $this->categorySummaries[] = new user_category_summary($cat, $this->user, 
                    $exerciseSummariesByCategoryId[$cat->getId()], false);
        }
    }
    
    public function getExerciseCount() {
        return $this->exerciseCount;
    }
    
    public function getMaxPoints() {
        $max = 0;
        foreach ($this->moduleSummariesByRoundId as $moduleSummary) {
            $max += $moduleSummary->getMaxPoints();
        }
        return $max;
    }
    
    public function getTotalPoints() {
        $total = 0;
        foreach ($this->moduleSummariesByRoundId as $moduleSummary) {
            $total += $moduleSummary->getTotalPoints();
        }
        return $total;
    }
    
    public function getExerciseRounds() {
        return $this->exerciseRounds;
    }
    
    public function getModuleSummary($roundId) {
        return $this->moduleSummariesByRoundId[$roundId];
    }
    
    public function getCategorySummaries() {
        return $this->categorySummaries;
    }
}