<?php

if (! defined('ABSPATH')) {
	exit;
}

class TwoB_CRM_Dani_Reports_Module {
	const CACHE_VERSION_OPTION = 'twob_crm_dani_metrics_cache_version';

	public static function remember($suffix, $ttl, callable $callback) {
		$version = (string) get_option(self::CACHE_VERSION_OPTION, '1');
		$key = 'twob_crm_' . md5($version . '|' . (string) $suffix);
		$cached = get_transient($key);
		if (false !== $cached) {
			return $cached;
		}

		$value = call_user_func($callback);
		set_transient($key, $value, max(60, (int) $ttl));
		return $value;
	}

	public static function flush_cache() {
		update_option(self::CACHE_VERSION_OPTION, (string) microtime(true), false);
	}
}
