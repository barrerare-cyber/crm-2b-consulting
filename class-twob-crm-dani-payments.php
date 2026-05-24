<?php

if (! defined('ABSPATH')) {
	exit;
}

class TwoB_CRM_Dani_Payments_Module {
	public static function normalize_status($status) {
		$status = sanitize_key((string) $status);
		$allowed = array(
			'pending' => 'pending',
			'paid' => 'paid',
			'overdue' => 'overdue',
		);

		return isset($allowed[$status]) ? $status : 'pending';
	}
}
