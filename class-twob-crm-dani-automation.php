<?php

if (! defined('ABSPATH')) {
	exit;
}

class TwoB_CRM_Dani_Automation_Module {
	public static function next_daily_timestamp($time_string) {
		$timestamp = strtotime('today ' . $time_string, current_time('timestamp'));
		if (! $timestamp || $timestamp <= current_time('timestamp')) {
			$timestamp = strtotime('tomorrow ' . $time_string, current_time('timestamp'));
		}
		return (int) $timestamp;
	}
}
