<?php

if (! defined('ABSPATH')) {
	exit;
}

class TwoB_CRM_Dani_Leads_Module {
	public static function normalize_stage_key($stage) {
		$stage = sanitize_key((string) $stage);
		$map = array(
			'pre_contact' => 'pre_contact',
			'precontact' => 'pre_contact',
			'pre_contacto' => 'pre_contact',
			'new' => 'new',
			'tracking' => 'tracking',
			'en_seguimiento' => 'tracking',
			'followup' => 'tracking',
			'contacted' => 'tracking',
			'interested' => 'interested',
			'responded' => 'interested',
			'responded_interested' => 'interested',
			'pending' => 'pending',
			'meeting_pending' => 'pending',
			'meeting_booked' => 'pending',
			'meeting_scheduled' => 'pending',
			'meeting_done' => 'pending',
			'proposal' => 'pending',
			'proposal_sent' => 'pending',
			'closed' => 'closed',
			'closed_no_response' => 'closed',
			'closed_won' => 'closed',
			'closed_lost' => 'closed',
			'won' => 'closed',
			'lost' => 'closed',
		);

		return isset($map[$stage]) ? $map[$stage] : $stage;
	}

	public static function normalize_detail_stage_key($stage) {
		$stage = sanitize_key((string) $stage);
		$map = array(
			'pre_contact' => 'pre_contact',
			'precontact' => 'pre_contact',
			'pre_contacto' => 'pre_contact',
			'new' => 'new',
			'contacted' => 'contacted',
			'interested' => 'responded_interested',
			'responded' => 'responded_interested',
			'responded_interested' => 'responded_interested',
			'meeting_pending' => 'meeting_scheduled',
			'meeting_booked' => 'meeting_scheduled',
			'meeting_scheduled' => 'meeting_scheduled',
			'meeting_done' => 'meeting_done',
			'proposal' => 'proposal_sent',
			'proposal_sent' => 'proposal_sent',
			'won' => 'won',
			'closed_won' => 'won',
			'ganado' => 'won',
			'lost' => 'lost',
			'closed_no_response' => 'closed_no_response',
			'sin_respuesta' => 'closed_no_response',
			'closed_lost' => 'lost',
			'perdido' => 'lost',
		);

		return isset($map[$stage]) ? $map[$stage] : $stage;
	}

	public static function validate_payload($data) {
		$name = isset($data['name']) ? trim((string) $data['name']) : '';
		$email = isset($data['email']) ? trim((string) $data['email']) : '';
		$origin = isset($data['origin_channel']) ? trim((string) $data['origin_channel']) : '';
		$client_type = isset($data['client_type']) ? trim((string) $data['client_type']) : '';
		$service_interest = isset($data['service_interest']) ? trim((string) $data['service_interest']) : '';

		if (! $name) {
			return new WP_Error('lead_name_required', 'Nombre completo es obligatorio.');
		}
		if ($email && ! is_email($email)) {
			return new WP_Error('lead_email_invalid', 'Email invalido.');
		}
		if (! $origin) {
			return new WP_Error('lead_origin_required', 'Canal de origen es obligatorio.');
		}
		if (! $client_type) {
			return new WP_Error('lead_type_required', 'Tipo de cliente es obligatorio.');
		}
		if (! $service_interest) {
			return new WP_Error('lead_service_required', 'Servicio de interes es obligatorio.');
		}

		return true;
	}

	public static function sanitize_date($value, $allow_empty = true, $field_label = 'Fecha') {
		$value = trim((string) $value);
		if ('' === $value) {
			return $allow_empty ? '' : new WP_Error('invalid_date_required', $field_label . ' es obligatoria.');
		}
		if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
			return new WP_Error('invalid_date_format', $field_label . ' debe estar en formato YYYY-MM-DD.');
		}
		$parts = explode('-', $value);
		if (3 !== count($parts) || ! checkdate((int) $parts[1], (int) $parts[2], (int) $parts[0])) {
			return new WP_Error('invalid_date_value', $field_label . ' no es valida.');
		}

		return $value;
	}

	public static function sanitize_stage($stage, $allowed_stages, $allow_empty = false, $default = 'new') {
		$stage = self::normalize_detail_stage_key($stage);
		if ('' === $stage) {
			return $allow_empty ? '' : $default;
		}
		if (! isset($allowed_stages[$stage])) {
			return $allow_empty ? '' : $default;
		}

		return $stage;
	}

	public static function sanitize_budget($budget, $allowed_budgets) {
		$budget = sanitize_key((string) $budget);
		if ($budget && ! isset($allowed_budgets[$budget])) {
			return '';
		}
		return $budget;
	}
}
