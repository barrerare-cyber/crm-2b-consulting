<?php

if (! defined('ABSPATH')) {
	exit;
}

class TwoB_CRM_Dani_Sales_Policy_Module {
	public static function sequence_policy_for_origin_channel($origin_channel) {
		return match (sanitize_key((string) $origin_channel)) {
			'red_calida', 'whatsapp' => 'short',
			'referral' => 'manual',
			'linkedin', 'email' => 'full',
			default => 'full',
		};
	}

	public static function temperature_seed_for_origin_channel($origin_channel, $stage = '') {
		$detail_stage = TwoB_CRM_Dani_Leads_Module::normalize_detail_stage_key((string) $stage);

		if (in_array($detail_stage, array('responded_interested', 'meeting_scheduled', 'meeting_done', 'proposal_sent', 'won'), true)) {
			return 'hot';
		}

		if (in_array($detail_stage, array('lost', 'closed_no_response'), true)) {
			return 'cold';
		}

		return match (sanitize_key((string) $origin_channel)) {
			'linkedin', 'email', 'whatsapp', 'red_calida', 'referral' => 'warm',
			default => 'warm',
		};
	}
}
