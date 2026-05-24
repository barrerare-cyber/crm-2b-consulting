<?php

if (! defined('ABSPATH')) {
	exit;
}

class TwoB_CRM_Dani_Message_Engine_Module {
	public static function anthropic_model_name() {
		return 'claude-sonnet-4-20250514';
	}

	public static function extract_text($data) {
		if (! is_array($data)) {
			return new WP_Error('anthropic_invalid_payload', 'La respuesta de Anthropic no pudo leerse.');
		}

		$chunks = array();
		foreach ((array) ($data['content'] ?? array()) as $item) {
			if (is_array($item) && 'text' === ($item['type'] ?? '') && ! empty($item['text'])) {
				$chunks[] = trim((string) $item['text']);
			}
		}

		$message = trim(implode("\n\n", array_filter($chunks)));
		if ('' === $message) {
			return new WP_Error('anthropic_empty_message', 'Anthropic no devolvió un mensaje utilizable.');
		}

		return $message;
	}

	public static function request_payload($payload, $api_key = '', $timeout = 20) {
		$api_key = trim((string) $api_key);
		if ('' === $api_key) {
			return new WP_Error('anthropic_missing_key', 'Configura primero la API key de Anthropic en Configuración.');
		}

		$payload = is_array($payload) ? $payload : array();
		if (empty($payload['model'])) {
			$payload['model'] = self::anthropic_model_name();
		}
		if (empty($payload['max_tokens'])) {
			$payload['max_tokens'] = 400;
		}

		$body = wp_json_encode($payload);

		$response = wp_remote_post('https://api.anthropic.com/v1/messages', array(
			'timeout' => max(10, (int) $timeout),
			'headers' => array(
				'x-api-key' => $api_key,
				'anthropic-version' => '2023-06-01',
				'content-type' => 'application/json',
			),
			'body' => $body,
		));

		if (is_wp_error($response)) {
			return new WP_Error('anthropic_request_failed', $response->get_error_message());
		}

		$code = (int) wp_remote_retrieve_response_code($response);
		$raw_body = (string) wp_remote_retrieve_body($response);
		$data = json_decode($raw_body, true);

		if ($code < 200 || $code >= 300) {
			$message = is_array($data) && ! empty($data['error']['message']) ? (string) $data['error']['message'] : ('La API respondió con estado ' . $code . '.');
			return new WP_Error('anthropic_http_' . $code, $message);
		}

		if (! is_array($data)) {
			return new WP_Error('anthropic_invalid_json', 'La respuesta de Anthropic no pudo leerse.');
		}

		return $data;
	}

	public static function request_message($prompt, $api_key = '', $max_tokens = 400) {
		$data = self::request_payload(array(
			'model' => self::anthropic_model_name(),
			'max_tokens' => max(80, (int) $max_tokens),
			'messages' => array(
				array(
					'role' => 'user',
					'content' => (string) $prompt,
				),
			),
		), $api_key, 20);

		if (is_wp_error($data)) {
			return $data;
		}

		return self::extract_text($data);
	}

	public static function build_task_prompt($context) {
		$defaults = array(
			'nombre' => 'no especificado',
			'empresa' => 'Sin empresa',
			'cargo' => 'no especificado',
			'url' => '',
			'sector' => 'No especificado',
			'canal' => 'Sin canal',
			'outbound_channel' => 'Sin canal',
			'hallazgo' => 'Sin hallazgo técnico registrado',
			'linkedin' => '',
			'servicio' => 'No definido',
			'temperatura' => 'Tibio',
			'dia_ciclo' => 'Seguimiento',
			'historial' => 'Sin contacto previo registrado',
			'message_type' => 'seguimiento',
		);
		$context = wp_parse_args((array) $context, $defaults);

		return <<<PROMPT
Eres el asistente comercial de 2B Consulting, una consultora técnica digital chilena. Generas mensajes de seguimiento comercial para Danitza "Dani" Guerrero, la directora comercial.

METODOLOGÍA 2B CONSULTING:
- El gancho siempre es un hallazgo técnico real del sitio web del prospecto, no una propuesta genérica
- El mensaje habla de impacto en el negocio, no de problemas técnicos
- Tono: par a par con el ejecutivo. Sin lenguaje de agencia ni de vendedor
- NUNCA mencionar "mantención web" ni "servicio digital"
- Precio del diagnóstico: $350.000 + IVA. Si contratan activación en 30 días, se aplica como abono

ADAPTAR TONO SEGÚN CARGO:
- Gerente General / CEO / Dueño: impacto en negocio, control del activo digital, decisión estratégica
- Gerente Comercial / Marketing: leads perdidos, campañas afectadas, formularios que no llegan
- CTO / Informático / TI: lenguaje más técnico, evidencia específica, deuda técnica
- Sin cargo conocido: tono ejecutivo general

DATOS DEL PROSPECTO:
Nombre: {$context['nombre']}
Empresa: {$context['empresa']}
Cargo: {$context['cargo']}
URL del sitio: {$context['url']}
Sector: {$context['sector']}
Canal de contacto original: {$context['canal']}
Canal de envío para este mensaje: {$context['outbound_channel']}
Hallazgo técnico detectado: {$context['hallazgo']}
LinkedIn persona: {$context['linkedin']}
Servicio de interés: {$context['servicio']}
Día del ciclo: {$context['dia_ciclo']}
Temperatura actual: {$context['temperatura']}

HISTORIAL DE CONTACTO:
{$context['historial']}

TIPO DE MENSAJE A GENERAR: {$context['message_type']}
Opciones y su propósito:
- d3: primer seguimiento. Reforzar el hallazgo con impacto de negocio. Proponer llamada de 15 min.
- d7: segundo seguimiento. Mostrar que hay más de un problema. Crear urgencia sin presionar.
- d10: último mensaje antes del cierre. Tono directo, cálido, sin insistencia. Dejar la puerta abierta.
- d12: cierre limpio. Sin vender. Solo despedirse profesionalmente y dejar contacto.
- reactivacion_email: reactivar lead cerrado. Mencionar que el precio bajó a $350K.
- reactivacion_formulario: pedir contacto del decisor, no vender directamente.
- reactivacion_telefono: pedir el email o contacto directo del decisor. No vender.
- reactivacion_instagram: pedir el contacto del decisor por DM, sin vender directo.
- reactivacion_facebook: pedir el contacto del decisor por DM, sin vender directo.
- reactivacion_reunion: confirmar o agendar reunión en tono ejecutivo.
- reactivacion: mensaje de reactivación corto y ejecutivo según el canal disponible.

INSTRUCCIONES DE FORMATO:
- Si el mensaje es para LinkedIn: máximo 280 caracteres
- Si es para Email: incluir asunto en primera línea con formato "Asunto: [texto]", luego el cuerpo
- Si es para WhatsApp: máximo 200 caracteres
- Para llamada: devolver solo el guion listo para leer
- Para otros canales: 3-5 líneas máximo
- NO usar bullets ni listas
- Escribir en español chileno profesional
- NO incluir explicaciones ni comentarios
- Devolver SOLO el mensaje listo para enviar
PROMPT;
	}

	public static function generate_with_fallback($prompt, $fallback = '', $api_key = '', $max_tokens = 200) {
		$message = self::request_message($prompt, $api_key, $max_tokens);
		if (is_wp_error($message)) {
			return $fallback;
		}
		return trim((string) $message) ?: $fallback;
	}
}
