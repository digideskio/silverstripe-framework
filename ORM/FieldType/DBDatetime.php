<?php

namespace SilverStripe\ORM\FieldType;

use Convert;
use Exception;
use Member;
use DatetimeField;
use Zend_Date;
use TemplateGlobalProvider;
use DateTime;
use SilverStripe\ORM\DB;

/**
 * Represents a date-time field.
 * The field currently supports New Zealand date format (DD/MM/YYYY),
 * or an ISO 8601 formatted date and time (Y-m-d H:i:s).
 * Alternatively you can set a timestamp that is evaluated through
 * PHP's built-in date() and strtotime() function according to your system locale.
 *
 * For all computations involving the current date and time,
 * please use {@link DBDatetime::now()} instead of PHP's built-in date() and time()
 * methods. This ensures that all time-based computations are testable with mock dates
 * through {@link DBDatetime::set_mock_now()}.
 *
 * Example definition via {@link DataObject::$db}:
 * <code>
 * static $db = array(
 *  "Expires" => "DBDatetime",
 * );
 * </code>
 *
 * @todo Add localization support, see http://open.silverstripe.com/ticket/2931
 *
 * @package framework
 * @subpackage orm
 */
class DBDatetime extends DBDate implements TemplateGlobalProvider {

	/**
	 * @config
	 * @see Date::nice_format
	 * @see Time::nice_format
	 */
	private static $nice_format = 'd/m/Y g:ia';

	public function setValue($value, $record = null, $markChanged = true) {
		if($value === false || $value === null || (is_string($value) && !strlen($value))) {
			// don't try to evaluate empty values with strtotime() below, as it returns "1970-01-01" when it should be
			// saved as NULL in database
			$this->value = null;
			return;
		}

		// Default to NZ date format - strtotime expects a US date
		if(preg_match('#^([0-9]+)/([0-9]+)/([0-9]+)$#', $value, $parts)) {
			$value = "$parts[2]/$parts[1]/$parts[3]";
		}

		if(is_numeric($value)) {
			$this->value = date('Y-m-d H:i:s', $value);
		} elseif(is_string($value)) {
			try {
				$date = new DateTime($value);
				$this->value = $date->format('Y-m-d H:i:s');
				return;
			} catch(Exception $e) {
				$this->value = null;
				return;
			}
		}
	}

	/**
	 * Returns the date and time in the format specified by the config value nice_format, or 'd/m/Y g:ia'
	 * by default (e.g. '31/01/2014 2:23pm').
	 * @return string Formatted date and time.
	 */
	public function Nice() {
		if($this->value) return $this->Format($this->config()->nice_format);
	}

	/**
	 * Returns the date and time (in 24-hour format) using the format string 'd/m/Y H:i' e.g. '28/02/2014 13:32'.
	 * @return string Formatted date and time.
	 */
	public function Nice24() {
		if($this->value) return $this->Format('d/m/Y H:i');
	}

	/**
	 * Returns the date using the format string 'd/m/Y' e.g. '28/02/2014'.
	 * @return string Formatted date.
	 */
	public function Date() {
		if($this->value) return $this->Format('d/m/Y');
	}

	/**
	 * Returns the time in 12-hour format using the format string 'g:ia' e.g. '1:32pm'.
	 * @return string Formatted time.
	 */
	public function Time() {
		if($this->value) return $this->Format('g:ia');
	}

	/**
	 * Returns the time in 24-hour format using the format string 'H:i' e.g. '13:32'.
	 * @return string Formatted time.
	 */
	public function Time24() {
		if($this->value) return $this->Format('H:i');
	}

	/**
	 * Return a date and time formatted as per a CMS user's settings.
	 *
	 * @param Member $member
	 * @return boolean | string A time and date pair formatted as per user-defined settings.
	 */
	public function FormatFromSettings($member = null) {
		require_once 'Zend/Date.php';

		if(!$member) {
			if(!Member::currentUserID()) {
				return false;
			}
			$member = Member::currentUser();
		}

		$formatD = $member->getDateFormat();
		$formatT = $member->getTimeFormat();

		$zendDate = new Zend_Date($this->getValue(), 'y-MM-dd HH:mm:ss');
		return $zendDate->toString($formatD).' '.$zendDate->toString($formatT);
	}

	public function requireField() {
		$parts=Array('datatype'=>'datetime', 'arrayValue'=>$this->arrayValue);
		$values=Array('type'=>'datetime', 'parts'=>$parts);
		DB::require_field($this->tableName, $this->name, $values);
	}

	/**
	 * Returns the url encoded date and time in ISO 6801 format using format
	 * string 'Y-m-d%20H:i:s' e.g. '2014-02-28%2013:32:22'.
	 *
	 * @return string Formatted date and time.
	 */
	public function URLDatetime() {
		if($this->value) return $this->Format('Y-m-d%20H:i:s');
	}

	public function scaffoldFormField($title = null, $params = null) {
		$field = DatetimeField::create($this->name, $title);

		// Show formatting hints for better usability
		$dateField = $field->getDateField();
		$dateField->setDescription(sprintf(
			_t('FormField.Example', 'e.g. %s', 'Example format'),
			Convert::raw2xml(Zend_Date::now()->toString($dateField->getConfig('dateformat')))
		));
		$dateField->setAttribute('placeholder', $dateField->getConfig('dateformat'));
		$timeField = $field->getTimeField();
		$timeField->setDescription(sprintf(
			_t('FormField.Example', 'e.g. %s', 'Example format'),
			Convert::raw2xml(Zend_Date::now()->toString($timeField->getConfig('timeformat')))
		));
		$timeField->setAttribute('placeholder', $timeField->getConfig('timeformat'));

		return $field;
	}

	/**
	 *
	 */
	protected static $mock_now = null;

	/**
	 * Returns either the current system date as determined
	 * by date(), or a mocked date through {@link set_mock_now()}.
	 *
	 * @return static
	 */
	public static function now() {
		if(self::$mock_now) {
			return self::$mock_now;
		} else {
			return DBField::create_field('SilverStripe\ORM\FieldType\DBDatetime', date('Y-m-d H:i:s'));
		}
	}

	/**
	 * Mock the system date temporarily, which is useful for time-based unit testing.
	 * Use {@link clear_mock_now()} to revert to the current system date.
	 * Caution: This sets a fixed date that doesn't increment with time.
	 *
	 * @param DBDatetime|string $datetime Either in object format, or as a DBDatetime compatible string.
	 * @throws Exception
	 */
	public static function set_mock_now($datetime) {
		if($datetime instanceof DBDatetime) {
			self::$mock_now = $datetime;
		} elseif(is_string($datetime)) {
			self::$mock_now = DBField::create_field('SilverStripe\ORM\FieldType\DBDatetime', $datetime);
		} else {
			throw new Exception('DBDatetime::set_mock_now(): Wrong format: ' . $datetime);
		}
	}

	/**
	 * Clear any mocked date, which causes
	 * {@link Now()} to return the current system date.
	 */
	public static function clear_mock_now() {
		self::$mock_now = null;
	}

	public static function get_template_global_variables() {
		return array(
			'Now' => array('method' => 'now', 'casting' => 'SilverStripe\ORM\FieldType\DBDatetime'),
		);
	}
}

