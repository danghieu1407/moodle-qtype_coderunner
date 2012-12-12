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
 * Multiple choice question renderer classes.
 *
 * @package    qtype
 * @subpackage coderunner
 * @copyright  2012 Richard Lobb, The University of Canterbury.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die();

define('FORCE_TABULAR_EXAMPLES', TRUE);
define('MAX_LINE_LENGTH', 120);
define('MAX_NUM_LINES', 200);

define('SHOW_STATISTICS', FALSE);  // If TRUE, shows stats on all coderunner-type questions
// Warning! For this to be workable, COMPUTE_STATS in questiontype.php must be
// true. The feature has been disabled as it brings the database server to its
// knees when there are large numbers of question submissions to compute stats
// from.

// require_once($CFG->dirroot . '/question/type/coderunner/testingoutcome.php');

/**
 * Subclass for generating the bits of output specific to coderunner questions.
 *
 * @copyright  Richard Lobb, University of Canterbury.
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class qtype_coderunner_renderer extends qtype_renderer {

    /**
     * Generate the display of the formulation part of the question. This is the
     * area that contains the question text, and the controls for students to
     * input their answers. Some question types also embed bits of feedback, for
     * example ticks and crosses, in this area.
     *
     * @param question_attempt $qa the question attempt to display.
     * @param question_display_options $options controls what should and should not be displayed.
     * @return string HTML fragment.
     */
    public function formulation_and_controls(question_attempt $qa, question_display_options $options) {
        $question = $qa->get_question();
        $qtext = $question->format_questiontext($qa);
        $testcases = $question->testcases;
        $examples = array_filter($testcases, function($tc) {
                    return $tc->useasexample;
                });
        if (count($examples) > 0) {
            $qtext .= html_writer::tag('p', 'For example:', array('class' => 'for-example-para'));
            $qtext .= html_writer::start_tag('div', array('class' => 'coderunner-examples'));
            $qtext .= $this->formatExamples($examples);
            $qtext .= html_writer::end_tag('div');
        }


        $qtext .= html_writer::start_tag('div', array('class' => 'prompt'));
        $answerprompt = get_string("answer", "quiz") . ': ';
        $qtext .= $answerprompt;
        $qtext .= html_writer::end_tag('div');

        $responsefieldname = $qa->get_qt_field_name('answer');
        $ta_attributes = array(
            'class' => 'coderunner-answer',
            'name' => $responsefieldname,
            'id' => $responsefieldname,
            //'style' => 'position: relative; height:400px; width: 100%; background-color: white; font-size:100%',
            'cols'      => '80',
            'rows'      => 18,
            'onkeydown' => 'keydown(event, this)',
            'onkeyup' => 'ignoreNL(event)',
            'onkeypress' => 'ignoreNL(event)'
        );

        if ($options->readonly) {
            $ta_attributes['readonly'] = 'readonly';
        }

        $currentanswer = $qa->get_last_qt_var('answer');
        $currentrating = $qa->get_last_qt_var('rating', 0);
        $qtext .= html_writer::tag('textarea', s($currentanswer), $ta_attributes);

        /*
        // Load ACE JavaScript editor. TODO: figure out where this belongs
        $qtext .= html_writer::tag('script', '', array(
            'src' => "http://d1n0x3qji82z53.cloudfront.net/src-min-noconflict/ace.js",
            'type' => "text/javascript",
            'charset' => 'utf8'));

        $qtext .= html_writer::tag('script', <<<EOT
var editor = ace.edit("$responsefieldname");
editor.setTheme("ace/theme/chrome");
editor.getSession().setMode("ace/mode/python");
EOT
            , array('type' => "text/javascript", 'charset' => 'utf8'));
*/
        if ($qa->get_state() == question_state::$invalid) {
            $qtext .= html_writer::nonempty_tag('div',
                    $question->get_validation_error(array('answer' => $currentanswer)),
                    array('class' => 'validationerror'));
        }

        if (SHOW_STATISTICS && isset($question->stats) && $question->stats) {
            $stats = $question->stats;
            $retries = sprintf("%.1f", $stats->average_retries);
            $stats_text = "Statistics: {$stats->attempts} attempts";
            if ($stats->attempts) {
                $stats_text .=
                    " ({$stats->success_percent}% successful)." .
                    " Average submissions per attempt: {$retries}.";
                if ($stats->likes + $stats->neutrals + $stats->dislikes > 0) {
                    $stats_text .= "<br />" .
                    " Likes: {$stats->likes}. Neutrals: {$stats->neutrals}. Dislikes: {$stats->dislikes}.";
                }
            }
            else {
                $stats_text .= '.';
            }

            $qtext .= html_writer::tag('p', $stats_text);

            $ratingSelector = html_writer::select(
                    array(1=>'Like', 2=>'Neutral', 3=>'Dislike'),
                    $qa->get_qt_field_name('rating'),
                    $currentrating);
            $qtext .= html_writer::tag('p', 'My rating of this question (optional): ' . $ratingSelector);
        }
        return $qtext;

        // TODO: consider how to prevent multiple submits while one submit in progress
        // (if it's actually a problem ... check first).
    }

    /**
     * Gereate the specific feedback. This is feedback that varies according to
     * the reponse the student gave.
     * This code tries to allow for the possiblity that the question is being
     * used with the wrong (i.e. non-adaptive) behaviour, which would mean that
     * test results aren't available.
     * @param question_attempt $qa the question attempt to display.
     * @return string HTML fragment.
     */
    protected function specific_feedback(question_attempt $qa) {
        $toSerialised = $qa->get_last_qt_var('_testoutcome');
        if ($toSerialised) {
            $q = $qa->get_question();
            $testCases = $q->testcases;
            $testOutcome = unserialize($toSerialised);
            $testResults = $testOutcome->testResults;
            if ($testOutcome->allCorrect()) {
                $resultsclass = "coderunner-test-results-good";
            } else {
                $resultsclass = "coderunner-test-results-bad";
            }

            $fb = html_writer::start_tag('div', array('class' => $resultsclass));
            $fb .= html_writer::tag('p', '&nbsp;', array('class' => 'coderunner-spacer'));
            if ($testOutcome->status != $testOutcome::STATUS_VALID) {
                $fb .= html_writer::tag('h3', 'Syntax Error(s)');
                $fb .= html_writer::tag('p', str_replace("\n", "<br />", s($testOutcome->errorMessage)));
            }
            else {
                $fb .= $this->buildResultsTable($testCases, $testResults);
            }

            // Summarise the status of the response in a paragraph at the end.

            $fb .= $this->buildFeedback($testCases, $testOutcome);
            $fb .= html_writer::end_tag('div');
        } else { // No testresults?! Probably due to a wrong behaviour selected
            $text = get_string('qWrongBehaviour', 'qtype_coderunner');
            $fb = html_writer::start_tag('div', array('class' => 'missingResults'));
            $fb .= html_writer::tag('p', $text);
            $fb .= html_writer::end_tag('div');
        }
        return $fb;
    }

    private function buildResultsTable($testCases, $testResults) {
        // First determine which columns are required in the result table
        // by looking for occurrences of testcode and stdin data in the tests.

        list($numStdins, $numTests) = $this->countBits($testCases);

        $table = new html_table();
        $table->attributes['class'] = 'coderunner-test-results';
        $table->head = array();
        if ($numTests) {
            $table->head[] = 'Test';
        }
        if ($numStdins) {
            $table->head[] = 'Stdin';
        }
        $table->head = array_merge($table->head, array('Expected', 'Got', ''));

        $tableData = array();
        $testCaseKeys = array_keys($testCases);  // Arbitrary numeric indices. Aarghhh.
        $i = 0;

        foreach ($testResults as $testResult) {
            $testCase = $testCases[$testCaseKeys[$i]];
            if ($this->shouldDisplayResult($testCase, $testResult)) {
                $tableRow = array();
                if ($numTests) {
                    $tableRow[] = s(restrict_qty($testCase->testcode));
                }
                if ($numStdins) {
                    $tableRow[] = s(restrict_qty($testCase->stdin));
                }
                $tableRow = array_merge($tableRow, array(
                    s($testResult->expected),  // Shouldn't need additional size ...
                    s($testResult->got)  // ... restrictions as already restricted
                ));

                $rowWithLineBreaks = array();
                foreach ($tableRow as $col) {
                    $rowWithLineBreaks[] = $this->addLineBreaks($col);
                }
                $mark = $testResult->isCorrect ? 1.0 : 0.0;
                $rowWithLineBreaks[] = $this->feedback_image($mark);
                $tableData[] = $rowWithLineBreaks;
            }
            $i++;
            if ($testCase->hiderestiffail && !$testResult->isCorrect) {
                break;
            }
        }
        $table->data = $tableData;
        $resultTableHtml = html_writer::table($table);
        return $resultTableHtml;
    }

    // Compute the HTML feedback to give for a given set of testresults
    private function buildFeedback($testCases, $testOutcome) {
        $lines = array();  // Build a list of lines of output
        $testResults = $testOutcome->testResults;
        if (count($testResults) != count($testCases)) {
            $lines[] = get_string('aborted', 'qtype_coderunner');
            $lines[] = get_string('noerrorsallowed', 'qtype_coderunner');
        } else {
            $numErrors = $testOutcome->errorCount;
            $hiddenErrors = $this->count_hidden_errors($testResults, $testCases);
            if ($numErrors > 0) {
                if ($numErrors == $hiddenErrors) {
                    // Only hidden tests were failed
                    $lines[] = get_string('failedhidden', 'qtype_coderunner');
                }
                else if ($hiddenErrors > 0) {
                    $lines[] = get_string('morehidden', 'qtype_coderunner');
                }
                $lines[] = get_string('noerrorsallowed', 'qtype_coderunner');
            } else {
                $lines[] =
                $isDisplayed = FALSE;get_string('allok', 'qtype_coderunner') .
                        "&nbsp;" . $this->feedback_image(1.0);
                ;
            }
        }

        // Convert list of lines to HTML paragraph

        $para = html_writer::start_tag('p');
        $para .= $lines[0];
        for ($i = 1; $i < count($lines); $i++) {
            $para .= html_writer::empty_tag('br') . $lines[$i];;
        }
        $para .= html_writer::end_tag('p');
        return $para;
    }


    // Format one or more examples
    protected function formatExamples($examples) {
        if ($this->allSingleLine($examples) && ! FORCE_TABULAR_EXAMPLES) {
            return $this->formatExamplesOnePerLine($examples);
        }
        else {
            return $this->formatExamplesAsTable($examples);
        }
    }


    // Return true iff there is no standard input and all output and shell
    // input cases are single line only
    private function allSingleLine($examples) {
        foreach ($examples as $example) {
            if (!empty($example->stdin) ||
                strpos($example->testcode, "\n") !== FALSE ||
                strpos($example->output, "\n") !== FALSE) {
               return FALSE;
            }
         }
         return TRUE;
    }



    // Return a '<br>' separated list of expression -> result examples.
    // For use only where there is no stdin and shell input is one line only.
    private function formatExamplesOnePerLine($examples) {
       $text = '';
       foreach ($examples as $example) {
            $text .=  $example->testcode . ' &rarr; ' . $example->output;
            $text .= html_writer::empty_tag('br');
       }
       return $text;
    }


    private function formatExamplesAsTable($examples) {
        // TODO: consider if ccode version should use column headers of
        // "Standard input" and "Standard output" rather than just Input
        // and output.
        $table = new html_table();
        $table->attributes['class'] = 'coderunnerexamples';
        list($numStd, $numShell) = $this->countBits($examples);
        $table->head = array();
        if ($numStd) {
            $table->head[] = 'Input';
        }
        if ($numShell) {
            $table->head[] = 'Test';
        }
        $table->head[] = 'Output';

        $tableRows = array();
        foreach ($examples as $example) {
            $row = array();
            if ($numStd) {
                $row[] = $this->addLineBreaks(s($example->stdin));
            }
            if ($numShell) {
                $row[] = $this->addLineBreaks(s($example->testcode));
            }
            $row[] = $this->addLineBreaks(s($example->output));
            $tableRows[] = $row;
        }
        $table->data = $tableRows;
        return html_writer::table($table);
    }


    // Return a count of the number of non-empty stdins and non-empty shell
    // inputs in the given list of test objects or examples
    private function countBits($tests) {
        $numStds = 0;
        $numShell = 0;
        foreach ($tests as $test) {
            if (!empty($test->stdin)) {
                $numStds++;
            }
            if (!empty($test->testcode)) {
                $numShell++;
            }
        }
        return array($numStds, $numShell);
    }



    // Replace all newline chars in a string with HTML line breaks.
    // Also replace spaces with &nbsp;
    private function addLineBreaks($s) {
        return str_replace("\n", "<br />", str_replace(' ', '&nbsp;', $s));
    }



    // Count the number of errors in hidden testcases, given the arrays of
    // testcases and testresults. A slight complication here is that the testcase keys
    // are arbitrary integers.
    private function count_hidden_errors($testResults, $testCases) {
        $testCaseKeys = array_keys($testCases);  // Arbitrary numeric indices. Aarghhh.
        $i = 0;
        $count = 0;
        $hidingRest = FALSE;
        foreach ($testResults as $tr) {
            $testCase = $testCases[$testCaseKeys[$i]];
            if ($hidingRest) {
                $isDisplayed = FALSE;
            }
            else {
                $isDisplayed = $this->shouldDisplayResult($testCase, $tr);
            }
            if (!$isDisplayed && !$tr->isCorrect) {
                $count++;
            }
            if ($testCase->hiderestiffail && !$tr->isCorrect) {
                $hidingRest = TRUE;
            }
            $i++;
        }
        return $count;
    }


    // True iff the given test result should be displayed
    private function shouldDisplayResult($testCase, $testResult) {
        return ($testCase->display == 'SHOW') ||
            ($testCase->display == 'HIDE_IF_FAIL' && $testResult->isCorrect) ||
            ($testCase->display == 'HIDE_IF_SUCCEED' && !$testResult->isCorrect);
    }

}


/* Support function to limit the size of a string for browser display.
 * Restricts line length to MAX_LINE_LENGTH and number of lines to
 * MAX_NUM_LINES.
 */
function restrict_qty($s) {
    $result = '';
    $n = strlen($s);
    $line = '';
    $lineLen = 0;
    $numLines = 0;
    for ($i = 0; $i < $n && $numLines < MAX_NUM_LINES; $i++) {
        if ($s[$i] != "\n") {
            if ($lineLen < MAX_LINE_LENGTH) {
                $line .= $s[$i];
            }
            elseif ($lineLen == MAX_LINE_LENGTH) {
                $line[MAX_LINE_LENGTH - 1] = $line[MAX_LINE_LENGTH - 2] =
                $line[MAX_LINE_LENGTH -3] = '.';
            }
            else {
                /* ignore remainder of line */
            }
            $lineLen++;
        }
        else {
            $result .= $line . "\n";
            $line = '';
            $lineLen = 0;
            $numLines += 1;
            if ($numLines == MAX_NUM_LINES) {
                $result .= "[... snip ...]\n";
            }
        }
    }
    return $result . $line;
}