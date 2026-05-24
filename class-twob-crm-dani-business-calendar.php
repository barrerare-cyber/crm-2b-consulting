<?php

if (! defined('ABSPATH')) {
	exit;
}

class TwoB_CRM_Dani_Business_Calendar_Module {
	public static function normalize_iso_day($day) {
		$day = trim((string) $day);
		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) {
			return '';
		}
		$timestamp = strtotime($day);
		return $timestamp ? date('Y-m-d', $timestamp) : '';
	}

	public static function chile_solstice_holiday_day($year) {
		$year = (int) $year;
		if ($year <= 0) {
			return '';
		}

		$overrides = array(
			2021 => '2021-06-21',
			2022 => '2022-06-21',
			2023 => '2023-06-21',
			2024 => '2024-06-20',
			2025 => '2025-06-20',
			2026 => '2026-06-21',
			2027 => '2027-06-21',
			2028 => '2028-06-20',
			2029 => '2029-06-20',
			2030 => '2030-06-21',
			2031 => '2031-06-21',
			2032 => '2032-06-20',
			2033 => '2033-06-20',
			2034 => '2034-06-21',
			2035 => '2035-06-21',
		);

		if (isset($overrides[$year])) {
			return $overrides[$year];
		}

		return sprintf('%04d-06-21', $year);
	}

	public static function shift_chile_monday_holiday($day) {
		$day = self::normalize_iso_day($day);
		if (! $day) {
			return '';
		}

		$weekday = (int) date('N', strtotime($day));
		if ($weekday >= 2 && $weekday <= 4) {
			return date('Y-m-d', strtotime($day . ' -' . ($weekday - 1) . ' days'));
		}
		if (5 === $weekday) {
			return date('Y-m-d', strtotime($day . ' +3 days'));
		}

		return $day;
	}

	public static function chile_reformation_holiday_day($year) {
		$year = (int) $year;
		if ($year <= 0) {
			return '';
		}

		$base = sprintf('%04d-10-31', $year);
		$weekday = (int) date('N', strtotime($base));
		if (2 === $weekday) {
			return date('Y-m-d', strtotime($base . ' -4 days'));
		}
		if (3 === $weekday) {
			return date('Y-m-d', strtotime($base . ' +2 days'));
		}

		return $base;
	}

	public static function chile_holiday_days_for_year($year) {
		static $cache = array();

		$year = (int) $year;
		if ($year <= 0) {
			return array();
		}
		if (isset($cache[$year])) {
			return $cache[$year];
		}

		$easter = easter_date($year);
		$holidays = array(
			sprintf('%04d-01-01', $year),
			date('Y-m-d', strtotime('-2 days', $easter)),
			date('Y-m-d', strtotime('-1 day', $easter)),
			sprintf('%04d-05-01', $year),
			sprintf('%04d-05-21', $year),
			self::chile_solstice_holiday_day($year),
			self::shift_chile_monday_holiday(sprintf('%04d-06-29', $year)),
			sprintf('%04d-07-16', $year),
			sprintf('%04d-08-15', $year),
			sprintf('%04d-09-18', $year),
			sprintf('%04d-09-19', $year),
			self::shift_chile_monday_holiday(sprintf('%04d-10-12', $year)),
			self::chile_reformation_holiday_day($year),
			sprintf('%04d-11-01', $year),
			sprintf('%04d-12-08', $year),
			sprintf('%04d-12-25', $year),
		);

		$holidays = array_values(array_unique(array_filter(array_map(array(__CLASS__, 'normalize_iso_day'), $holidays))));
		sort($holidays);
		$cache[$year] = $holidays;
		return $cache[$year];
	}

	public static function chile_holiday_days_window($from_year = 0, $to_year = 0) {
		$from_year = $from_year > 0 ? (int) $from_year : (int) current_time('Y');
		$to_year = $to_year > 0 ? (int) $to_year : $from_year;
		if ($to_year < $from_year) {
			$tmp = $from_year;
			$from_year = $to_year;
			$to_year = $tmp;
		}

		$days = array();
		for ($year = $from_year; $year <= $to_year; $year++) {
			$days = array_merge($days, self::chile_holiday_days_for_year($year));
		}

		$days = array_values(array_unique($days));
		sort($days);
		return $days;
	}

	public static function is_chile_holiday($day) {
		$day = self::normalize_iso_day($day);
		if (! $day) {
			return false;
		}

		return in_array($day, self::chile_holiday_days_for_year((int) substr($day, 0, 4)), true);
	}

	public static function is_chile_business_day($day) {
		$day = self::normalize_iso_day($day);
		if (! $day) {
			return false;
		}

		$weekday = (int) date('N', strtotime($day));
		return $weekday < 6 && ! self::is_chile_holiday($day);
	}

	public static function next_chile_business_day($day, $direction = 1, $allow_same = true) {
		$day = self::normalize_iso_day($day);
		if (! $day) {
			return '';
		}

		$direction = (int) $direction;
		if (0 === $direction) {
			$direction = 1;
		}
		if ($allow_same && self::is_chile_business_day($day)) {
			return $day;
		}

		$cursor = $day;
		$step = $direction > 0 ? 1 : -1;
		for ($guard = 0; $guard < 370; $guard++) {
			$cursor = date('Y-m-d', strtotime($cursor . ($step > 0 ? ' +1 day' : ' -1 day')));
			if (self::is_chile_business_day($cursor)) {
				return $cursor;
			}
		}

		return $day;
	}

	public static function normalize_business_due_day($day) {
		$day = self::normalize_iso_day($day);
		if (! $day) {
			return '';
		}

		return self::next_chile_business_day($day, 1, true);
	}

	public static function add_chile_business_days($day, $days) {
		$day = self::normalize_iso_day($day);
		$days = (int) $days;
		if (! $day) {
			return '';
		}
		if ($days <= 0) {
			return self::normalize_business_due_day($day);
		}

		$cursor = $day;
		$count = 0;
		while ($count < $days) {
			$cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
			if (self::is_chile_business_day($cursor)) {
				$count++;
			}
		}

		return $cursor;
	}

	public static function shift_chile_business_days($day, $days) {
		$day = self::normalize_iso_day($day);
		$days = (int) $days;
		if (! $day) {
			return '';
		}
		if (0 === $days) {
			return self::normalize_business_due_day($day);
		}
		if ($days > 0) {
			return self::add_chile_business_days($day, $days);
		}

		$cursor = self::normalize_business_due_day($day);
		$remaining = abs($days);
		while ($remaining > 0) {
			$cursor = self::next_chile_business_day($cursor, -1, false);
			$remaining--;
		}

		return $cursor;
	}

	public static function business_days_between_dates($older_day, $newer_day = '') {
		$older_day = self::normalize_iso_day($older_day);
		$newer_day = self::normalize_iso_day($newer_day ?: current_time('Y-m-d'));
		if (! $older_day || ! $newer_day) {
			return 0;
		}

		$older_ts = strtotime($older_day);
		$newer_ts = strtotime($newer_day);
		if (! $older_ts || ! $newer_ts || $newer_ts <= $older_ts) {
			return 0;
		}

		$count = 0;
		$cursor = $older_day;
		while ($cursor < $newer_day) {
			$cursor = date('Y-m-d', strtotime($cursor . ' +1 day'));
			if ($cursor <= $newer_day && self::is_chile_business_day($cursor)) {
				$count++;
			}
		}

		return $count;
	}
}
