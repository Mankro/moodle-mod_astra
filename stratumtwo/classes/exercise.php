<?php
defined('MOODLE_INTERNAL') || die();

class mod_stratumtwo_exercise extends mod_stratumtwo_database_object {
    const TABLE = 'stratumtwo_exercises'; // database table name
    const STATUS_READY       = 0;
    const STATUS_HIDDEN      = 1;
    const STATUS_MAINTENANCE = 2;
    
    // cache of references to other records, used in corresponding getter methods
    protected $category = null;
    protected $exerciseRound = null;
    protected $parentExercise = null;
    
    public function getId() {
        return $this->record->id;
    }
    
    public function getStatus() {
        //TODO number or string?
        return $this->record->status;
    }
    
    public function getCategory() {
        if (is_null($this->category)) {
            $this->category = mod_stratumtwo_category::createFromId($this->record->categoryid);
        }
        return $this->category;
    }
    
    public function getExerciseRound() {
        if (is_null($this->exerciseRound)) {
            $this->exerciseRound = mod_stratumtwo_exercise_round::createFromId($this->record->roundid);
        }
        return $this->exerciseRound;
    }
    
    public function getParentExercise() {
        if (empty($this->record->parentid)) {
            return null;
        }
        if (is_null($this->parentExercise)) {
            $this->parentExercise = self::createFromId($this->record->parentid);
        }
        return $this->parentExercise;
    }
    
    public function getOrder() {
        return $this->record->ordernum;
    }
    
    public function getRemoteKey() {
        return $this->record->remotekey;
    }
    
    public function getName() {
        return $this->record->name;
    }
    
    public function getServiceUrl() {
        return $this->record->serviceurl;
    }
    
    public function isAssistantGradingAllowed() {
        return (bool) $this->record->allowastgrading;
    }
    
    public function getMaxSubmissions() {
        return $this->record->maxsubmissions;
    }
    
    public function getPointsToPass() {
        return $this->record->pointstopass;
    }
    
    public function getMaxPoints() {
        return $this->record->maxpoints;
    }
    
    public function getMaxSubmissionFileSize() {
        return $this->record->sbmsmaxbytes;
    }
    
    public function getGradebookItemNumber() {
        return $this->record->gradeitemnumber;
    }
    
    /**
     * Delete this exercise instance from the database.
     * @param bool $updateRoundMaxPoints if true, the max points of the 
     * exercise round are updated here
     */
    public function deleteInstance($updateRoundMaxPoints = true) {
        global $DB;
        
        // Delete any dependent records here.
        // all submissions to this exercise
        //TODO submitted files
        $DB->delete_records(mod_stratumtwo_submission::TABLE, array(
            'exerciseid' => $this->record->id,
        ));
        // this exercise
        $DB->delete_records(self::TABLE, array('id' => $this->record->id));
        
        // delete exercise gradebook item
        $this->deleteGradebookItem();
        
        // update round max points (subtract this exercise)
        if ($updateRoundMaxPoints) {
            $this->getExerciseRound()->updateMaxPoints(-$this->record->maxpoints);
        }
        
        return true; // success
    }
    
    /**
     * Delete Moodle gradebook item for this exercise.
     * @return int GRADE_UPDATE_OK or GRADE_UPDATE_FAILED (or GRADE_UPDATE_MULTIPLE)
     */
    public function deleteGradebookItem() {
        global $CFG;
        require_once($CFG->libdir.'/gradelib.php');
        return grade_update('mod/'. mod_stratumtwo_exercise_round::TABLE,
                $this->getExerciseRound()->getCourse()->id,
                'mod',
                mod_stratumtwo_exercise_round::TABLE,
                $this->getExerciseRound()->getId(),
                $this->getGradebookItemNumber(),
                null, array('deleted' => 1));
    }
    
    /**
     * Return the best submission of the student to this exercise.
     * @param int $userid Moodle user ID of the student
     * @return mod_stratumtwo_submission the best submission, or null if there is
     * no valid submission (not late and not exceeded submit limit)
     */
    public function getBestSubmissionForStudent($userid) {
        global $DB;

        $submissions = $DB->get_recordset(mod_stratumtwo_submission::TABLE, array(
                'exerciseid' => $this->getId(),
                'submitter'  => $userid,
        ), 'submissiontime', '*', 0, $this->getMaxSubmissions());
        // order by submissiontime, earlier first
        // take only the max submissions number of submissions (zero means no limit)
        // TODO submit limit deviations
        $bestSubmission = null;
        foreach ($submissions as $s) {
            $sbms = new mod_stratumtwo_submission($s);
            if (!$sbms->isLate() && ($bestSubmission === null || 
                    $sbms->getGrade() > $bestSubmission->getGrade())) {
                $bestSubmission = $sbms;
            }
        }
        $submissions->close();
        
        return $bestSubmission;
    }
    
    /**
     * Create or update the Moodle gradebook item for this exercise.
     * (In order to add grades for students, use the method updateGrades.)
     * @param bool $reset if true, delete all grades in the grade item
     * @return int grade_update return value (one of GRADE_UPDATE_OK, GRADE_UPDATE_FAILED,
     * GRADE_UPDATE_MULTIPLE or GRADE_UPDATE_ITEM_LOCKED)
     */
    public function updateGradebookItem($reset = false) {
        global $CFG, $DB;
        require_once($CFG->libdir.'/gradelib.php');
        require_once($CFG->libdir .'/grade/grade_item.php');
        
        $item = array();
        $item['itemname'] = clean_param($this->getName(), PARAM_NOTAGS);
        
        // update exercise grading information ($item)
        if ($this->getMaxPoints() > 0) {
            $item['gradetype'] = GRADE_TYPE_VALUE; // points
            $item['grademax']  = $this->getMaxPoints();
            $item['grademin']  = 0; // min allowed value (points cannot be below this)
            // looks like min grade to pass (gradepass) cannot be set in this API directly
        } else {
            // Moodle core does not accept zero max points
            $item['gradetype'] = GRADE_TYPE_NONE;
        }
        
        if ($reset) {
            $item['reset'] = true;
        }
        
        $courseid = $this->getExerciseRound()->getCourse()->id;
        
        // create gradebook item
        $res = grade_update('mod/'. mod_stratumtwo_exercise_round::TABLE, $courseid, 'mod',
                mod_stratumtwo_exercise_round::TABLE, $this->record->roundid,
                $this->getGradebookItemNumber(), null, $item);
        
        // parameters to find the grade item from DB
        $grade_item_params = array(
                'itemtype'     => 'mod',
                'itemmodule'   => mod_stratumtwo_exercise_round::TABLE,
                'iteminstance' => $this->record->roundid,
                'itemnumber'   => $this->getGradebookItemNumber(),
                'courseid'     => $courseid,
        );
        // set min points to pass
        $DB->set_field('grade_items', 'gradepass', $this->getPointsToPass(), $grade_item_params);
        $gi = grade_item::fetch($grade_item_params);
        $gi->update('mod/'. mod_stratumtwo_exercise_round::TABLE);
        
        return $res;
    }
    
    /**
     * Return the grade of this exercise for the given user from the Moodle gradebook. 
     * @param int $userid
     * @param numeric the grade
     */
    public function getGradeFromGradebook($userid) {
        global $CFG;
        require_once($CFG->libdir.'/gradelib.php');
        // The Moodle API returns the exercise round and exercise grades all at once
        // since they use different item numbers with the same Moodle course module.
        $grades = grade_get_grades($this->getExerciseRound()->getCourse()->id, 'mod',
                mod_stratumtwo_exercise_round::TABLE,
                $this->getExerciseRound()->getId(),
                $userid);
        return $grades[$this->getGradebookItemNumber()]->grade;
    }
    
    /**
     * Update the grades of students in the gradebook for this exercise.
     * The gradebook item must have been created earlier.
     * @param array $grades student grades of this exercise, indexed by Moodle user IDs.
     * The grade is given either as an integer or as stdClass with fields
     * userid and rawgrade. Do not mix these two input types in the same array!
     *
     * For example:
     * array(userid => 100)
     * OR
     * $g = new stdClass(); $g->userid = userid; $g->rawgrade = 100;
     * array(userid => $g)
     *
     * @return int grade_update return value (one of GRADE_UPDATE_OK, GRADE_UPDATE_FAILED,
     * GRADE_UPDATE_MULTIPLE or GRADE_UPDATE_ITEM_LOCKED)
     */
    public function updateGrades(array $grades) {
        global $CFG;
        require_once($CFG->libdir.'/gradelib.php');
        
        // transform integer grades to objects (if the first array value is integer)
        if (is_int(reset($grades))) {
            $grades = mod_stratumtwo_exercise_round::gradeArrayToGradeObjects($grades);
        }
        
        return grade_update('mod/'. mod_stratumtwo_exercise_round::TABLE,
                $this->getExerciseRound()->getCourse()->id, 'mod',
                mod_stratumtwo_exercise_round::TABLE,
                $this->getExerciseRound()->getId(),
                $this->getGradebookItemNumber(), $grades, null);
    }
    
    /**
     * Write grades for each student in this exercise to Moodle gradebook.
     * The grades are read from the database tables of Stratum2 plugin.
     * 
     * @return array grade objects written to gradebook, indexed by user IDs
     */
    public function writeAllGradesToGradebook() {
        global $DB;
        // get all user IDs of the students that have submitted to this exercise
        $table = mod_stratumtwo_submission::TABLE;
        $submitters = $DB->get_recordset_sql('SELECT DISTINCT submitter FROM {'. $table .'} WHERE exerciseid = ?',
                array($this->getId()));
        $grades = array(); // grade objects indexed by user IDs
        foreach ($submitters as $row) {
            // get the best points of each student
            $sbms = $this->getBestSubmissionForStudent($row->submitter);
            if ($sbms !== null) {
                $grades[$row->submitter] = $sbms->getGradeObject();
            }
        }
        $submitters->close();
        
        $this->updateGrades($grades);
        
        return $grades;
    }
}