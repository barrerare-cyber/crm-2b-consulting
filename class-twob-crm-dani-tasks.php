<?php

if (! defined('ABSPATH')) {
	exit;
}

class TwoB_CRM_Dani_Tasks_Module {
	public static function normalize_status($status) {
		$status = sanitize_key((string) $status);
		$aliases = array(
			'pending' => 'pending',
			'open' => 'pending',
			'todo' => 'pending',
			'in_progress' => 'in_progress',
			'doing' => 'in_progress',
			'done' => 'done',
			'completed' => 'done',
		);

		return isset($aliases[$status]) ? $aliases[$status] : 'pending';
	}

	public static function normalize_recurrence($recurrence) {
		$recurrence = sanitize_key((string) $recurrence);
		$allowed = array(
			'none' => 'none',
			'daily' => 'daily',
			'weekly' => 'weekly',
			'monthly' => 'monthly',
		);

		return isset($allowed[$recurrence]) ? $recurrence : 'none';
	}

	public static function is_open_status($status) {
		return in_array(self::normalize_status($status), array('pending', 'in_progress'), true);
	}

	public static function next_due_by_recurrence($due, $recurrence) {
		$due = trim((string) $due);
		$recurrence = self::normalize_recurrence($recurrence);
		if (! $due || 'none' === $recurrence) {
			return '';
		}
		$ts = strtotime($due);
		if (! $ts) {
			return '';
		}
		if ('daily' === $recurrence) {
			return date('Y-m-d', strtotime('+1 day', $ts));
		}
		if ('weekly' === $recurrence) {
			return date('Y-m-d', strtotime('+7 days', $ts));
		}
		return date('Y-m-d', strtotime('+1 month', $ts));
	}

	public static function sanitize_time($time_value, $allow_empty = true) {
		$time_value = trim((string) $time_value);
		if ('' === $time_value) {
			return $allow_empty ? '' : new WP_Error('invalid_time_required', 'La hora es obligatoria.');
		}
		if (! preg_match('/^\d{2}:\d{2}$/', $time_value)) {
			return new WP_Error('invalid_time_format', 'La hora debe estar en formato HH:MM.');
		}
		list($hour, $minute) = array_map('intval', explode(':', $time_value));
		if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
			return new WP_Error('invalid_time_value', 'La hora no es valida.');
		}

		return sprintf('%02d:%02d', $hour, $minute);
	}
}
