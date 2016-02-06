<?php
// This file is part of Moodle - http://moodle.org/
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Import Word file into book.
 *
 * @package    booktool_wordimport
 * @copyright  2016 Eoin Campbell
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(dirname(__FILE__).'/../../../../config.php');
require_once(dirname(__FILE__).'/locallib.php');
require_once(dirname(__FILE__).'/import_form.php');

$id        = required_param('id', PARAM_INT);           // Course Module ID.
$chapterid = optional_param('chapterid', 0, PARAM_INT); // Chapter ID.
$action = optional_param('action', 'import', PARAM_TEXT);

// Security checks.
$cm = get_coursemodule_from_id('book', $id, 0, false, MUST_EXIST);
$course = $DB->get_record('course', array('id' => $cm->course), '*', MUST_EXIST);
$book = $DB->get_record('book', array('id' => $cm->instance), '*', MUST_EXIST);

require_course_login($course, true, $cm);

// Should update capabilities to separate import and export permissions.
$context = context_module::instance($cm->id);
require_capability('booktool/wordimport:import', $context);
require_capability('mod/book:edit', $context);

// Set up page in case an import has been requested.
$PAGE->set_url('/mod/book/tool/wordimport/index.php', array('id' => $id, 'chapterid' => $chapterid));
$PAGE->set_title($book->name);
$PAGE->set_heading($course->fullname);
$mform = new booktool_wordimport_form(null, array('id' => $id, 'chapterid' => $chapterid));

// If data submitted, then process and store.
if ($mform->is_cancelled()) {
    // Form cancelled, go back.
    if (empty($chapter->id)) {
        redirect($CFG->wwwroot."/mod/book/view.php?id=$cm->id");
    } else {
        redirect($CFG->wwwroot."/mod/book/view.php?id=$cm->id&chapterid=$chapter->id");
    }
} else if ($action == 'export' and $chapterid) {
    // Export the current chapter into Word.
    $chapter = $DB->get_record('book_chapters', array('id' => $chapterid, 'bookid' => $book->id), '*', MUST_EXIST);
    unset($id);
    unset($chapterid);

    if ($chapter->hidden) {
        require_capability('mod/book:viewhiddenchapters', $context);
    }

    // Preprocess the chapter HTML.
    $chaptertext = file_rewrite_pluginfile_urls($chapter->content, 'pluginfile.php', $context->id,
            'mod_book', 'chapter', $chapter->id);
    $chaptertext = "<html><body>" . $chaptertext . "</body></html>";
    // Postprocess the HTML to add a wrapper template, convert images into base64, etc.
    $filecontent = booktool_wordimport_postprocess("<h1>" . $chapter->title . "</h1>" . $chaptertext);
    send_file($filecontent, clean_filename($book->name).'.doc', 10, 0, true,
            array('filename' => clean_filename($book->name).'.doc'));
    die;
} else if ($action == 'export') {
    // Export the whole book into Word.
    $allchapters = $DB->get_records('book_chapters', array('bookid' => $book->id), 'pagenum');
    unset($id);

    // Read the title, introduction and all the chapters into a string.
    $booktext = "<html><body><p class='MsoTitle'>" . $book->name . "</p>";
    $booktext .= file_rewrite_pluginfile_urls($book->intro, 'pluginfile.php', $context->id, 'mod_book', 'intro', null);
    foreach ($allchapters as $chapter) {
        // Check if the chapter title is duplicated inside the content, and include it if not.
        if (!strpos($chapter->content, "<h1")) {
            $booktext .= '<h1>' . $chapter->title . '</h1>';
        }
        $booktext .= $chapter->content;
    }
    $booktext .=  "</body></html>";
    $filecontent = booktool_wordimport_postprocess($booktext);
    send_file($filecontent, clean_filename($book->name).'.doc', 10, 0, true, array('filename' => clean_filename($book->name).'.doc'));
    die;
} else if ($data = $mform->get_data()) {
    // A Word file has been uploaded, so process it.
    echo $OUTPUT->header();
    echo $OUTPUT->heading($book->name);
    echo $OUTPUT->heading(get_string('importchapters', 'booktool_wordimport'), 3);

    // Should the Word file split into subchapters on 'Heading 2' styles?
    $splitonsubheadings = property_exists($data, 'splitonsubheadings');

    // Get the uploaded Word file and save it to the file system.
    $fs = get_file_storage();
    $draftid = file_get_submitted_draft_itemid('importfile');
    if (!$files = $fs->get_area_files(context_user::instance($USER->id)->id, 'user', 'draft', $draftid, 'id DESC', false)) {
        redirect($PAGE->url);
    }
    $file = reset($files);

    // Save the file to a temporary location on the file system.
    if (!$tmpfilename = $file->copy_content_to_temp()) {
        // Cannot save file.
        throw new moodle_exception(get_string('errorcreatingfile', 'error', $package->get_filename()));
    }

    // Convert the Word file content and import it into the book.
    booktool_wordimport_import_word($tmpfilename, $book, $context, $splitonsubheadings);

    echo $OUTPUT->continue_button(new moodle_url('/mod/book/view.php', array('id' => $id)));
    echo $OUTPUT->footer();
    die;
}
    echo $OUTPUT->header();
    echo $OUTPUT->heading($book->name);

    $mform->display();

    echo $OUTPUT->footer();
