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
namespace mod_booking;

defined('MOODLE_INTERNAL') || die();

/**
 * Support class for generating ical items Note - this code is based on the ical code from mod_facetoface
 *
 * @package mod_booking
 * @copyright 2012-2017 Davo Smith, Synergy Learning, Andras Princic, David Bogner
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ical {

    private $datesareset = false;

    protected $booking;

    protected $option;

    protected $user;

    protected $fromuser;

    protected $tempfilename = '';

    protected $times = '';

    protected $ical = '';

    protected $vevents = '';

    protected $dtstamp = '';

    protected $summary = '';

    protected $description = '';

    protected $location = '';

    protected $host = '';

    protected $status = '';

    protected $role = 'REQ-PARTICIPANT';

    protected $userfullname = '';

    /**
     * Create a new mod_booking\ical instance
     *
     * @param object $booking the booking activity details
     * @param object $option the option that is being booked
     * @param object $user the user the booking is for
     */
    public function __construct($booking, $option, $user, $fromuser) {
        global $DB, $CFG;

        $this->booking = $booking;
        $this->option = $option;
        $this->fromuser = $fromuser;
        $this->times = $DB->get_records('booking_optiondates', array('optionid' => $option->id),
                'coursestarttime ASC');
        // Check if start and end dates exist.
        $coursedates = ($this->option->coursestarttime && $this->option->courseendtime);
        $sessiontimes = !empty($this->times);
        if ( ($coursedates OR $sessiontimes) ) {
            $this->datesareset = true;
            $this->user = $DB->get_record('user', array('id' => $user->id));
            // Date that this representation of the calendar information was created -
            // we use the time the option was last modified
            // http://www.kanzaki.com/docs/ical/dtstamp.html
            $this->dtstamp = $this->generate_timestamp($this->option->timemodified);
            $this->summary = $this->escape($this->booking->name);
            $this->description = $this->escape($this->option->text, true);
            // NOTE: Newlines are meant to be encoded with the literal sequence
            // '\n'. But evolution presents a single line text field for location,
            // and shows the newlines as [0x0A] junk. So we switch it for commas
            // here. Remember commas need to be escaped too.
            if ($this->option->courseid) {
                $url = new \moodle_url('/course/view.php', array('id' => $this->option->courseid));
                $this->location = $this->escape($url->out());
            }
            $urlbits = parse_url($CFG->wwwroot);
            $this->host = $urlbits['host'];
            $this->userfullname = \fullname($this->user);
        }
    }

    /**
     * Create an attachment to add to the notification email
     *
     * @param bool $cancel optional - true to generate a 'cancel' ical event
     * @return string the path to the attachment file empty if no dates are set
     */
    public function get_attachment($cancel = false) {
        global $CFG;
        if (!$this->datesareset){
            return '';
        }

        // UIDs should be globally unique
        $uid = md5($CFG->siteidentifier . $this->option->id . 'mod_booking_option') . '@' . $this->host; // Hostname for this moodle installation
        $dtstart = $this->generate_timestamp($this->option->coursestarttime);
        $dtend = $this->generate_timestamp($this->option->courseendtime);

        if ($cancel) {
            $this->role = 'NON-PARTICIPANT';
            $this->status = "\nSTATUS:CANCELLED";
        }
        $icalmethod = ($cancel) ? 'CANCEL' : 'PUBLISH';

        if (!empty($this->times)) {
            $this->get_vevents_from_optiondates();
        } else {
            $this->vevents = <<<EOF
BEGIN:VEVENT
UID:{$uid}
DTSTAMP:{$this->dtstamp}
DTSTART:{$dtstart}
DTEND:{$dtend}
SUMMARY:{$this->summary}
LOCATION:{$this->location}
DESCRIPTION:{$this->description}
CLASS:PRIVATE
TRANSP:OPAQUE{$this->status}
ORGANIZER;CN={$this->fromuser->email}:MAILTO:{$this->fromuser->email}
ATTENDEE;CUTYPE=INDIVIDUAL;ROLE={$this->role};PARTSTAT=NEEDS-ACTION;RSVP=false;CN={$this->userfullname};LANGUAGE=en:MAILTO:{$this->user->email}
END:VEVENT

EOF;
        }

        $this->vevents = trim($this->vevents);

        $template = <<<EOF
BEGIN:VCALENDAR
VERSION:2.0
PRODID:-//Moodle//NONSGML Booking//EN
CALSCALE:GREGORIAN
METHOD:{$icalmethod}
{$this->vevents}
END:VCALENDAR
EOF;

        $template = str_replace("\n", "\r\n", $template);

        $this->tempfilename = md5($template . microtime());
        $tempfilepathname = $CFG->tempdir . '/' . $this->tempfilename;
        file_put_contents($tempfilepathname, $template);
        return $tempfilepathname;
    }

    /**
     * Get the dates from the sessions and render them for ical.
     * Events are saved in $this->vevents
     */
    protected function get_vevents_from_optiondates(){
        foreach ($this->times as $time) {
            $dtstart = $this->generate_timestamp($time->coursestarttime);
            $dtend = $this->generate_timestamp($time->courseendtime);
            $uid = md5($CFG->siteidentifier . $this->times->id . $this->option->id . 'mod_booking_option') . '@' . $this->host;

            $this->vevents .= <<<EOF
BEGIN:VEVENT
UID:{$uid}
DTSTAMP:{$this->dtstamp}
DTSTART:{$dtstart}
DTEND:{$dtend}
SUMMARY:{$this->summary}
LOCATION:{$this->location}
DESCRIPTION:{$this->description}
CLASS:PRIVATE
TRANSP:OPAQUE{$this->status}
ORGANIZER;CN={$this->fromuser->email}:MAILTO:{$this->fromuser->email}
ATTENDEE;CUTYPE=INDIVIDUAL;ROLE={$this->role};PARTSTAT=NEEDS-ACTION;RSVP=false;CN={$this->userfullname};LANGUAGE=en:MAILTO:{$this->user->email}
END:VEVENT

EOF;
        }
    }

    public function get_name() {
        return 'booking.ics';
    }

    protected function generate_timestamp($timestamp) {
        return gmdate('Ymd', $timestamp) . 'T' . gmdate('His', $timestamp) . 'Z';
    }

    protected function escape($text, $converthtml = false) {
        if (empty($text)) {
            return '';
        }

        if ($converthtml) {
            $text = html_to_text($text);
        }

        $text = str_replace(array('\\', "\n", ';', ','), array('\\\\', '\n', '\;', '\,'), $text);

        // Text should be wordwrapped at 75 octets, and there should be one whitespace after the newline that does the wrapping.
        $text = wordwrap($text, 75, "\n ", true);

        return $text;
    }
}