<?php

if (! defined('ABSPATH')) {
	exit;
}

class TwoB_CRM_Dani_Daily_Plan_Module {
	const PAGE_SLUG = 'twob-crm-plan-diario';
	const ACCESS_CAP = 'twob_crm_access';
	const NONCE_ACTION = 'twob_crm_daily_plan';
	const PROGRESS_OPTION_PREFIX = 'twob_crm_daily_plan_progress_';
	const START_DATE_OPTION = 'twob_plan_fecha_inicio';

	public static function init() {
		add_action('admin_menu', array(__CLASS__, 'register_menu'), 20);
		add_action('admin_menu', array(__CLASS__, 'reorder_menu'), 99);
		add_action('wp_ajax_twob_crm_daily_plan_toggle', array(__CLASS__, 'ajax_toggle_progress'));
		add_action('wp_ajax_twob_crm_daily_plan_create_task', array(__CLASS__, 'ajax_create_task'));
		add_action('wp_ajax_twob_crm_daily_plan_save_finding', array(__CLASS__, 'ajax_save_finding'));
		add_action('wp_ajax_twob_crm_daily_plan_activate_precontact', array(__CLASS__, 'ajax_activate_precontact'));
	}

	public static function register_menu() {
		add_submenu_page(
			'twob-crm-visual-dashboard',
			'Plan diario',
			'Plan diario',
			self::ACCESS_CAP,
			self::PAGE_SLUG,
			array(__CLASS__, 'render_page')
		);
	}

	public static function reorder_menu() {
		global $submenu;

		if (empty($submenu['twob-crm-visual-dashboard']) || ! is_array($submenu['twob-crm-visual-dashboard'])) {
			return;
		}

		$items = array_values($submenu['twob-crm-visual-dashboard']);
		$plan_index = null;

		foreach ($items as $index => $item) {
			if (($item[2] ?? '') === self::PAGE_SLUG) {
				$plan_index = $index;
				break;
			}
		}

		if (null === $plan_index) {
			return;
		}

		$plan_item = $items[$plan_index];
		unset($items[$plan_index]);
		$items = array_values($items);
		array_splice($items, 1, 0, array($plan_item));
		$submenu['twob-crm-visual-dashboard'] = $items;
	}

	public static function ajax_toggle_progress() {
		if (! current_user_can(self::ACCESS_CAP)) {
			wp_send_json_error(array('message' => 'No tienes permisos para actualizar el plan diario.'), 403);
		}

		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		$task_key = sanitize_key(isset($_POST['task_key']) ? wp_unslash($_POST['task_key']) : '');
		if (! preg_match('/^check_task_(\d|1\d|20)_(dani|renata)_[1-3]$/', $task_key)) {
			wp_send_json_error(array('message' => 'La tarea indicada no es valida.'), 400);
		}

		$checked_raw = isset($_POST['checked']) ? wp_unslash($_POST['checked']) : '';
		$checked = in_array($checked_raw, array('1', 'true', 'yes', 1, true), true);

		$progress = self::get_progress();
		if ($checked) {
			$progress[$task_key] = 1;
		} else {
			unset($progress[$task_key]);
		}

		update_option(self::progress_option_name(), $progress, false);

		wp_send_json_success(
			array(
				'taskKey' => $task_key,
				'checked' => $checked,
			)
		);
	}

	public static function ajax_create_task() {
		if (! current_user_can('edit_crm_tasks')) {
			wp_send_json_error(array('message' => 'No tienes permisos para crear tareas del plan diario.'), 403);
		}

		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		$task_key = sanitize_key(isset($_POST['task_key']) ? wp_unslash($_POST['task_key']) : '');
		if (! preg_match('/^task_(\d|1\d|20)_(dani|renata)_[1-3]$/', $task_key)) {
			wp_send_json_error(array('message' => 'La referencia del bloque no es valida.'), 400);
		}

		$day_num = isset($_POST['day_num']) ? absint($_POST['day_num']) : 0;
		$who = sanitize_key(isset($_POST['who']) ? wp_unslash($_POST['who']) : '');
		if (! in_array($who, array('dani', 'renata'), true) || $day_num < 1 || $day_num > 20) {
			wp_send_json_error(array('message' => 'No pude identificar el bloque del plan.'), 400);
		}

		$title = sanitize_text_field(isset($_POST['title']) ? wp_unslash($_POST['title']) : '');
		$description = sanitize_textarea_field(isset($_POST['description']) ? wp_unslash($_POST['description']) : '');
		$category = self::sanitize_plan_task_category(isset($_POST['category']) ? wp_unslash($_POST['category']) : 'operational');
		$type = self::sanitize_plan_task_type(isset($_POST['task_type']) ? wp_unslash($_POST['task_type']) : 'other', $category);
		$priority = self::sanitize_plan_task_priority(isset($_POST['priority']) ? wp_unslash($_POST['priority']) : 'medium');
		$assignee = self::sanitize_plan_task_assignee(isset($_POST['assignee']) ? wp_unslash($_POST['assignee']) : $who);
		$start_time = self::sanitize_plan_task_time(isset($_POST['start_time']) ? wp_unslash($_POST['start_time']) : '');
		$duration = max(15, min(240, isset($_POST['duration']) ? absint($_POST['duration']) : 30));

		if (! $title) {
			$title = sprintf('Plan diario · Día %d · %s', $day_num, ucfirst($who));
		}

		$auto_key = self::plan_task_auto_key($task_key);
		$existing_task_id = self::existing_plan_task_id($auto_key);
		if ($existing_task_id) {
			$record = self::serialize_plan_task_record($existing_task_id, $task_key);
			wp_send_json_success(
				array(
					'created' => false,
					'taskId' => $existing_task_id,
					'taskKey' => $task_key,
					'record' => $record,
					'message' => 'Ese bloque ya tiene una tarea creada para este periodo.',
				)
			);
		}

		$task_id = wp_insert_post(
			array(
				'post_type' => 'crm_task',
				'post_status' => 'publish',
				'post_title' => $title,
				'post_content' => $description,
				'meta_input' => array(
					'_crm_task_due' => self::due_date_for_plan_day($day_num),
					'_crm_task_status' => 'pending',
					'_crm_task_lead_id' => 0,
					'_crm_task_type' => $type,
					'_crm_task_priority' => $priority,
					'_crm_task_start_time' => $start_time,
					'_crm_task_duration' => $duration,
					'_crm_task_category' => $category,
					'_crm_task_relation' => 'internal',
					'_crm_task_assignee' => $assignee,
					'_crm_task_pipeline_stage' => '',
					'_crm_task_recurrence' => 'none',
					'_crm_auto_key' => $auto_key,
					'_crm_daily_plan_task_key' => $task_key,
					'_crm_daily_plan_period' => self::current_period_key(),
					'_crm_daily_plan_day' => $day_num,
					'_crm_daily_plan_owner' => $who,
				),
			)
		);

		if (! $task_id || is_wp_error($task_id)) {
			wp_send_json_error(array('message' => 'No se pudo crear la tarea del plan diario.'), 500);
		}

		$record = self::serialize_plan_task_record((int) $task_id, $task_key);

		wp_send_json_success(
			array(
				'created' => true,
				'taskId' => (int) $task_id,
				'taskKey' => $task_key,
				'record' => $record,
				'message' => 'Tarea creada en el CRM.',
			)
		);
	}

	public static function ajax_save_finding() {
		if (! current_user_can('edit_crm_leads')) {
			wp_send_json_error(array('message' => 'No tienes permisos para guardar hallazgos.'), 403);
		}

		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		$lead_id = isset($_POST['lead_id']) ? absint($_POST['lead_id']) : 0;
		if (! $lead_id || 'crm_lead' !== get_post_type($lead_id)) {
			wp_send_json_error(array('message' => 'No pude identificar el lead.'), 400);
		}

		$technical_finding = sanitize_textarea_field(wp_unslash($_POST['technical_finding'] ?? ''));
		if (! $technical_finding) {
			wp_send_json_error(array('message' => 'Escribe un hallazgo antes de guardar.'), 400);
		}

		if (class_exists('TwoB_CRM_Dani_Visual') && method_exists('TwoB_CRM_Dani_Visual', 'save_lead_technical_finding')) {
			$result = TwoB_CRM_Dani_Visual::save_lead_technical_finding($lead_id, $technical_finding);
			if (is_wp_error($result)) {
				wp_send_json_error(array('message' => $result->get_error_message()), 400);
			}
		} else {
			update_post_meta($lead_id, '_crm_technical_finding', $technical_finding);
			update_post_meta($lead_id, '_crm_technical_reviewed_at', current_time('mysql'));
		}

		wp_send_json_success(
			array(
				'leadId' => $lead_id,
				'technicalFinding' => $technical_finding,
				'message' => 'Hallazgo guardado en el CRM.',
			)
		);
	}

	public static function ajax_activate_precontact() {
		if (! current_user_can('edit_crm_leads')) {
			wp_send_json_error(array('message' => 'No tienes permisos para activar leads.'), 403);
		}

		check_ajax_referer(self::NONCE_ACTION, 'nonce');

		$lead_id = isset($_POST['lead_id']) ? absint($_POST['lead_id']) : 0;
		$first_contact_channel = sanitize_key(wp_unslash($_POST['first_contact_channel'] ?? ''));
		if (! class_exists('TwoB_CRM_Dani_Visual') || ! method_exists('TwoB_CRM_Dani_Visual', 'activate_precontact_lead')) {
			wp_send_json_error(array('message' => 'No pude activar el lead desde este modulo.'), 500);
		}

		$result = TwoB_CRM_Dani_Visual::activate_precontact_lead($lead_id, current_time('Y-m-d'), $first_contact_channel);
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()), 400);
		}

		$policy = sanitize_key((string) ($result['sequence_policy'] ?? 'full'));
		$message = 'Lead activado y primer contacto registrado.';
		if ('manual' === $policy) {
			$message .= ' Este canal queda sin secuencia automatica.';
		} elseif ('short' === $policy) {
			$message .= ' Se genero solo D+3.';
		} else {
			$message .= ' Se genero el ciclo completo desde D+3.';
		}

		wp_send_json_success(
			array(
				'leadId' => $lead_id,
				'sequencePolicy' => $policy,
				'message' => $message,
			)
		);
	}

	public static function render_page() {
		if (! current_user_can(self::ACCESS_CAP)) {
			wp_die('No tienes permisos para ver el plan diario.');
		}

		$context = self::page_context();
		$links = $context['links'];
		?>
		<div class="wrap twob-daily-plan-wrap">
			<link rel="preconnect" href="https://fonts.googleapis.com">
			<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
			<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=Figtree:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
			<style><?php echo self::plan_styles(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></style>
			<script>window.twobDailyPlanConfig = <?php echo wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>
			<div class="twob-daily-plan-page">
				<section class="crm-shell">
					<div class="crm-summary">
						<div class="crm-summary-copy">
							<span class="crm-kicker">Modulo integrado al CRM Dani</span>
							<h1>Plan diario operativo</h1>
							<p>El playbook vive dentro del CRM, pero la verdad comercial sigue en Leads, Interacciones y Tareas. Los checks y atajos de esta pantalla se guardan sobre el ciclo actual del plan.</p>
						</div>
						<div class="crm-summary-meta">
							<div class="crm-meta-pill">
								<strong>Ciclo</strong>
								<span><?php echo esc_html($context['periodLabel']); ?></span>
							</div>
							<div class="crm-meta-pill">
								<strong>Inicio</strong>
								<span><?php echo esc_html($context['startDateLabel']); ?></span>
							</div>
							<div class="crm-meta-pill">
								<strong>Dia sugerido</strong>
								<span>Dia <?php echo esc_html((string) $context['currentDay']); ?></span>
							</div>
						</div>
					</div>

					<div class="crm-live-grid">
						<div class="crm-live-card is-progress">
							<span>Progreso del dia</span>
							<strong id="twobPlanProgress">0 / 0</strong>
							<small id="twobPlanProgressHint">Checks guardados para <?php echo esc_html($context['periodLabel']); ?></small>
						</div>
						<div class="crm-live-card">
							<span>Leads nuevos hoy</span>
							<strong><?php echo esc_html((string) $context['live']['newLeadsToday']); ?></strong>
							<small>Creados en el CRM hoy</small>
						</div>
						<div class="crm-live-card">
							<span>Interacciones hoy</span>
							<strong><?php echo esc_html((string) $context['live']['interactionsToday']); ?></strong>
							<small>Actividad registrada hoy</small>
						</div>
						<div class="crm-live-card">
							<span>Tareas CRM visibles</span>
							<strong><?php echo esc_html((string) $context['live']['visibleTasksToday']); ?></strong>
							<small>Abiertas y vencidas hoy o antes</small>
						</div>
						<div class="crm-live-card">
							<span>Reuniones esta semana</span>
							<strong><?php echo esc_html((string) $context['live']['meetingsThisWeek']); ?></strong>
							<small>Seguimiento de agenda comercial</small>
						</div>
					</div>

					<div class="crm-shortcuts">
						<a class="crm-btn" href="<?php echo esc_url($links['dashboard']); ?>">Dashboard</a>
						<a class="crm-btn" href="<?php echo esc_url($links['leads']); ?>">Leads</a>
						<a class="crm-btn" href="<?php echo esc_url($links['interactions']); ?>">Interacciones</a>
						<a class="crm-btn" href="<?php echo esc_url($links['tasks']); ?>">Tareas</a>
						<a class="crm-btn" href="<?php echo esc_url($links['pipeline']); ?>">Pipeline</a>
						<?php if (! empty($links['calendar'])) : ?>
							<a class="crm-btn" href="<?php echo esc_url($links['calendar']); ?>">Calendario</a>
						<?php endif; ?>
						<?php if (! empty($links['reports'])) : ?>
							<a class="crm-btn" href="<?php echo esc_url($links['reports']); ?>">Reportes</a>
						<?php endif; ?>
					</div>

					<div class="crm-note">
						<strong>Como usarlo bien:</strong> esta pantalla organiza el trabajo del dia; el avance comercial real se sigue cerrando en las pantallas del CRM.
					</div>
				</section>

				<header class="topbar">
					<div class="topbar-brand">2B<span>.</span> Plan diario</div>
					<div class="topbar-right">
						<div class="who-toggle">
							<button class="who-btn active" type="button" data-who="both" onclick="setWho('both', this)">Ambas</button>
							<button class="who-btn" type="button" data-who="dani" onclick="setWho('dani', this)">Dani</button>
							<button class="who-btn" type="button" data-who="renata" onclick="setWho('renata', this)">Renata</button>
						</div>
						<div class="week-sel">
							<button class="wsb active" type="button" data-week="1" onclick="setWeek(1, this)">S1</button>
							<button class="wsb" type="button" data-week="2" onclick="setWeek(2, this)">S2</button>
							<button class="wsb" type="button" data-week="3" onclick="setWeek(3, this)">S3</button>
							<button class="wsb" type="button" data-week="4" onclick="setWeek(4, this)">S4</button>
						</div>
						<button class="timer-btn" type="button" onclick="goToSuggestedDay()">Dia sugerido</button>
						<button class="timer-btn" type="button" onclick="openTimer()">Pomodoro</button>
					</div>
				</header>

				<div class="timer-overlay" id="timerOverlay">
					<div class="timer-box">
						<h3 id="timerPhaseLabel">Bloque de trabajo</h3>
						<div class="timer-display" id="timerDisplay">25:00</div>
						<div class="timer-phase" id="timerSubLabel">Enfocate - sin redes sociales ni WhatsApp</div>
						<div class="timer-controls">
							<button class="tcb tcb-go" type="button" id="timerStartBtn" onclick="startTimer()">Iniciar</button>
							<button class="tcb tcb-stop" type="button" onclick="resetTimer()">Reiniciar</button>
							<button class="tcb tcb-close" type="button" onclick="closeTimer()">Cerrar</button>
						</div>
						<div class="timer-tip">Tecnica Pomodoro: 25 min trabajo -> 5 min descanso.<br>Despues de 4 bloques: descanso de 20 min.</div>
					</div>
				</div>

				<main class="main">
					<div class="intro" id="dayIntro">
						<div class="intro-left">
							<h2 id="dayTitle">Plan mensual · Lunes a viernes</h2>
							<p id="dayFocus">Selecciona el dia de hoy para ver exactamente que hacer, paso a paso, sin necesidad de pensar ni decidir nada.</p>
						</div>
						<div class="intro-metrics" id="introMetrics">
							<div class="im"><div class="im-n">20</div><div class="im-l">Dias habiles</div></div>
							<div class="im"><div class="im-n">4</div><div class="im-l">Semanas</div></div>
							<div class="im"><div class="im-n">2</div><div class="im-l">Roles coordinados</div></div>
						</div>
					</div>

					<div class="adhd-bar">
						<div class="icon">Foco</div>
						<div>
							<h4>Este plan esta pensado para reducir friccion operativa</h4>
							<p>Maximo 3 tareas por persona por dia. Cada tarea trae pasos exactos, mensajes listos y un minimo viable para los dias mas pesados. Usa los checks como tablero compartido del mes; registra el trabajo comercial real en el CRM.</p>
						</div>
					</div>

					<div class="day-nav" id="dayNav"></div>
					<div class="week-summary" id="weekSummary"></div>
					<div id="dayContent"></div>
					<section class="crm-live-ops" id="liveOpsPanel"></section>
				</main>
			</div>
			<script><?php echo self::plan_script(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
		</div>
		<?php
	}

	private static function page_context() {
		$current_day = self::current_business_day_number();
		$current_week = (int) ceil($current_day / 5);
		$current_week = max(1, min(4, $current_week));
		$links = self::plan_links();
		$start_date = self::plan_start_date();

		return array(
			'ajaxUrl' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce(self::NONCE_ACTION),
			'periodLabel' => self::current_period_label(),
			'startDate' => $start_date,
			'startDateLabel' => self::human_date_label($start_date),
			'currentDay' => $current_day,
			'currentWeek' => $current_week,
			'canCreateTasks' => current_user_can('edit_crm_tasks'),
			'progress' => self::get_progress(),
			'live' => array(
				'newLeadsToday' => self::count_created_today('crm_lead'),
				'interactionsToday' => self::count_created_today('crm_interaction'),
				'visibleTasksToday' => self::count_visible_tasks_today(),
				'meetingsThisWeek' => self::count_meetings_this_week(),
			),
			'links' => $links,
			'resources' => self::plan_resources(),
			'liveOps' => self::live_ops_context(),
			'planTasks' => self::existing_plan_tasks(),
		);
	}

	private static function plan_links() {
		$base_admin = admin_url('admin.php');

		return array(
			'dashboard' => admin_url('admin.php?page=twob-crm-visual-dashboard'),
			'leads' => admin_url('admin.php?page=twob-crm-visual-leads'),
			'leadsPreContact' => admin_url('admin.php?page=twob-crm-visual-leads&focus=pre_contact'),
			'leadsToday' => admin_url('admin.php?page=twob-crm-visual-leads&focus=today'),
			'leadsNew' => admin_url('admin.php?page=twob-crm-visual-leads&focus=new'),
			'leadsContacted' => admin_url('admin.php?page=twob-crm-visual-leads&focus=contacted'),
			'leadsFollowup' => admin_url('admin.php?page=twob-crm-visual-leads&focus=followup'),
			'leadsInterested' => admin_url('admin.php?page=twob-crm-visual-leads&focus=interested'),
			'leadsArchive' => admin_url('admin.php?page=twob-crm-visual-leads&focus=archive'),
			'interactions' => admin_url('admin.php?page=twob-crm-visual-interactions'),
			'responses' => admin_url('admin.php?page=twob-crm-visual-interactions&focus=responses'),
			'tasks' => admin_url('admin.php?page=twob-crm-visual-tasks'),
			'tasksVisible' => admin_url('admin.php?page=twob-crm-visual-tasks') . '#twob-visible-actions',
			'pipeline' => admin_url('admin.php?page=twob-crm-visual-pipeline'),
			'proposals' => admin_url('admin.php?page=twob-crm-visual-proposals'),
			'clients' => admin_url('admin.php?page=twob-crm-visual-clients'),
			'payments' => current_user_can('edit_crm_payments') ? admin_url('admin.php?page=twob-crm-visual-payments') : '',
			'risk' => current_user_can('edit_crm_leads') ? admin_url('admin.php?page=twob-crm-visual-risk') : '',
			'calendar' => current_user_can('twob_crm_manage_google') ? admin_url('admin.php?page=twob-crm-visual-calendar') : '',
			'reports' => current_user_can('twob_crm_view_reports') ? admin_url('admin.php?page=twob-crm-visual-reports') : '',
			'siteAdmin' => $base_admin,
		);
	}

	private static function plan_resources() {
		return array(
			'salesNavigator' => 'https://app.linkedin.com/sales/',
			'linkedin' => 'https://www.linkedin.com/feed/',
			'whatsapp' => 'https://web.whatsapp.com/',
			'googleDocs' => 'https://docs.google.com/document/create',
			'googleSheets' => 'https://docs.google.com/spreadsheets/',
			'pageSpeed' => 'https://pagespeed.web.dev/',
			'mxToolbox' => 'https://mxtoolbox.com/SuperTool.aspx',
			'siteHome' => home_url('/'),
			'siteAdmin' => admin_url(),
		);
	}

	private static function current_period_key() {
		$configured_start = self::configured_plan_start_date();
		if ($configured_start) {
			return 'plan_' . str_replace('-', '_', $configured_start);
		}

		return current_time('Y_m');
	}

	private static function current_period_label() {
		$start_date = self::plan_start_date();
		$end_date = self::business_day_date_for_number(20);
		if (! $start_date) {
			return wp_date('F Y', current_time('timestamp'));
		}
		if (! $end_date) {
			return self::human_date_label($start_date);
		}
		return self::human_date_label($start_date) . ' -> ' . self::human_date_label($end_date);
	}

	private static function live_ops_context() {
		if (class_exists('TwoB_CRM_Dani_Visual') && method_exists('TwoB_CRM_Dani_Visual', 'daily_plan_renata_live_data')) {
			$payload = TwoB_CRM_Dani_Visual::daily_plan_renata_live_data(current_time('Y-m-d'), 10);
			if (is_array($payload)) {
				return array(
					'review' => array_values((array) ($payload['review'] ?? array())),
					'meeting' => array_values((array) ($payload['meeting'] ?? array())),
					'diagnostic' => array_values((array) ($payload['diagnostic'] ?? array())),
					'dani_ready' => array_values((array) ($payload['dani_ready'] ?? array())),
					'dani_waiting' => array_values((array) ($payload['dani_waiting'] ?? array())),
					'reactivation_prepare' => array_values((array) ($payload['reactivation_prepare'] ?? array())),
					'reactivation_tasks' => array_values((array) ($payload['reactivation_tasks'] ?? array())),
				);
			}
		}

		return array(
			'review' => array(),
			'meeting' => array(),
			'diagnostic' => array(),
			'dani_ready' => array(),
			'dani_waiting' => array(),
			'reactivation_prepare' => array(),
			'reactivation_tasks' => array(),
		);
	}

	private static function plan_start_date() {
		$configured_start = self::configured_plan_start_date();
		if ($configured_start) {
			return $configured_start;
		}

		return self::first_business_day_of_current_month();
	}

	private static function configured_plan_start_date() {
		$raw = trim((string) get_option(self::START_DATE_OPTION, ''));
		return self::normalize_option_date($raw);
	}

	private static function normalize_option_date($date_string) {
		$date_string = trim((string) $date_string);
		if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date_string, $matches)) {
			return '';
		}

		$year = (int) $matches[1];
		$month = (int) $matches[2];
		$day = (int) $matches[3];

		if (! checkdate($month, $day, $year)) {
			return '';
		}

		return sprintf('%04d-%02d-%02d', $year, $month, $day);
	}

	private static function first_business_day_of_current_month() {
		$current_ts = current_time('timestamp');
		$year = (int) wp_date('Y', $current_ts);
		$month = (int) wp_date('n', $current_ts);

		for ($day = 1; $day <= 7; $day++) {
			if (! checkdate($month, $day, $year)) {
				break;
			}
			$loop_ts = mktime(12, 0, 0, $month, $day, $year);
			if ((int) wp_date('N', $loop_ts) <= 5) {
				return wp_date('Y-m-d', $loop_ts);
			}
		}

		return wp_date('Y-m-01', $current_ts);
	}

	private static function human_date_label($date_string) {
		$normalized = self::normalize_option_date($date_string);
		if (! $normalized) {
			return '-';
		}

		return wp_date('j M Y', strtotime($normalized . ' 12:00:00'));
	}

	private static function plan_task_auto_key($task_key) {
		return 'daily_plan_' . self::current_period_key() . '_' . sanitize_key((string) $task_key);
	}

	private static function existing_plan_task_id($auto_key) {
		$tasks = get_posts(
			array(
				'post_type' => 'crm_task',
				'post_status' => array('publish', 'private'),
				'numberposts' => 1,
				'orderby' => 'date',
				'order' => 'DESC',
				'fields' => 'ids',
				'meta_query' => array(
					array(
						'key' => '_crm_auto_key',
						'value' => (string) $auto_key,
						'compare' => '=',
					),
				),
			)
		);

		return ! empty($tasks[0]) ? (int) $tasks[0] : 0;
	}

	private static function existing_plan_tasks() {
		$task_ids = get_posts(
			array(
				'post_type' => 'crm_task',
				'post_status' => array('publish', 'private'),
				'numberposts' => -1,
				'orderby' => 'date',
				'order' => 'DESC',
				'fields' => 'ids',
				'meta_query' => array(
					array(
						'key' => '_crm_daily_plan_period',
						'value' => self::current_period_key(),
						'compare' => '=',
					),
				),
			)
		);

		$map = array();
		foreach ($task_ids as $task_id) {
			$task_id = (int) $task_id;
			$task_key = sanitize_key((string) get_post_meta($task_id, '_crm_daily_plan_task_key', true));
			if (! $task_key) {
				continue;
			}
			$map[$task_key] = self::serialize_plan_task_record($task_id, $task_key);
		}

		return $map;
	}

	private static function serialize_plan_task_record($task_id, $task_key = '') {
		$task_id = (int) $task_id;
		if (! $task_id || 'crm_task' !== get_post_type($task_id)) {
			return array();
		}

		$status = sanitize_key((string) get_post_meta($task_id, '_crm_task_status', true));
		$status_labels = array(
			'pending' => 'Pendiente',
			'in_progress' => 'En proceso',
			'done' => 'Completada',
		);

		return array(
			'id' => $task_id,
			'taskKey' => $task_key ?: sanitize_key((string) get_post_meta($task_id, '_crm_daily_plan_task_key', true)),
			'title' => (string) get_the_title($task_id),
			'due' => (string) get_post_meta($task_id, '_crm_task_due', true),
			'startTime' => (string) get_post_meta($task_id, '_crm_task_start_time', true),
			'status' => $status ?: 'pending',
			'statusLabel' => $status_labels[$status] ?? 'Pendiente',
			'editUrl' => get_edit_post_link($task_id, ''),
			'panelUrl' => admin_url('admin.php?page=twob-crm-visual-tasks') . '#twob-visible-actions',
		);
	}

	private static function sanitize_plan_task_category($category) {
		$category = sanitize_key((string) $category);
		return in_array($category, array('commercial', 'operational', 'marketing'), true) ? $category : 'operational';
	}

	private static function sanitize_plan_task_priority($priority) {
		$priority = sanitize_key((string) $priority);
		return in_array($priority, array('high', 'medium', 'low'), true) ? $priority : 'medium';
	}

	private static function sanitize_plan_task_type($task_type, $category) {
		$category = self::sanitize_plan_task_category($category);
		$task_type = sanitize_key((string) $task_type);
		$map = array(
			'commercial' => array('contact_lead', 'followup', 'proposal', 'meeting', 'other'),
			'operational' => array('hosting_review', 'collection', 'support', 'other'),
			'marketing' => array('linkedin_post', 'content_creation', 'web_optimization', 'other'),
		);
		$allowed = $map[$category] ?? $map['operational'];
		if (in_array($task_type, $allowed, true)) {
			return $task_type;
		}
		return 'commercial' === $category ? 'followup' : 'other';
	}

	private static function sanitize_plan_task_assignee($assignee) {
		$assignee = sanitize_key((string) $assignee);
		return in_array($assignee, array('dani', 'renata'), true) ? $assignee : 'dani';
	}

	private static function sanitize_plan_task_time($time) {
		$time = trim((string) $time);
		return preg_match('/^\d{2}:\d{2}$/', $time) ? $time : '';
	}

	private static function due_date_for_plan_day($day_num) {
		$day_num = max(1, min(20, (int) $day_num));
		$today = current_time('Y-m-d');
		$business_day_date = self::business_day_date_for_number($day_num);
		if (! $business_day_date) {
			return $today;
		}
		return $business_day_date < $today ? $today : $business_day_date;
	}

	private static function business_day_date_for_number($day_num) {
		$day_num = max(1, min(20, (int) $day_num));
		return self::nth_business_day_from_start(self::plan_start_date(), $day_num);
	}

	private static function nth_business_day_from_start($start_date, $day_num) {
		$start_date = self::normalize_option_date($start_date);
		$day_num = max(1, min(20, (int) $day_num));
		if (! $start_date) {
			return '';
		}

		$start_ts = strtotime($start_date . ' 12:00:00');
		$count = 0;
		$last_match = '';

		for ($offset = 0; $offset <= 60; $offset++) {
			$loop_ts = strtotime('+' . $offset . ' days', $start_ts);
			if ((int) wp_date('N', $loop_ts) > 5) {
				continue;
			}
			$count++;
			$last_match = wp_date('Y-m-d', $loop_ts);
			if ($count >= $day_num) {
				return $last_match;
			}
		}

		return $last_match;
	}

	private static function progress_option_name() {
		return self::PROGRESS_OPTION_PREFIX . self::current_period_key();
	}

	private static function get_progress() {
		$raw = get_option(self::progress_option_name(), array());
		$progress = array();

		if (! is_array($raw)) {
			return $progress;
		}

		foreach ($raw as $task_key => $value) {
			$task_key = sanitize_key((string) $task_key);
			if ($task_key && $value) {
				$progress[$task_key] = 1;
			}
		}

		return $progress;
	}

	private static function current_business_day_number() {
		$start_date = self::plan_start_date();
		$today = current_time('Y-m-d');
		if (! $start_date) {
			return 1;
		}
		if ($today < $start_date) {
			return 1;
		}

		return max(1, min(20, self::business_days_between_dates($start_date, $today)));
	}

	private static function business_days_between_dates($start_date, $end_date) {
		$start_date = self::normalize_option_date($start_date);
		$end_date = self::normalize_option_date($end_date);
		if (! $start_date || ! $end_date || $end_date < $start_date) {
			return 0;
		}

		$start_ts = strtotime($start_date . ' 12:00:00');
		$end_ts = strtotime($end_date . ' 12:00:00');
		$count = 0;

		for ($loop_ts = $start_ts; $loop_ts <= $end_ts; $loop_ts = strtotime('+1 day', $loop_ts)) {
			if ((int) wp_date('N', $loop_ts) <= 5) {
				$count++;
			}
		}

		return $count;
	}

	private static function count_created_today($post_type) {
		$today = current_time('Y-m-d');
		$query = new WP_Query(
			array(
				'post_type' => $post_type,
				'post_status' => array('publish', 'private'),
				'posts_per_page' => 1,
				'fields' => 'ids',
				'no_found_rows' => false,
				'date_query' => array(
					array(
						'after' => $today . ' 00:00:00',
						'before' => $today . ' 23:59:59',
						'inclusive' => true,
						'column' => 'post_date',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	private static function count_visible_tasks_today() {
		$today = current_time('Y-m-d');
		$query = new WP_Query(
			array(
				'post_type' => 'crm_task',
				'post_status' => array('publish', 'private'),
				'posts_per_page' => 1,
				'fields' => 'ids',
				'no_found_rows' => false,
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key' => '_crm_task_status',
						'value' => array('pending', 'in_progress'),
						'compare' => 'IN',
					),
					array(
						'key' => '_crm_task_due',
						'value' => $today,
						'compare' => '<=',
						'type' => 'DATE',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	private static function count_meetings_this_week() {
		$current_ts = current_time('timestamp');
		$week_start_ts = strtotime('monday this week', $current_ts);
		$week_end_ts = strtotime('sunday this week', $current_ts);
		$week_start = wp_date('Y-m-d', $week_start_ts);
		$week_end = wp_date('Y-m-d', $week_end_ts);

		$query = new WP_Query(
			array(
				'post_type' => 'crm_task',
				'post_status' => array('publish', 'private'),
				'posts_per_page' => 1,
				'fields' => 'ids',
				'no_found_rows' => false,
				'meta_query' => array(
					'relation' => 'AND',
					array(
						'key' => '_crm_task_type',
						'value' => 'meeting',
					),
					array(
						'key' => '_crm_task_status',
						'value' => array('pending', 'in_progress'),
						'compare' => 'IN',
					),
					array(
						'key' => '_crm_task_due',
						'value' => array($week_start, $week_end),
						'compare' => 'BETWEEN',
						'type' => 'DATE',
					),
				),
			)
		);

		return (int) $query->found_posts;
	}

	private static function plan_styles() {
		return <<<'CSS'
.twob-daily-plan-page{
  --a:#E8A020;--al:#fff8ec;--am:#fde8b8;
  --dk:#0f0f0f;--dk2:#1a1a1a;--dk3:#252525;
  --tx:#1a1a1a;--mu:#666;--li:#999;
  --bd:#e8e8e4;--bg:#f5f4f0;--wh:#fff;
  --gn:#1D9E75;--gl:#edfaf4;--gm:#b8e8d3;
  --bl:#185FA5;--bll:#eef4fc;--blm:#bed6ef;
  --rd:#c0392b;--rl:#fdf0ee;
  --pu:#6c4fc5;--pl:#f3f0ff;
  --or:#c0560a;--ol:#fef4ee;
  font-family:'Figtree',sans-serif;
  color:var(--tx);
}
.twob-daily-plan-page *{box-sizing:border-box}
.twob-daily-plan-page .crm-shell{margin:14px 0 18px}
.twob-daily-plan-page .crm-summary{
  display:flex;justify-content:space-between;gap:18px;align-items:flex-start;
  background:linear-gradient(135deg,#111,#232323);
  border:1px solid #2b2b2b;border-radius:18px;padding:22px 24px;color:#fff;
}
.twob-daily-plan-page .crm-summary-copy h1{
  margin:6px 0 8px;font-family:'Syne',sans-serif;font-size:30px;line-height:1.05;font-weight:800;color:#fff;
}
.twob-daily-plan-page .crm-summary-copy p{margin:0;max-width:720px;color:#a9a9a9;font-size:13px;line-height:1.6}
.twob-daily-plan-page .crm-kicker{
  display:inline-flex;align-items:center;gap:6px;padding:6px 10px;border-radius:999px;
  background:rgba(232,160,32,.12);border:1px solid rgba(232,160,32,.24);color:var(--a);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;
}
.twob-daily-plan-page .crm-summary-meta{display:grid;grid-template-columns:1fr;gap:10px;min-width:180px}
.twob-daily-plan-page .crm-meta-pill{
  background:rgba(255,255,255,.05);border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:12px 14px;
}
.twob-daily-plan-page .crm-meta-pill strong{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:#8c8c8c;margin-bottom:4px}
.twob-daily-plan-page .crm-meta-pill span{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:#fff}
.twob-daily-plan-page .crm-live-grid{
  display:grid;grid-template-columns:repeat(5,1fr);gap:12px;margin-top:14px;
}
.twob-daily-plan-page .crm-live-card{
  background:var(--wh);border:1px solid var(--bd);border-radius:14px;padding:16px 18px;
}
.twob-daily-plan-page .crm-live-card span{
  display:block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--li);margin-bottom:8px;
}
.twob-daily-plan-page .crm-live-card strong{
  display:block;font-family:'Syne',sans-serif;font-size:30px;line-height:1;font-weight:800;color:var(--dk);margin-bottom:6px;
}
.twob-daily-plan-page .crm-live-card small{display:block;font-size:12px;color:var(--mu);line-height:1.45}
.twob-daily-plan-page .crm-live-card.is-progress{
  background:linear-gradient(135deg,#fff8ec,#fffef7);border-color:var(--am);
}
.twob-daily-plan-page .crm-live-card.is-progress strong{color:#925800}
.twob-daily-plan-page .crm-shortcuts{
  display:flex;flex-wrap:wrap;gap:10px;margin-top:14px;
}
.twob-daily-plan-page .crm-btn{
  display:inline-flex;align-items:center;justify-content:center;gap:8px;
  min-height:40px;padding:0 14px;border-radius:10px;background:var(--wh);
  border:1px solid var(--bd);color:var(--tx);text-decoration:none;font-size:12px;font-weight:700;
  transition:.16s ease;
}
.twob-daily-plan-page .crm-btn:hover{
  background:var(--dk);border-color:var(--dk);color:#fff;
}
.twob-daily-plan-page .crm-note{
  margin-top:14px;background:#f8f6ef;border:1px solid #ece6d4;border-radius:12px;
  padding:13px 16px;font-size:12px;line-height:1.6;color:var(--mu);
}
.twob-daily-plan-page .topbar{
  background:var(--dk);padding:0 24px;display:flex;align-items:center;justify-content:space-between;
  min-height:58px;position:sticky;top:32px;z-index:50;border:1px solid #2a2a2a;border-radius:16px;margin-top:18px;
}
.twob-daily-plan-page .topbar-brand{font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:#fff;letter-spacing:-.02em}
.twob-daily-plan-page .topbar-brand span{color:var(--a)}
.twob-daily-plan-page .topbar-right{display:flex;align-items:center;gap:12px;flex-wrap:wrap;padding:12px 0}
.twob-daily-plan-page .who-toggle{display:flex;background:#222;border-radius:8px;overflow:hidden;border:1px solid #333}
.twob-daily-plan-page .who-btn{padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;color:#888;border:0;background:transparent;font-family:inherit;transition:.15s}
.twob-daily-plan-page .who-btn.active{background:var(--a);color:var(--dk)}
.twob-daily-plan-page .week-sel{display:flex;gap:4px}
.twob-daily-plan-page .wsb{padding:6px 12px;border-radius:6px;font-size:12px;font-weight:700;cursor:pointer;color:#888;border:1px solid #333;background:transparent;font-family:inherit;transition:.15s}
.twob-daily-plan-page .wsb.active{background:#fff;color:var(--dk);border-color:#fff}
.twob-daily-plan-page .timer-btn{background:var(--a);color:var(--dk);border:0;border-radius:8px;padding:7px 14px;font-size:12px;font-weight:700;cursor:pointer;font-family:inherit}
.twob-daily-plan-page .timer-overlay{position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:500;display:none;align-items:center;justify-content:center}
.twob-daily-plan-page .timer-overlay.open{display:flex}
.twob-daily-plan-page .timer-box{background:var(--dk);border-radius:20px;padding:36px;text-align:center;min-width:280px;border:1px solid #333}
.twob-daily-plan-page .timer-box h3{font-family:'Syne',sans-serif;font-size:14px;font-weight:700;color:#888;text-transform:uppercase;letter-spacing:.1em;margin-bottom:16px}
.twob-daily-plan-page .timer-display{font-family:'Syne',sans-serif;font-size:64px;font-weight:800;color:#fff;line-height:1;margin-bottom:8px}
.twob-daily-plan-page .timer-phase{font-size:12px;color:#666;margin-bottom:24px}
.twob-daily-plan-page .timer-controls{display:flex;gap:10px;justify-content:center}
.twob-daily-plan-page .tcb{border-radius:8px;padding:10px 18px;font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;border:0;transition:.15s}
.twob-daily-plan-page .tcb-go{background:var(--gn);color:#fff}
.twob-daily-plan-page .tcb-stop{background:#333;color:#aaa}
.twob-daily-plan-page .tcb-close{background:#222;color:#666}
.twob-daily-plan-page .timer-tip{font-size:11px;color:#555;margin-top:16px;line-height:1.5}
.twob-daily-plan-page .main{max-width:1180px;margin:0 auto;padding:24px 0 80px}
.twob-daily-plan-page .intro{background:var(--dk);border-radius:16px;padding:22px 26px;margin-bottom:24px;display:flex;align-items:center;justify-content:space-between;gap:20px}
.twob-daily-plan-page .intro-left h2{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:#fff;line-height:1;margin:0}
.twob-daily-plan-page .intro-left p{font-size:13px;color:#888;margin:6px 0 0;line-height:1.5;max-width:560px}
.twob-daily-plan-page .intro-metrics{display:flex;gap:12px;flex-wrap:wrap}
.twob-daily-plan-page .im{background:#1d1d1d;border:1px solid #2e2e2e;border-radius:10px;padding:12px 16px;text-align:center;min-width:96px}
.twob-daily-plan-page .im-n{font-family:'Syne',sans-serif;font-size:26px;font-weight:800;color:var(--a)}
.twob-daily-plan-page .im-l{font-size:10px;color:#666;margin-top:2px}
.twob-daily-plan-page .adhd-bar{background:linear-gradient(135deg,#1a1207,#2a1e08);border:1px solid #3d2e0a;border-radius:12px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:flex-start;gap:12px}
.twob-daily-plan-page .adhd-bar .icon{
  width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:800;letter-spacing:.08em;text-transform:uppercase;background:rgba(232,160,32,.14);color:var(--a);flex-shrink:0;
}
.twob-daily-plan-page .adhd-bar h4{font-size:13px;font-weight:700;color:var(--a);margin:0 0 4px}
.twob-daily-plan-page .adhd-bar p{font-size:12px;color:#999;line-height:1.55;margin:0}
.twob-daily-plan-page .day-nav{display:flex;gap:4px;flex-wrap:wrap;margin-bottom:20px}
.twob-daily-plan-page .dnb{
  width:48px;height:48px;border-radius:10px;border:1px solid var(--bd);background:var(--wh);color:var(--mu);
  font-size:13px;font-weight:700;cursor:pointer;font-family:inherit;display:flex;flex-direction:column;align-items:center;justify-content:center;transition:.15s;line-height:1.1;
}
.twob-daily-plan-page .dnb .dw{font-size:8px;font-weight:600;letter-spacing:.06em;text-transform:uppercase}
.twob-daily-plan-page .dnb:hover{border-color:var(--a)}
.twob-daily-plan-page .dnb.active{background:var(--dk);color:#fff;border-color:var(--dk)}
.twob-daily-plan-page .dnb.done{background:var(--gl);border-color:var(--gm);color:var(--gn)}
.twob-daily-plan-page .week-summary{background:var(--wh);border:1px solid var(--bd);border-radius:14px;padding:18px 22px;margin-bottom:20px}
.twob-daily-plan-page .ws-title{font-family:'Syne',sans-serif;font-size:20px;font-weight:800;margin:0 0 6px}
.twob-daily-plan-page .ws-desc{font-size:13px;color:var(--mu);line-height:1.6;margin:0 0 16px}
.twob-daily-plan-page .ws-goals{display:grid;grid-template-columns:repeat(4,1fr);gap:10px}
.twob-daily-plan-page .wg{background:var(--bg);border-radius:8px;padding:12px;text-align:center}
.twob-daily-plan-page .wg-n{font-family:'Syne',sans-serif;font-size:28px;font-weight:800;color:var(--gn)}
.twob-daily-plan-page .wg-l{font-size:11px;color:var(--mu);margin-top:2px}
.twob-daily-plan-page .people-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.twob-daily-plan-page .people-grid.single-dani .col-renata{display:none}
.twob-daily-plan-page .people-grid.single-dani{grid-template-columns:1fr}
.twob-daily-plan-page .people-grid.single-renata .col-dani{display:none}
.twob-daily-plan-page .people-grid.single-renata{grid-template-columns:1fr}
.twob-daily-plan-page .person-header{display:flex;align-items:center;gap:10px;padding:14px 18px;border-radius:12px 12px 0 0;margin-bottom:0}
.twob-daily-plan-page .ph-dani{background:var(--bl)}
.twob-daily-plan-page .ph-renata{background:var(--dk2);border-bottom:3px solid var(--a)}
.twob-daily-plan-page .person-avatar{width:36px;height:36px;border-radius:50%;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}
.twob-daily-plan-page .person-name{font-family:'Syne',sans-serif;font-size:16px;font-weight:800;color:#fff}
.twob-daily-plan-page .person-role{font-size:11px;color:rgba(255,255,255,.6);margin-top:1px}
.twob-daily-plan-page .ph-energy{margin-left:auto;font-size:11px;color:rgba(255,255,255,.6)}
.twob-daily-plan-page .task-list{display:flex;flex-direction:column;gap:10px}
.twob-daily-plan-page .tc{background:var(--wh);border:1px solid var(--bd);border-radius:0;overflow:hidden}
.twob-daily-plan-page .tc:last-child{border-radius:0 0 12px 12px;border-bottom:1px solid var(--bd)}
.twob-daily-plan-page .tc-head{padding:14px 16px;cursor:pointer;display:flex;align-items:center;gap:12px;transition:background .15s}
.twob-daily-plan-page .tc-head:hover{background:#fafaf8}
.twob-daily-plan-page .tc-num{width:28px;height:28px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:14px;font-weight:800;flex-shrink:0}
.twob-daily-plan-page .num-dani{background:var(--bll);color:var(--bl)}
.twob-daily-plan-page .num-renata{background:var(--al);color:#925800}
.twob-daily-plan-page .tc-info{flex:1;min-width:0}
.twob-daily-plan-page .tc-title{font-size:14px;font-weight:700;color:var(--tx)}
.twob-daily-plan-page .tc-time{font-size:11px;color:var(--mu);margin-top:2px;display:flex;align-items:center;gap:5px}
.twob-daily-plan-page .tc-badges{display:flex;align-items:center;gap:6px;margin-left:auto;flex-shrink:0;flex-wrap:wrap}
.twob-daily-plan-page .tb{font-size:10px;font-weight:700;padding:3px 8px;border-radius:4px;letter-spacing:.04em;text-transform:uppercase}
.twob-daily-plan-page .tb-li{background:var(--bll);color:var(--bl)}
.twob-daily-plan-page .tb-wa{background:var(--gl);color:var(--gn)}
.twob-daily-plan-page .tb-rc{background:var(--al);color:#925800}
.twob-daily-plan-page .tb-ag{background:var(--pl);color:var(--pu)}
.twob-daily-plan-page .tb-em{background:var(--ol);color:var(--or)}
.twob-daily-plan-page .tb-web{background:#f0f0ee;color:#555}
.twob-daily-plan-page .tc-check{
  width:24px;height:24px;border-radius:50%;border:2px solid var(--bd);cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;transition:.15s;background:var(--wh);font-weight:800;font-size:12px;color:#fff;
}
.twob-daily-plan-page .tc-check.done{background:var(--gn);border-color:var(--gn);color:#fff}
.twob-daily-plan-page .tc-check.is-saving{opacity:.55;pointer-events:none}
.twob-daily-plan-page .tc-arrow{color:var(--li);font-size:12px;flex-shrink:0;transition:transform .2s}
.twob-daily-plan-page .tc-arrow.open{transform:rotate(180deg)}
.twob-daily-plan-page .tc-body{display:none;padding:0 16px 16px;border-top:1px solid var(--bd)}
.twob-daily-plan-page .tc-body.open{display:block}
.twob-daily-plan-page .step-label{font-size:10px;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--li);margin:14px 0 8px;display:flex;align-items:center;gap:8px}
.twob-daily-plan-page .step-label::after{content:'';flex:1;height:1px;background:var(--bd)}
.twob-daily-plan-page .steps{display:flex;flex-direction:column;gap:6px}
.twob-daily-plan-page .step{display:flex;align-items:flex-start;gap:10px;padding:8px 12px;background:var(--bg);border-radius:8px;font-size:12px;line-height:1.5;color:var(--tx)}
.twob-daily-plan-page .step-n{font-family:'Syne',sans-serif;font-size:14px;font-weight:800;color:var(--a);flex-shrink:0;width:20px}
.twob-daily-plan-page .done-box{margin-top:12px;background:var(--gl);border:1px solid var(--gm);border-radius:8px;padding:10px 14px;font-size:12px;color:var(--gn);font-weight:600}
.twob-daily-plan-page .task-actions-panel{margin-top:14px;background:#fbfaf7;border:1px solid #e8e0cf;border-radius:10px;padding:12px 12px 10px}
.twob-daily-plan-page .task-actions-copy{font-size:12px;color:var(--mu);line-height:1.55;margin:0 0 10px}
.twob-daily-plan-page .task-actions-grid{display:flex;flex-wrap:wrap;gap:8px}
.twob-daily-plan-page .task-action-link,
.twob-daily-plan-page .task-action-btn{
  display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:0 12px;border-radius:8px;
  border:1px solid var(--bd);background:var(--wh);font-size:12px;font-weight:700;color:var(--tx);text-decoration:none;
  cursor:pointer;font-family:inherit;transition:.15s ease;
}
.twob-daily-plan-page .task-action-link:hover,
.twob-daily-plan-page .task-action-btn:hover{background:var(--dk);border-color:var(--dk);color:#fff}
.twob-daily-plan-page .task-action-link.is-primary,
.twob-daily-plan-page .task-action-btn.is-primary{background:var(--dk);border-color:var(--dk);color:#fff}
.twob-daily-plan-page .task-action-link.is-primary:hover,
.twob-daily-plan-page .task-action-btn.is-primary:hover{background:#000;border-color:#000}
.twob-daily-plan-page .task-action-link.is-muted{background:#f4f1ea;border-color:#eadfc9;color:#6f6047}
.twob-daily-plan-page .task-action-btn[disabled]{opacity:.65;cursor:wait}
.twob-daily-plan-page .task-status{margin-top:10px;font-size:12px;line-height:1.5;color:var(--mu)}
.twob-daily-plan-page .task-status strong{color:var(--tx)}
.twob-daily-plan-page .task-status.is-ok{color:var(--gn)}
.twob-daily-plan-page .task-status.is-error{color:var(--rd)}
.twob-daily-plan-page .task-status a{color:inherit;font-weight:700;text-decoration:none}
.twob-daily-plan-page .task-status a:hover{text-decoration:underline}
.twob-daily-plan-page .msg-toggle{display:flex;align-items:center;gap:6px;background:var(--wh);border:1px solid var(--bd);border-radius:8px;padding:8px 12px;font-size:12px;font-weight:600;cursor:pointer;color:var(--mu);margin-top:10px;font-family:inherit;width:100%;text-align:left;transition:.15s}
.twob-daily-plan-page .msg-toggle:hover{border-color:var(--a);color:var(--tx)}
.twob-daily-plan-page .msg-box{display:none;margin-top:8px;background:#fafaf8;border:1px solid var(--bd);border-radius:8px;overflow:hidden}
.twob-daily-plan-page .msg-box.open{display:block}
.twob-daily-plan-page .msg-content{padding:12px 14px;font-family:'DM Mono',monospace;font-size:12px;line-height:1.7;color:var(--tx);white-space:pre-wrap}
.twob-daily-plan-page .msg-ph{background:var(--am);color:#925800;padding:1px 4px;border-radius:3px}
.twob-daily-plan-page .msg-foot{padding:8px 14px;border-top:1px solid var(--bd);display:flex;justify-content:space-between;align-items:center;gap:10px}
.twob-daily-plan-page .copy-mini{border:1px solid var(--bd);background:var(--wh);padding:5px 10px;border-radius:6px;font-size:11px;font-weight:600;cursor:pointer;font-family:inherit;transition:.15s}
.twob-daily-plan-page .copy-mini:hover{background:var(--dk);color:#fff;border-color:var(--dk)}
.twob-daily-plan-page .copy-mini.ok{background:var(--gn);color:#fff;border-color:var(--gn)}
.twob-daily-plan-page .mvd{margin-top:14px;background:linear-gradient(135deg,#fff8ec,#fffbf3);border:1px solid var(--am);border-radius:8px;padding:12px 14px}
.twob-daily-plan-page .mvd h4{font-size:12px;font-weight:700;color:#925800;margin:0 0 6px;display:flex;align-items:center;gap:6px}
.twob-daily-plan-page .mvd p{font-size:12px;color:var(--mu);line-height:1.5;margin:0}
.twob-daily-plan-page .sync-bar{background:var(--dk);border:1px solid #2a2a2a;border-radius:12px;padding:14px 18px;margin-top:16px;display:flex;align-items:center;gap:14px}
.twob-daily-plan-page .sync-icon{font-size:20px}
.twob-daily-plan-page .sync-time{font-family:'Syne',sans-serif;font-size:14px;font-weight:800;color:var(--a)}
.twob-daily-plan-page .sync-text{font-size:12px;color:#999;line-height:1.5}
.twob-daily-plan-page .crm-live-ops{margin-top:24px}
.twob-daily-plan-page .ops-head{
  display:flex;align-items:flex-end;justify-content:space-between;gap:16px;margin-bottom:14px;
}
.twob-daily-plan-page .ops-head h3{
  margin:0;font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--dk);
}
.twob-daily-plan-page .ops-head p{
  margin:6px 0 0;font-size:13px;line-height:1.6;color:var(--mu);max-width:760px;
}
.twob-daily-plan-page .ops-pill{
  display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 12px;border-radius:999px;
  background:#fff6df;border:1px solid #eed6a0;color:#8a5a00;font-size:12px;font-weight:700;white-space:nowrap;
}
.twob-daily-plan-page .ops-sections{display:grid;gap:18px}
.twob-daily-plan-page .ops-section{display:grid;gap:12px}
.twob-daily-plan-page .ops-section-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px}
.twob-daily-plan-page .ops-section-head h4{margin:0;font-family:'Syne',sans-serif;font-size:20px;font-weight:800;color:var(--dk)}
.twob-daily-plan-page .ops-section-head p{margin:5px 0 0;font-size:12px;line-height:1.6;color:var(--mu);max-width:760px}
.twob-daily-plan-page .ops-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px}
.twob-daily-plan-page .ops-card{
  background:var(--wh);border:1px solid var(--bd);border-radius:14px;padding:18px;
}
.twob-daily-plan-page .ops-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.twob-daily-plan-page .ops-card-head h4{margin:0;font-size:16px;font-weight:800;color:var(--dk)}
.twob-daily-plan-page .ops-card-head p{margin:5px 0 0;font-size:12px;line-height:1.55;color:var(--mu)}
.twob-daily-plan-page .ops-count{
  min-width:34px;height:34px;padding:0 10px;border-radius:999px;background:var(--bg);
  display:inline-flex;align-items:center;justify-content:center;font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--dk);
}
.twob-daily-plan-page .ops-list{display:flex;flex-direction:column;gap:10px}
.twob-daily-plan-page .ops-item{
  border:1px solid var(--bd);border-radius:12px;padding:12px 13px;background:#fcfbf8;
}
.twob-daily-plan-page .ops-item.is-ready{border-color:#b7e4c5;background:#f7fff9}
.twob-daily-plan-page .ops-item.is-warning{border-color:#f3c784;background:#fffaf2}
.twob-daily-plan-page .ops-item strong{display:block;font-size:14px;color:var(--dk);margin-bottom:4px}
.twob-daily-plan-page .ops-meta{font-size:12px;line-height:1.6;color:var(--mu)}
.twob-daily-plan-page .ops-meta-row{display:flex;flex-wrap:wrap;gap:8px;margin:4px 0 6px}
.twob-daily-plan-page .ops-badge{display:inline-flex;align-items:center;justify-content:center;min-height:26px;padding:0 9px;border-radius:999px;background:#eef2ff;border:1px solid #dbe4f0;color:#334155;font-size:11px;font-weight:700}
.twob-daily-plan-page .ops-badge.is-ready{background:#dcfce7;border-color:#86efac;color:#166534}
.twob-daily-plan-page .ops-badge.is-warning{background:#fff7ed;border-color:#fdba74;color:#9a3412}
.twob-daily-plan-page .ops-finding{margin-top:10px;padding:10px 11px;border-radius:10px;background:#fff;border:1px solid #e5dccb;font-size:12px;line-height:1.6;color:var(--tx);white-space:pre-wrap}
.twob-daily-plan-page .ops-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.twob-daily-plan-page .ops-link,
.twob-daily-plan-page .ops-btn{
  display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:0 11px;border-radius:8px;
  border:1px solid var(--bd);background:var(--wh);color:var(--tx);text-decoration:none;font-size:12px;font-weight:700;
  transition:.15s ease;cursor:pointer;font-family:inherit;
}
.twob-daily-plan-page .ops-link:hover,
.twob-daily-plan-page .ops-btn:hover{background:var(--dk);border-color:var(--dk);color:#fff}
.twob-daily-plan-page .ops-btn.is-primary,
.twob-daily-plan-page .ops-link.is-primary{background:var(--dk);border-color:var(--dk);color:#fff}
.twob-daily-plan-page .ops-btn[disabled]{opacity:.65;cursor:wait}
.twob-daily-plan-page .ops-inline-editor{
  display:block;margin-top:10px;padding-top:10px;border-top:1px dashed #dfd7c7;
}
.twob-daily-plan-page .ops-textarea{
  width:100%;min-height:88px;border:1px solid #d9d0bf;border-radius:10px;padding:10px 12px;
  background:#fff;font:inherit;font-size:12px;line-height:1.55;color:var(--tx);resize:vertical;
}
.twob-daily-plan-page .ops-inline-footer{
  margin-top:8px;display:flex;align-items:center;justify-content:space-between;gap:10px;
}
.twob-daily-plan-page .ops-inline-footer span{font-size:11px;line-height:1.5;color:var(--li)}
.twob-daily-plan-page .ops-inline-status{margin-top:8px;font-size:12px;line-height:1.5;color:var(--mu)}
.twob-daily-plan-page .ops-inline-status.is-error{color:var(--rd)}
.twob-daily-plan-page .ops-empty{
  background:#f8f6ef;border:1px dashed #d8cfbd;border-radius:12px;padding:16px;font-size:12px;line-height:1.65;color:var(--mu);
}
.twob-daily-plan-page .ops-only-renata{
  background:#111;border:1px solid #2a2a2a;border-radius:14px;padding:18px;color:#fff;
}
.twob-daily-plan-page .ops-only-renata h3{margin:0 0 6px;font-family:'Syne',sans-serif;font-size:22px;font-weight:800}
.twob-daily-plan-page .ops-only-renata p{margin:0;color:#989898;font-size:13px;line-height:1.6}
.twob-daily-plan-page .ops-only-renata .ops-actions{margin-top:14px}
@media(max-width:1200px){
  .twob-daily-plan-page .crm-live-grid{grid-template-columns:repeat(3,1fr)}
  .twob-daily-plan-page .ops-grid{grid-template-columns:1fr}
}
@media(max-width:960px){
  .twob-daily-plan-page .crm-summary{flex-direction:column}
  .twob-daily-plan-page .crm-live-grid{grid-template-columns:repeat(2,1fr)}
  .twob-daily-plan-page .people-grid{grid-template-columns:1fr}
  .twob-daily-plan-page .people-grid.single-dani,.twob-daily-plan-page .people-grid.single-renata{grid-template-columns:1fr}
  .twob-daily-plan-page .intro{flex-direction:column;align-items:flex-start}
  .twob-daily-plan-page .ops-head{flex-direction:column;align-items:flex-start}
  .twob-daily-plan-page .ops-section-head{flex-direction:column;align-items:flex-start}
}
@media(max-width:782px){
  .twob-daily-plan-page .topbar{top:46px;padding:0 14px}
  .twob-daily-plan-page .main{padding:20px 0 72px}
}
@media(max-width:640px){
  .twob-daily-plan-page .crm-live-grid{grid-template-columns:1fr}
  .twob-daily-plan-page .ws-goals{grid-template-columns:repeat(2,1fr)}
}
CSS;
	}

	private static function plan_script() {
		return <<<'JS'
(function(){
var config = window.twobDailyPlanConfig || {};
var VIEW_STORAGE = 'twob_crm_daily_plan_view_v1';

// ══ WEEK INFO ══
var weekInfo = [
  {
    w:1,
    title:"Semana 1 — Activar todos los canales",
    desc:"Esta semana arranca el motor. Red cálida primero — es el canal más rápido y el más fácil de trabajar. LinkedIn en paralelo. Objetivo no es vender todavía: es abrir 50+ conversaciones activas.",
    goals:[{n:"50+",l:"Contactos activados"},{n:"15+",l:"Respuestas recibidas"},{n:"3–5",l:"Reuniones agendadas"},{n:"10",l:"Agencias contactadas"}]
  },
  {
    w:2,
    title:"Semana 2 — Cerrar primeros diagnósticos",
    desc:"Las conversaciones de semana 1 maduran. Esta semana se ejecutan reuniones y se envían propuestas. La meta es cerrar 1–2 diagnósticos. Recordar: la oferta ahora tiene precio correcto y abono incluido.",
    goals:[{n:"5–8",l:"Reuniones ejecutadas"},{n:"2–3",l:"Propuestas enviadas"},{n:"1–2",l:"Diagnósticos cerrados"},{n:"$700K",l:"Caja potencial"}]
  },
  {
    w:3,
    title:"Semana 3 — Entregar y escalar",
    desc:"Se entregan los primeros diagnósticos. La entrega bien ejecutada convierte en activación. No parar la prospección mientras se entrega — son tareas paralelas.",
    goals:[{n:"2+",l:"Diagnósticos entregados"},{n:"1",l:"Activación propuesta"},{n:"$1.4M+",l:"Caja acumulada"},{n:"1–2",l:"Referidos pedidos"}]
  },
  {
    w:4,
    title:"Semana 4 — Convertir y proyectar mes 2",
    desc:"Cerrar lo que está abierto, cobrar lo pendiente y construir el pipeline del mes siguiente. No esperar al día 20 para pensar en mes 2.",
    goals:[{n:"1",l:"Plan mensual cerrado"},{n:"30",l:"Prospectos mes 2 cargados"},{n:"$2M+",l:"Caja bruta mes 1"},{n:"✓",l:"Sitio web listo"}]
  }
];

// ══ DAY DATA ══
var days = [
  // ── SEMANA 1 ──
  {d:1,w:1,name:"Lunes",
   focus:"Arrancar desde cero. No intentar hacerlo todo. Hoy solo activar los tres primeros movimientos.",
   mvd_dani:"Mínimo viable: enviar 10 mensajes WA a red cálida y cargar 20 contactos al CRM.",
   mvd_renata:"Mínimo viable: revisar 5 sitios y entregar hallazgos a Dani antes de las 11:00.",
   sync:"12:00 · 15 min · ¿Hallazgos listos para Dani? ¿Alguna respuesta urgente? ¿Reunión que preparar?",
   dani:[
     {n:1,t:"Red cálida: lista + 15 mensajes WA",time:"09:00 – 10:30",dur:90,badges:["rc"],
      launch:"Abre WhatsApp en el teléfono o escritorio.",
      steps:[
        "Abre una hoja en blanco o el CRM. Escribe los nombres de 50 personas que te conocen: ex colegas, amigos con empresa, familiares con negocio, proveedores, conocidos de universidad.",
        "No filtres — escribe todos los que se te vengan a la mente en 15 min. No importa si parecen poco relevantes.",
        "Abre WhatsApp. Ve a la primera persona de la lista que tenga empresa o trabaje en empresa con sitio web.",
        "Copia el mensaje de Red Cálida y personaliza solo [Nombre]. Envía. No esperes respuesta.",
        "Siguiente persona. Repite. Meta: 15 mensajes enviados en 60 min.",
        "Registra en el CRM: nombre, empresa, fecha, canal = Red cálida, estado = Nuevo."
      ],
      done:"Hecho cuando: 15 mensajes WA enviados y registrados en CRM.",
      msgs:[{label:"Mensaje red cálida — apertura",body:"Hola [Nombre], ¿cómo estás?\n\nEstoy lanzando con Dani un servicio de diagnóstico técnico para empresas con sitio web. Revisamos si el sitio puede estar afectando formularios, campañas, seguridad o continuidad — y lo entregamos en un portal ejecutivo, no en un PDF.\n\n¿Conoces alguna empresa que pueda necesitar esto? O si es para [su empresa], con gusto lo conversamos."}]
     },
     {n:2,t:"LinkedIn: configurar filtros + 10 primeros contactos",time:"11:00 – 12:30",dur:90,badges:["li"],
      launch:"Abre app.linkedin.com/sales en el navegador.",
      steps:[
        "Haz clic en 'Búsqueda de leads' (Lead search) en el menú superior.",
        "Aplica estos filtros exactos: Ubicación = Chile · Cargo = 'Gerente General' OR 'CEO' OR 'Gerente Comercial' · Tamaño empresa = 11–200 empleados · Industria = elige 2 de tu lista (salud privada, inmobiliario o servicios profesionales).",
        "Haz clic en el primer resultado. Abre su perfil.",
        "Abre en nueva pestaña el sitio web de su empresa (está en la sección Experiencia del perfil).",
        "Busca en la lista de hallazgos que te pasó Renata el nombre de esa empresa. Si está: usa ese hallazgo.",
        "Si no está en la lista: mira el sitio 2 min — ¿carga lento? ¿hay error visible? ¿formulario que probar? Anota 1 hallazgo.",
        "Vuelve al perfil de LinkedIn. Haz clic en 'Conectar' → 'Añadir nota'.",
        "Pega el mensaje personalizado con [Nombre] y [hallazgo]. Máximo 300 caracteres.",
        "Envía. Registra en CRM. Siguiente perfil. Repite.",
        "Meta: 10 contactos en 90 min = 9 min por contacto."
      ],
      done:"Hecho cuando: 10 conexiones enviadas con mensaje personalizado y registradas en CRM.",
      msgs:[{label:"Mensaje LinkedIn — con hallazgo",body:"Hola [Nombre], revisé [empresa].cl y noté [hallazgo específico en 1 línea] que puede estar afectando [formularios / campañas / correos].\n\n¿Le envío los detalles? Son 2 min sin compromiso."}]
     },
     {n:3,t:"Lista de 15 agencias + CRM del día",time:"15:00 – 16:00",dur:60,badges:["ag"],
      launch:"Abre LinkedIn (normal, no Sales Navigator) y el CRM.",
      steps:[
        "Busca en LinkedIn: 'agencia marketing digital Chile', 'agencia PR Chile', 'agencia contenido Chile'. Abre perfiles.",
        "Para cada agencia: anota nombre, URL, persona de contacto (preferir dueño o director).",
        "Meta: lista de 15 agencias para contactar desde el día 4.",
        "Mientras haces la lista: actualiza el CRM con todos los contactos de hoy. Estado, fecha, próxima acción."
      ],
      done:"Hecho cuando: 15 agencias en CRM y todos los contactos del día tienen próxima acción asignada.",
      msgs:[]
     }
   ],
   renata:[
     {n:1,t:"Revisar 10 sitios → hallazgos para Dani",time:"08:30 – 10:30",dur:120,badges:["li"],
      launch:"Abre el CRM y pide a Dani la lista de prospectos LinkedIn del día.",
      steps:[
        "Pide a Dani la lista de prospectos que va a contactar hoy (puede ser la noche anterior).",
        "Para cada empresa de la lista, abre su sitio web.",
        "Revisión rápida por sitio — máx 10 min: ¿carga lento? (usa Google PageSpeed en otro tab), ¿formulario funciona? (intenta llenarlo), ¿hay error 404 visible?, ¿tiene certificado SSL activo?, ¿dKIM/SPF configurado? (usa mxtoolbox.com → escribe el dominio).",
        "Escribe el hallazgo en formato comercial: 'EMPRESA: [nombre] · HALLAZGO: [problema] · IMPACTO: [qué le significa al negocio]'.",
        "Envía la lista de hallazgos a Dani antes de las 10:30."
      ],
      done:"Hecho cuando: hallazgos de 8–10 sitios enviados a Dani antes de las 10:30.",
      msgs:[{label:"Formato hallazgo para Dani",body:"EMPRESA: Clínica Norte\nURL: clinica-norte.cl\nHALLAZGO: El formulario de agendamiento no envía confirmación de email\nIMPACTO: Pacientes que intentan agendar online no saben si quedaron registrados — algunos llaman, otros simplemente no vuelven\n\n---\n\nEMPRESA: Inmobiliaria Andes\nURL: inmobiliaria-andes.cl\nHALLAZGO: dKIM no configurado en el dominio\nIMPACTO: Los correos de seguimiento de cotizaciones probablemente caen en spam"}]
     },
     {n:2,t:"Brief técnico de alianzas (1 página)",time:"11:00 – 12:00",dur:60,badges:["ag"],
      launch:"Abre un documento nuevo (Google Docs o Word).",
      steps:[
        "El brief es para agencias de marketing. Debe explicar qué hacemos en lenguaje NO técnico.",
        "Sección 1 — Qué es el diagnóstico: 'Revisión técnica y operativa del sitio web. Se entrega en un portal interactivo — no un informe PDF — con hallazgos priorizados y plan de acción.'",
        "Sección 2 — Para qué cliente sirve: 'Empresas con sitio activo que invierten en marketing, tienen formularios o campañas.'",
        "Sección 3 — Qué recibe: 'Portal ejecutivo + hallazgos priorizados + roadmap + reunión de presentación. En 5-7 días.'",
        "Sección 4 — Precio y abono: '$350.000 + IVA. Si avanzan con activación en 30 días, el diagnóstico se descuenta.'",
        "Sección 5 — Cómo funciona el referido: 'La agencia da nombre + empresa. Nosotros contactamos. Si se cierra: 10% de comisión = $35.000 por diagnóstico.'",
        "Sección 6 — Qué NO somos: 'No hacemos diseño web, redes sociales ni campañas. Solo diagnóstico y gestión técnica.'",
        "Guarda como PDF. Envía a Dani para que lo use en conversaciones con agencias."
      ],
      done:"Hecho cuando: brief de 1 página guardado como PDF y enviado a Dani.",
      msgs:[]
     },
     {n:3,t:"Red cálida técnica: 10 mensajes a tu propia red",time:"15:00 – 16:00",dur:60,badges:["rc"],
      launch:"Abre WhatsApp o LinkedIn.",
      steps:[
        "Tu red son: ex colegas informáticos, proveedores técnicos, compañeros de carrera, contactos de trabajos anteriores.",
        "El mensaje habla desde tu rol técnico — más creíble en tu red que hablar como vendedora.",
        "Meta: 10 mensajes enviados. Registra en CRM."
      ],
      done:"Hecho cuando: 10 mensajes enviados desde tu red técnica y registrados en CRM.",
      msgs:[{label:"Mensaje red cálida — versión Renata",body:"Hola [Nombre], ¿cómo estás?\n\nEstoy ofreciendo diagnóstico técnico ejecutivo para empresas con sitio WordPress. Revisamos el estado técnico y operativo y entregamos un portal con hallazgos priorizados, evidencia y plan de acción — no un informe PDF.\n\nEstoy revisando sitios esta semana. Si tienes algún cliente, empresa conocida o contacto que pueda necesitar esto, avísame.\n\nSi quieres ver cómo se ve el portal de entrega, te mando una demo."}]
     }
   ]
  },

  {d:2,w:1,name:"Martes",
   focus:"Escalar LinkedIn. El martes y jueves son los mejores días para outreach B2B (datos Salesloft 2023).",
   mvd_dani:"Mínimo viable: 15 mensajes LinkedIn enviados con hallazgos reales.",
   mvd_renata:"Mínimo viable: 8 sitios revisados y hallazgos enviados a Dani antes de las 10:30.",
   sync:"12:00 · 15 min · ¿Respuestas calientes ayer? ¿Reunión que preparar? ¿Alianza interesada?",
   dani:[
     {n:1,t:"20 mensajes LinkedIn con hallazgos personalizados",time:"09:00 – 11:00",dur:120,badges:["li"],
      launch:"Abre app.linkedin.com/sales y la lista de hallazgos de Renata.",
      steps:[
        "Revisa la lista de hallazgos de Renata (debe llegar antes de las 09:00).",
        "Abre el primer perfil en Sales Navigator. Lee el hallazgo correspondiente.",
        "Personaliza el mensaje: cambia [Nombre], [empresa] y [hallazgo]. Solo eso.",
        "Conectar → Añadir nota → Pegar mensaje → Enviar. Registrar en CRM.",
        "Tiempo por contacto: 5–6 min. Meta: 20 contactos en 2 horas.",
        "Si alguien ya respondió del día anterior: responder PRIMERO antes de nuevos contactos."
      ],
      done:"20 conexiones enviadas con mensaje personalizado y CRM actualizado.",
      msgs:[{label:"Mensaje LinkedIn día 2 — variante sector salud",body:"Hola [Nombre], revisé el sitio de [clínica/centro] y el formulario de agendamiento [tiene un error / no envía confirmación].\n\nEn centros médicos, cada formulario que falla puede significar entre 3 y 10 consultas perdidas por semana.\n\n¿Le cuento lo que encontré?"},
            {label:"Mensaje LinkedIn día 2 — variante inmobiliario",body:"Hola [Nombre], revisé [empresa].cl y noté que [hallazgo técnico] puede estar cortando el flujo de leads.\n\nPara inmobiliarias con campañas activas, eso se traduce directo en inversión publicitaria desperdiciada.\n\n¿Le comento los detalles?"}]
     },
     {n:2,t:"Seguimiento red cálida día 1 + respuestas LinkedIn",time:"11:30 – 12:00",dur:30,badges:["rc","wa"],
      launch:"Abre WhatsApp y LinkedIn mensajes.",
      steps:[
        "Revisa quién respondió los 15 mensajes WA de ayer.",
        "Si respondió positivamente → agendar reunión. Propón horario concreto: 'Mañana a las 10:00 o el jueves a las 16:00 ¿cuál le queda mejor?'",
        "Si no respondió → no insistir aún. El seguimiento es el día 4.",
        "Revisa mensajes de LinkedIn de ayer → responder a los que mostraron interés.",
        "Registrar todos los cambios de estado en CRM."
      ],
      done:"Todas las respuestas respondidas y estado del CRM actualizado.",
      msgs:[{label:"WA — Respuesta cuando muestran interés",body:"¡Perfecto [Nombre]! Me alegra que le parezca relevante.\n\nTenemos disponibilidad mañana [día] a las [hora] o el [otro día] a las [hora]. ¿Cuál le acomoda para una llamada de 20 minutos?"}]
     },
     {n:3,t:"5 agencias — primer contacto LinkedIn",time:"15:00 – 16:00",dur:60,badges:["ag"],
      launch:"Abre la lista de 15 agencias del día 1 y LinkedIn.",
      steps:[
        "Elige las 5 primeras agencias de la lista.",
        "Busca al director o dueño en LinkedIn.",
        "Conectar → Añadir nota con el mensaje de alianza. Máx 300 caracteres.",
        "Registrar en la hoja de Alianzas del CRM."
      ],
      done:"5 solicitudes de alianza enviadas.",
      msgs:[{label:"Mensaje alianza — LinkedIn (max 300 car.)",body:"Hola [Nombre], vi el trabajo de [agencia]. Tengo una propuesta: hacemos diagnóstico técnico web y cuando refieren un cliente que cierra, les pasamos el 10%. Sin exclusividad. ¿Tiene 15 min para conversarlo?"}]
     }
   ],
   renata:[
     {n:1,t:"10 sitios para Dani + 8 para mañana",time:"08:30 – 10:30",dur:120,badges:["li"],
      launch:"Lista de prospectos de Dani + abrir sitios.",
      steps:[
        "Revisa la lista de prospectos que Dani contactará hoy.",
        "Para cada uno: revisión de 10 min usando esta secuencia fija — 1) PageSpeed (mete URL en pagespeed.web.dev) 2) Formulario (intenta enviarlo) 3) MxToolbox (verifica dKIM) 4) Navega el sitio en móvil 30 seg.",
        "Escribe el hallazgo en formato Renata→Dani.",
        "Envía a Dani antes de las 09:00 idealmente, máximo 10:30.",
        "Si sobra tiempo: adelanta 8 sitios para el día de mañana."
      ],
      done:"Hallazgos de 10 sitios enviados a Dani. Si hay tiempo: 8 extras para mañana.",
      msgs:[]
     },
     {n:2,t:"Preparar evidencia si hay reunión esta semana",time:"11:30 – 12:00",dur:30,badges:["li"],
      launch:"Revisar si Dani tiene alguna reunión agendada esta semana.",
      steps:[
        "Pregunta a Dani en el sync si hay reunión confirmada.",
        "Si sí: prepara una carpeta con 3 elementos — (1) hallazgo específico de ESA empresa con captura de pantalla, (2) explicación del impacto en negocio en 3 líneas sin jerga técnica, (3) ejemplo de slide del roadmap de diagnóstico.",
        "Si no hay reunión aún: descansa este bloque o adelanta hallazgos de mañana."
      ],
      done:"Si hay reunión: evidencia preparada. Si no: bloque libre.",
      msgs:[]
     },
     {n:3,t:"Red cálida técnica: 8 mensajes más",time:"15:00 – 15:30",dur:30,badges:["rc"],
      launch:"Abre WhatsApp o LinkedIn.",
      steps:[
        "Continúa con la lista de red técnica del día anterior.",
        "8 mensajes más. Solo texto corto. Registra en CRM."
      ],
      done:"8 mensajes más enviados.",
      msgs:[]
     }
   ]
  },

  {d:3,w:1,name:"Miércoles",
   focus:"Día de contenido + seguimientos. El post de LinkedIn calienta TODOS los contactos que ya recibieron mensaje.",
   mvd_dani:"Mínimo viable: publicar el post LinkedIn y enviar 10 mensajes nuevos.",
   mvd_renata:"Mínimo viable: 8 sitios revisados y hallazgo del post entregado a Dani.",
   sync:"11:30 · 15 min · ¿Respuestas calientes? ¿Contenido para el post listo?",
   dani:[
     {n:1,t:"20 mensajes LinkedIn nuevos",time:"09:00 – 11:00",dur:120,badges:["li"],
      launch:"app.linkedin.com/sales + lista hallazgos Renata.",
      steps:["Mismo proceso del día 2. Usa la lista de hallazgos de Renata.","Si la lista de Renata tiene menos de 20: usar los hallazgos del día anterior en sectores distintos.","Meta: 20 contactos en 2 horas."],
      done:"20 mensajes enviados y CRM actualizado.",msgs:[]
     },
     {n:2,t:"Publicar post LinkedIn (hallazgo anónimo)",time:"11:30 – 12:00",dur:30,badges:["li"],
      launch:"Abre LinkedIn personal → 'Crear publicación'.",
      steps:[
        "Usa el hallazgo que te pasó Renata. No menciones nombre de empresa ni datos identificables.",
        "Estructura del post: línea 1 = el hallazgo (hook), líneas 2-4 = qué significa para el negocio, línea 5 = qué haríamos nosotros, última línea = pregunta o CTA suave.",
        "Agrega entre 3 y 5 hashtags: #diagnósticoweb #sitiowebempresas #consultoriadigital #pymes + 1 del sector.",
        "Publica. No edites después de publicar — LinkedIn penaliza las ediciones."
      ],
      done:"Post publicado. Compartir en el perfil de 2B Consulting también si tienen la página.",
      msgs:[{label:"Estructura del post LinkedIn",body:"🔍 Revisamos el sitio de una empresa de [sector] esta semana.\n\nEncontramos algo que nadie sabía que existía:\n[hallazgo en 1 línea, sin jerga técnica]\n\nEsto significaba que [impacto en negocio en 1-2 líneas].\n\nEl problema no estaba en el diseño. Ni en el contenido. Estaba en la base técnica.\n\n¿Cuándo fue la última vez que alguien revisó técnicamente el sitio web de su empresa?\n\n#diagnósticoweb #sitiowebempresas #consultoriadigitalchile"}]
     },
     {n:3,t:"Seguimiento día 3 — contactos del Día 1",time:"15:00 – 16:00",dur:60,badges:["li","wa"],
      launch:"Abre CRM → filtrar por Fecha inicio = Día 1 · Estado = Conectado sin respuesta.",
      steps:[
        "Abre la lista de contactos del Día 1 que NO respondieron.",
        "Envíales el seguimiento día 3 por LinkedIn o WhatsApp si ya tienes su número.",
        "Máximo 10 seguimientos. No perseguir a los que ya respondieron negativamente."
      ],
      done:"10 seguimientos día 3 enviados.",
      msgs:[{label:"Seguimiento día +3",body:"Hola [Nombre], vuelvo sobre mi mensaje.\n\nEl punto que detecté en [empresa].cl puede parecer menor, pero en sitios con campañas activas suele estar cortando silenciosamente parte del flujo de leads.\n\n¿Tiene 15 minutos esta semana para que le muestre exactamente lo que vi?"}]
     }
   ],
   renata:[
     {n:1,t:"8 sitios para Dani + hallazgo para el post",time:"08:30 – 10:00",dur:90,badges:["li"],
      launch:"Lista de prospectos de Dani.",
      steps:[
        "Revisión de 8 sitios con el proceso fijo de 10 min por sitio.",
        "Adicionalmente: elige el hallazgo MÁS llamativo de la semana para el post de Dani.",
        "Escríbelo en lenguaje de negocio, sin jerga técnica. Que un gerente lo entienda."
      ],
      done:"Hallazgos enviados a Dani + hallazgo del post entregado antes de las 10:00.",msgs:[]
     },
     {n:2,t:"Cambios CRÍTICOS del sitio web",time:"11:00 – 12:00",dur:60,badges:["web"],
      launch:"Abre WordPress → Elementor en la página de Diagnóstico.",
      steps:[
        "CAMBIO 1 — El más urgente (10 min): Busca en la página de Diagnóstico el precio '$690.000'. Cámbialo a '$350.000 + IVA'. Guarda. Verifica en el sitio que cambió.",
        "CAMBIO 2 (20 min): En la home, busca el H1 'Consultoría técnica digital y optimización de ecosistemas digitales empresariales'. Cámbialo a: 'Detectamos si su sitio web está afectando ventas, campañas, seguridad o continuidad operativa.' Guarda.",
        "CAMBIO 3 (20 min): Busca todos los botones que digan 'Solicitar revisión'. Cámbialos a 'Solicitar Diagnóstico Ejecutivo'. Esto puede haber en varias páginas.",
        "Verifica los 3 cambios en el sitio desde modo incógnito (para ver sin caché)."
      ],
      done:"Precio $350K visible, H1 nuevo en home, CTAs unificados. Verificado en incógnito.",msgs:[]
     },
     {n:3,t:"Buscar 5 emails públicos de prospectos",time:"15:00 – 15:30",dur:30,badges:["em"],
      launch:"Lista de prospectos LinkedIn que no respondieron aún.",
      steps:[
        "Para los 5 primeros prospectos que no respondieron LinkedIn: abre su sitio web.",
        "Busca en 'Contacto' o footer el email corporativo.",
        "Si no está visible: prueba formato nombre@empresa.cl o n.apellido@empresa.cl",
        "Guarda el email en el CRM (columna Email directo)."
      ],
      done:"5 emails encontrados y guardados en CRM.",msgs:[]
     }
   ]
  },

  {d:4,w:1,name:"Jueves",
   focus:"Jueves = mejor día para reuniones y propuestas. Si hay alguna caliente: ejecutarla hoy.",
   mvd_dani:"Mínimo viable: 15 mensajes LinkedIn + contactar 5 agencias.",
   mvd_renata:"Mínimo viable: 8 sitios + preparar evidencia para reunión si existe.",
   sync:"12:00 · 15 min · ¿Hay reunión lista para hoy o mañana? ¿Qué agencias respondieron?",
   dani:[
     {n:1,t:"20 mensajes LinkedIn",time:"09:00 – 11:00",dur:120,badges:["li"],
      launch:"app.linkedin.com/sales + hallazgos de Renata.",
      steps:["Mismo proceso. 20 contactos en 2 horas.","Priorizar sectores que respondieron mejor en días anteriores.","Si tienes reunión hoy tarde: prioriza preparación en vez de nuevos contactos."],
      done:"20 mensajes enviados.",msgs:[]
     },
     {n:2,t:"Mover LinkedIn calientes → WhatsApp",time:"11:30 – 12:00",dur:30,badges:["wa"],
      launch:"Abre CRM → filtrar contactos con Estado = Respondió (positivo).",
      steps:[
        "Identifica quiénes respondieron positivamente en LinkedIn.",
        "Si en el intercambio mencionaron un número de teléfono: pasarlos a WhatsApp.",
        "Si no dieron número: pedirlo con el mensaje de transición WA.",
        "En WA: enviar el hallazgo completo + proponer reunión con horario concreto."
      ],
      done:"Todos los calientes de LinkedIn tienen seguimiento en WA o reunión agendada.",
      msgs:[{label:"LinkedIn → WhatsApp — transición",body:"Hola [Nombre], te escribo por acá para ser más directo.\n\nLo que encontré en [empresa].cl es: [hallazgo completo en 2 líneas].\n\nEsto puede estar afectando [impacto]. ¿Tiene 20 minutos mañana o el lunes para mostrárselo en pantalla?"}]
     },
     {n:3,t:"Alianzas: 5 nuevas + seguimiento día 1",time:"15:00 – 16:00",dur:60,badges:["ag"],
      launch:"CRM → Hoja Alianzas.",
      steps:[
        "Seguimiento a las 5 agencias del día 2: ¿alguien respondió? Si sí: proponer llamada de 15 min.",
        "5 nuevas agencias: mismo proceso del día 2.",
        "Si alguna agencia respondió con interés: agendar reunión de onboarding para semana 2."
      ],
      done:"Seguimiento hecho + 5 nuevas agencias contactadas.",
      msgs:[{label:"Alianza — seguimiento (WA o LinkedIn)",body:"Hola [Nombre], vuelvo sobre mi mensaje de [día].\n\n¿Pudo revisar la propuesta de alianza? Si tiene 15 min esta semana, le muestro exactamente cómo funciona el diagnóstico que entregaríamos a sus clientes.\n\nEs una sola llamada — sin compromiso."}]
     }
   ],
   renata:[
     {n:1,t:"10 sitios para Dani",time:"08:30 – 10:30",dur:120,badges:["li"],
      launch:"Lista de Dani.",
      steps:["Proceso fijo: 10 min por sitio.","Enviar hallazgos antes de las 09:30 si es posible — Dani los usa desde las 09:00."],
      done:"10 hallazgos enviados a Dani.",msgs:[]
     },
     {n:2,t:"Evidencia para reunión (si existe)",time:"11:30 – 12:00",dur:30,badges:["li"],
      launch:"Revisar si hay reunión agendada hoy o mañana.",
      steps:[
        "Si hay reunión: prepara captura de pantalla del hallazgo, traducción a impacto negocio, y ruta de 3 prioridades.",
        "Prepara también el demo del portal (diagnóstico anonimizado) listo para compartir pantalla.",
        "Si no hay reunión: aprovecha para adelantar hallazgos de mañana."
      ],
      done:"Evidencia lista para reunión O hallazgos adelantados.",msgs:[]
     },
     {n:3,t:"5 emails públicos de prospectos + respuesta técnica si llega",time:"15:00 – 15:30",dur:30,badges:["em"],
      launch:"CRM + sitios de prospectos.",
      steps:[
        "Busca 5 emails más de prospectos (mismo método del día 3).",
        "Si llegó alguna pregunta técnica de un prospecto: responderla con un mensaje corto en lenguaje de negocio."
      ],
      done:"5 emails nuevos en CRM. Respuestas técnicas enviadas.",msgs:[]
     }
   ]
  },

  {d:5,w:1,name:"Viernes",
   focus:"Día de orden. No prospectar nuevo. Revisar, medir, preparar semana 2.",
   mvd_dani:"Mínimo viable: actualizar CRM y contar las 3 métricas clave de la semana.",
   mvd_renata:"Mínimo viable: preparar la lista de 15 sitios para la semana 2.",
   sync:"15:30 · 20 min · Revisión semanal completa. ¿Qué funcionó? ¿Qué no? ¿Meta semana 2?",
   dani:[
     {n:1,t:"10 emails directos a no-respuestas LinkedIn",time:"09:00 – 10:00",dur:60,badges:["em"],
      launch:"CRM → prospectos LinkedIn con Estado = Conectado sin respuesta (más de 3 días).",
      steps:[
        "Identifica los prospectos que no respondieron al mensaje LinkedIn.",
        "Si en el CRM Renata encontró su email: envíale el email directo.",
        "Personaliza con su nombre, empresa y hallazgo. Asunto: 'Revisé [empresa].cl — encontré algo que le puede interesar'.",
        "10 emails en 1 hora = 6 min por email."
      ],
      done:"10 emails enviados.",
      msgs:[{label:"Email directo — asunto y cuerpo",body:"Asunto: Revisé [empresa].cl — encontré algo que le puede interesar\n\nEstimado/a [Nombre],\n\nRevisé el sitio de [empresa] y encontré [hallazgo en 1 línea] que puede estar afectando [impacto en negocio].\n\nEn 2B Consulting hacemos diagnósticos técnicos ejecutivos que entregan evidencia clara de qué está fallando y qué debe corregirse. La entrega es un portal ejecutivo — no un PDF — con hallazgos priorizados y plan de acción.\n\nValor: $350.000 + IVA · Entrega en 5–7 días hábiles.\n\n¿Tiene 20 minutos esta semana para mostrarle lo que encontramos?\n\n[Tu nombre]\n2B Consulting · 2bconsulting.cl"}]
     },
     {n:2,t:"CRM completo: estado, temperatura, próxima acción",time:"11:00 – 12:00",dur:60,badges:["li"],
      launch:"Abre el CRM completo.",
      steps:[
        "Para cada fila del CRM: actualiza el Estado (Nuevo / Conectado / Respondió / Reunión / Propuesta / En decisión / Cerrado / Descartado).",
        "Asigna temperatura: 🔥 Caliente (respondió positivo), 🌡 Tibio (conectó pero no respondió), ❄ Frío (no respondió en 7+ días).",
        "Para cada contacto caliente: escribe la próxima acción ESPECÍFICA y la fecha."
      ],
      done:"Todos los contactos tienen estado, temperatura y próxima acción con fecha.",msgs:[]
     },
     {n:3,t:"Métricas semana 1 + objetivo semana 2",time:"15:00 – 15:30",dur:30,badges:["li"],
      launch:"Abre hoja Métricas diarias del CRM.",
      steps:[
        "Cuenta: mensajes enviados por canal, respuestas totales, reuniones agendadas.",
        "¿Qué canal respondió mejor? ¿Qué mensaje generó más respuestas?",
        "Define la meta de semana 2: cuántos diagnósticos cerrar, cuántas reuniones hacer.",
        "Comparte el resumen con Renata en el sync de las 15:30."
      ],
      done:"Métricas registradas en CRM y meta semana 2 definida.",msgs:[]
     }
   ],
   renata:[
     {n:1,t:"Lista de 15 sitios para semana 2 (con hallazgos)",time:"08:30 – 10:30",dur:120,badges:["li"],
      launch:"CRM → prospectos nuevos semana 2.",
      steps:[
        "Pide a Dani la lista de prospectos que contactará en semana 2.",
        "Revisa 15 sitios y prepara hallazgos. Guárdalos en el CRM o en un documento compartido.",
        "Si Dani no tiene la lista lista: revisar sitios de los sectores prioritarios y tenerlos preparados."
      ],
      done:"15 sitios revisados con hallazgos listos para semana 2.",msgs:[]
     },
     {n:2,t:"Revisar qué hallazgos funcionaron + cambio CTAs sitio web",time:"11:00 – 12:00",dur:60,badges:["web"],
      launch:"Lista de hallazgos usados esta semana + WordPress.",
      steps:[
        "¿Qué tipo de hallazgo generó más respuestas? (formulario, dKIM, velocidad, SSL, etc.) Anotarlo.",
        "En WordPress: busca todos los botones 'Solicitar revisión' que queden pendientes y cámbialos. También el botón final de la home y el de la página de Contacto.",
        "Objetivo: todos los CTAs del sitio dicen 'Solicitar Diagnóstico Ejecutivo' excepto Continuidad (que dice 'Evaluar continuidad técnica')."
      ],
      done:"Hallazgos clasificados + CTAs del sitio unificados.",msgs:[]
     },
     {n:3,t:"Métricas técnicas + plan semana 2",time:"15:00 – 15:30",dur:30,badges:["li"],
      launch:"CRM + notas de la semana.",
      steps:[
        "¿Cuántos sitios revisaste? ¿Cuántos hallazgos entregaste? ¿Cuántos diagnósticos hay en pipeline?",
        "Si hay diagnóstico vendido para semana 2: calcular tiempo real que necesitas para entregarlo.",
        "Define tu capacidad real: ¿cuántos diagnósticos puedes ejecutar en paralelo sin bajar calidad?"
      ],
      done:"Métricas anotadas. Capacidad semana 2 definida.",msgs:[]
     }
   ]
  },

  // ── SEMANA 2 ──
  {d:6,w:2,name:"Lunes",
   focus:"Arrancar semana 2 con energía. Las conversaciones de semana 1 están madurando.",
   mvd_dani:"Mínimo viable: confirmar reuniones de la semana + 15 mensajes nuevos LinkedIn.",
   mvd_renata:"Mínimo viable: 8 sitios revisados y evidencia para reuniones de la semana.",
   sync:"12:00 · 15 min · ¿Cuántas reuniones esta semana? ¿Alguna alianza activa?",
   dani:[
     {n:1,t:"20 LinkedIn nuevos + responder cola completa",time:"09:00 – 11:00",dur:120,badges:["li"],
      launch:"app.linkedin.com/sales + mensajes pendientes.",
      steps:["Antes de enviar nuevos: responder TODOS los mensajes pendientes de semana 1.","Luego: 20 nuevos contactos con hallazgos de Renata."],
      done:"Cola respondida + 20 nuevos mensajes.",msgs:[]
     },
     {n:2,t:"Confirmar reuniones de la semana",time:"11:30 – 12:00",dur:30,badges:["li","wa"],
      launch:"CRM → filtrar por Estado = Reunión agendada.",
      steps:[
        "Para cada reunión agendada esta semana: enviar mensaje de confirmación con agenda.",
        "Formato agenda: '20 min · Revisaremos lo que encontramos en su sitio + te muestro cómo se ve el diagnóstico que entregamos. Sin compromiso.'"
      ],
      done:"Todas las reuniones confirmadas con agenda enviada.",
      msgs:[{label:"Confirmación de reunión",body:"Hola [Nombre], confirmando nuestra reunión [día] a las [hora].\n\nDuración: 20 minutos.\nFormato: te muestro en pantalla lo que encontramos en el sitio + cómo se ve el diagnóstico que entregamos.\n\nUsa este link: [Zoom/Meet/Teams] — o me confirmas si prefieres llamada.\n\nNos vemos el [día]."}]
     },
     {n:3,t:"Alianzas: seguimiento + 5 nuevas",time:"15:00 – 16:00",dur:60,badges:["ag"],
      launch:"CRM → Hoja Alianzas.",
      steps:["Seguimiento a todas las agencias que no respondieron semana 1.","5 nuevas agencias de la lista.","Si alguna quiere reunión: agendar para esta semana o la siguiente."],
      done:"Seguimiento hecho + 5 nuevas.",msgs:[]
     }
   ],
   renata:[
     {n:1,t:"10 sitios para Dani",time:"08:30 – 10:30",dur:120,badges:["li"],
      launch:"Lista prospectos Dani.",
      steps:["Proceso fijo 10 min por sitio.","Priorizar sectores que respondieron mejor en semana 1."],
      done:"10 hallazgos enviados antes de las 09:30.",msgs:[]
     },
     {n:2,t:"Preparar evidencia para TODAS las reuniones de la semana",time:"11:00 – 12:00",dur:60,badges:["li"],
      launch:"Lista de reuniones confirmadas.",
      steps:[
        "Para cada empresa con reunión: carpeta con (1) hallazgo + captura, (2) impacto negocio en 3 líneas, (3) propuesta de 3 prioridades del diagnóstico.",
        "Preparar el demo del portal listo para compartir pantalla."
      ],
      done:"Evidencia completa para cada reunión de la semana.",msgs:[]
     },
     {n:3,t:"Red cálida + seguimiento mensajes semana 1",time:"15:00 – 16:00",dur:60,badges:["rc"],
      launch:"CRM → red cálida con Estado = Nuevo (sin seguimiento aún).",
      steps:["Seguimiento a los que no respondieron en semana 1.","8 mensajes nuevos a red que no contactaste aún."],
      done:"Seguimientos y nuevos mensajes enviados.",msgs:[]
     }
   ]
  },

  {d:7,w:2,name:"Martes",
   focus:"Día de reuniones. Mostrar el portal en vivo. Enviar propuesta el mismo día.",
   mvd_dani:"Mínimo viable: ejecutar la reunión y enviar la propuesta esa misma tarde.",
   mvd_renata:"Mínimo viable: asistir a la reunión y presentar el hallazgo + impacto.",
   sync:"Antes de cada reunión · 5 min de alineación rápida.",
   dani:[
     {n:1,t:"15 LinkedIn + respuestas",time:"09:00 – 10:30",dur:90,badges:["li"],
      launch:"app.linkedin.com/sales.",
      steps:["Si hay reunión esta tarde: solo 15 contactos para no llegar agotada.","Priorizar responder mensajes antes que enviar nuevos."],
      done:"15 mensajes + cola respondida.",msgs:[]
     },
     {n:2,t:"REUNIÓN: ejecutar con demo del portal",time:"Según agenda",dur:0,badges:["li"],
      launch:"Abre el link de reunión 5 min antes. Ten el demo listo para compartir pantalla.",
      steps:[
        "Min 0–5: Bienvenida. 'Hoy vamos a revisar lo que encontramos en su sitio y mostrarte cómo se ve el diagnóstico que entregaríamos. Son 20 minutos.'",
        "Min 5–8: Mostrar el hallazgo específico de su empresa. Pantalla compartida. Explicar el impacto en negocio, NO el problema técnico.",
        "Min 8–15: Mostrar el demo del portal anonimizado. 'Así se ve lo que recibirían.' Mostrar hallazgos priorizados, roadmap y próximos pasos.",
        "Min 15–18: Presentar la oferta. Solo el diagnóstico: $350.000 + IVA, 5–7 días, y el abono si avanzan.",
        "Min 18–20: Pregunta de cierre: '¿Esto tiene sentido para lo que están viviendo hoy?'. Silencio. Esperar respuesta.",
        "Si dicen sí: '¿Les parece que coordinamos el inicio esta semana o la próxima?'",
        "Si piden tiempo: '¿Qué información necesitan para poder decidir? Les preparo un resumen de 1 página.'"
      ],
      done:"Reunión ejecutada. Propuesta enviada el mismo día (no al día siguiente).",
      msgs:[{label:"Email propuesta post-reunión (mismo día)",body:"Asunto: Diagnóstico 2B Consulting — [Empresa]\n\nEstimado/a [Nombre],\n\nFue un gusto conversar hoy. Como acordamos, le envío el resumen:\n\nDIAGNÓSTICO EJECUTIVO DIGITAL — $350.000 + IVA\n· Portal ejecutivo web con hallazgos priorizados y evidencia técnica\n· Roadmap de acción en 3 fases\n· Reunión de presentación + 15 días de seguimiento\n· Entrega en 5–7 días hábiles desde el pago\n\nSi deciden avanzar con la Activación Técnica Completa dentro de 30 días, los $350.000 se aplican como abono al total de $900.000.\n\n¿Alguna duda antes de decidir?\n\n[Nombre]\n2B Consulting"}]
     },
     {n:3,t:"Seguimiento WA a propuestas enviadas",time:"16:00 – 16:30",dur:30,badges:["wa"],
      launch:"CRM → propuestas enviadas hace 2+ días.",
      steps:["Para cada propuesta enviada hace 2 días o más: enviar el mensaje de seguimiento corto.","No resumir la propuesta de nuevo. Solo preguntar si hay dudas."],
      done:"Todos los seguimientos día +2 enviados.",
      msgs:[{label:"Seguimiento día +2 (WhatsApp)",body:"Hola [Nombre], ¿pudo revisar la propuesta?\n\nSi hay algo que no quedó claro o quiere ajustar algún punto antes de decidir, con gusto lo conversamos."}]
     }
   ],
   renata:[
     {n:1,t:"8 sitios + preparar presentación de la reunión",time:"08:30 – 10:00",dur:90,badges:["li"],
      launch:"Lista prospectos Dani + material de reunión.",
      steps:["Revisar 5–8 sitios para Dani.","Últimos ajustes a la evidencia de la reunión de hoy."],
      done:"Hallazgos enviados + evidencia lista.",msgs:[]
     },
     {n:2,t:"REUNIÓN: presentar el portal (tú eres la experta técnica)",time:"Según agenda",dur:0,badges:["li"],
      launch:"Demo del portal listo. Pantalla compartida preparada.",
      steps:[
        "Tú presentas la parte técnica. Dani presenta la parte comercial.",
        "Tu foco: mostrar el hallazgo real, explicar el impacto en el negocio (no en la tecnología), mostrar el portal demo.",
        "Cuando muestres el portal: 'Esto es lo que recibirían. No es un PDF — es un portal web que pueden consultar en cualquier momento, con toda la evidencia de lo que encontramos.'",
        "Al terminar tu parte: Dani toma el cierre comercial. No te metas en el precio ni en las condiciones — eso es de Dani."
      ],
      done:"Reunión ejecutada.",msgs:[]
     },
     {n:3,t:"Si no hay reunión: avanzar diagnóstico vendido o más sitios",time:"15:00 – 17:00",dur:120,badges:["li"],
      launch:"Diagnóstico en proceso (si existe) o lista de prospectos.",
      steps:["Si hay diagnóstico pagado: ejecutarlo. Esta es la prioridad máxima.","Si no: revisar 10 sitios adicionales para pipeline."],
      done:"Diagnóstico avanzado O 10 sitios nuevos.",msgs:[]
     }
   ]
  },

  {d:8,w:2,name:"Miércoles",
   focus:"Post + seguimientos clave. Los contactos de semana 1 están en su momento más caliente.",
   mvd_dani:"Mínimo viable: post LinkedIn publicado + seguimientos día 5 para semana 1.",
   mvd_renata:"Mínimo viable: 8 sitios + avanzar diagnóstico si existe.",
   sync:"12:00 · 15 min · ¿Propuestas abiertas? ¿Diagnóstico que entregar?",
   dani:[
     {n:1,t:"20 LinkedIn + responder cola",time:"09:00 – 11:00",dur:120,badges:["li"],
      launch:"app.linkedin.com/sales.",
      steps:["Mismo proceso. Priorizar respuestas antes de nuevos contactos."],
      done:"20 enviados + cola respondida.",msgs:[]
     },
     {n:2,t:"Post LinkedIn #2",time:"11:30 – 12:00",dur:30,badges:["li"],
      launch:"LinkedIn → Crear publicación.",
      steps:["Usa un hallazgo distinto al post de semana 1.","Esta vez puede ser una pregunta directa al sector: '¿Cada cuánto revisan técnicamente su sitio web?'"],
      done:"Post publicado.",msgs:[{label:"Post tipo 2 — pregunta al sector",body:"¿Cada cuánto tiempo revisan técnicamente su sitio web?\n\nNo el diseño. No el contenido.\n\nSino: ¿están llegando los formularios? ¿Los correos van a spam? ¿El sitio carga bien en móvil? ¿Hay plugins desactualizados que pueden colapsar todo?\n\nEsta semana revisamos 5 sitios de empresas distintas.\n\nEn 4 de los 5 encontramos al menos un problema que nadie sabía que existía.\n\nEl que más sorprendió: [hallazgo anónimo específico].\n\n#sitiowebempresas #consultoriadigitalchile #diagnósticoweb"}]
     },
     {n:3,t:"Seguimientos día 5 (semana 1) + día 3 (esta semana)",time:"15:00 – 16:00",dur:60,badges:["li","wa"],
      launch:"CRM → filtrar por fecha de primer contacto.",
      steps:["Contactos del día 1 de semana 1 que no respondieron: enviar seguimiento día 5.","Contactos de lunes de esta semana que no respondieron: enviar seguimiento día 3."],
      done:"Seguimientos enviados.",
      msgs:[{label:"Seguimiento día +5",body:"Hola [Nombre], última vez que lo molesto por este tema.\n\nSi en algún momento necesitan saber con evidencia qué está pasando técnicamente en el sitio de [empresa], aquí estamos.\n\nEl diagnóstico toma 5-7 días y entrega claridad real antes de tomar decisiones técnicas o de inversión."}]
     }
   ],
   renata:[
     {n:1,t:"8 sitios para Dani",time:"08:30 – 10:00",dur:90,badges:["li"],
      launch:"Lista prospectos Dani.",steps:["Proceso fijo."],done:"8 hallazgos enviados.",msgs:[]
     },
     {n:2,t:"Avanzar diagnóstico vendido",time:"11:00 – 12:00",dur:60,badges:["li"],
      launch:"Diagnóstico en proceso.",
      steps:["Si hay diagnóstico vendido: esta es la prioridad. Avanzar la revisión técnica completa del sitio.","Documentar hallazgos con capturas de pantalla e impacto en negocio.","Si no hay diagnóstico aún: revisar 8 sitios adicionales para pipeline."],
      done:"Diagnóstico avanzado o sitios adicionales revisados.",msgs:[]
     },
     {n:3,t:"Sitio web: Continuidad — agregar 3 planes",time:"15:00 – 16:00",dur:60,badges:["web"],
      launch:"WordPress → página Continuidad.",
      steps:[
        "Eliminar el plan único con rango de precio ($380K–$480K).",
        "Agregar 3 bloques de planes: Plan Base $380.000/mes · Plan Profesional $580.000/mes (marcar como recomendado) · Plan Enterprise $890.000/mes.",
        "Cada plan con sus features listadas (ver el catálogo).",
        "Agregar nota al final: 'Contratos trimestrales renovables. Compromiso anual: 10% de descuento con precio bloqueado.'"
      ],
      done:"Página Continuidad con 3 planes y precios fijos visibles.",msgs:[]
     }
   ]
  },

  {d:9,w:2,name:"Jueves",
   focus:"El momento más importante de la semana: recontactar las 3 personas de las reuniones anteriores.",
   mvd_dani:"Mínimo viable: enviar los 3 mensajes de re-contacto y 15 LinkedIn nuevos.",
   mvd_renata:"Mínimo viable: 8 sitios + diagnóstico avanzado.",
   sync:"12:00 · 15 min · ¿Alguna de las 3 personas respondió? ¿Diagnóstico listo para entregar?",
   dani:[
     {n:1,t:"RE-CONTACTAR las 3 personas de reuniones anteriores",time:"09:00 – 09:30",dur:30,badges:["rc","wa"],
      launch:"Abre el historial de las 3 reuniones anteriores. WhatsApp o email.",
      steps:[
        "Estas 3 personas ya te conocen. Ya tuvieron una reunión. La barrera de re-contacto es muy baja.",
        "Mensaje corto y directo: la oferta cambió completamente. El diagnóstico bajó a la mitad. Ahora hay precios claros para todo.",
        "Envía por el canal por donde comunicaron antes (WA, email o LinkedIn).",
        "No esperes respuesta hoy — registra en CRM y sigue."
      ],
      done:"3 mensajes enviados.",
      msgs:[{label:"Re-contacto reuniones anteriores",body:"Hola [Nombre], quería retomar la conversación que tuvimos hace unas semanas.\n\nReestructuramos completamente nuestra oferta. El diagnóstico bajó a $350.000 + IVA (antes era $700.000) y ahora el cliente sabe exactamente qué viene después con precios claros para cada etapa.\n\nSi le parece, con gusto le muestro cómo quedó en 20 minutos. Es una conversación muy distinta a la anterior."}]
     },
     {n:2,t:"20 LinkedIn nuevos",time:"10:00 – 12:00",dur:120,badges:["li"],
      launch:"app.linkedin.com/sales.",
      steps:["Mismo proceso. Con hallazgos de Renata."],
      done:"20 mensajes enviados.",msgs:[]
     },
     {n:3,t:"Seguimiento propuestas enviadas día +5",time:"15:00 – 16:00",dur:60,badges:["wa","em"],
      launch:"CRM → propuestas enviadas hace 5 días.",
      steps:["Enviar seguimiento por WA o email a propuestas que llevan 5 días sin respuesta.","Preguntar concretamente: '¿Qué información falta para poder decidir?'"],
      done:"Seguimientos enviados.",
      msgs:[{label:"Seguimiento propuesta día +5",body:"Hola [Nombre], sigo atenta a si tuvieron la oportunidad de revisar la propuesta.\n\n¿Hay algún punto que no quedó claro o alguna duda sobre el alcance o el proceso? Con gusto lo revisamos antes de decidir."}]
     }
   ],
   renata:[
     {n:1,t:"10 sitios para Dani",time:"08:30 – 10:30",dur:120,badges:["li"],
      launch:"Lista prospectos.",steps:["Proceso fijo."],done:"10 hallazgos enviados.",msgs:[]
     },
     {n:2,t:"Diagnóstico en proceso — avanzar",time:"11:00 – 13:00",dur:120,badges:["li"],
      launch:"Diagnóstico vendido.",
      steps:["Continuar revisión técnica completa.","Si hay dudas sobre un hallazgo: documentar con herramientas (PageSpeed, MxToolbox, DevTools del navegador)."],
      done:"Diagnóstico avanzado al 60–80%.",msgs:[]
     },
     {n:3,t:"Preparar presentación del diagnóstico para entrega",time:"15:00 – 16:00",dur:60,badges:["li"],
      launch:"Portal del diagnóstico.",
      steps:["Organizar los hallazgos en 3 prioridades: Crítico, Alto, Medio.","Para cada hallazgo crítico: 1 captura + 1 descripción del impacto en negocio.","Preparar el roadmap de 3 fases."],
      done:"Diagnóstico listo para ser presentado.",msgs:[]
     }
   ]
  },

  {d:10,w:2,name:"Viernes",
   focus:"Orden, métricas y preparar la semana más importante: la de las entregas.",
   mvd_dani:"Mínimo viable: CRM actualizado + métricas semana 2 contadas.",
   mvd_renata:"Mínimo viable: 15 sitios para semana 3 listos.",
   sync:"15:30 · 20 min · Revisión semana 2. ¿Primer diagnóstico cerrado? ¿Qué aprendimos?",
   dani:[
     {n:1,t:"10 emails directos",time:"09:00 – 10:00",dur:60,badges:["em"],
      launch:"CRM → no-respuestas LinkedIn.",steps:["Mismo proceso semana 1."],done:"10 emails enviados.",msgs:[]
     },
     {n:2,t:"CRM completo + forecast",time:"11:00 – 12:00",dur:60,badges:["li"],
      launch:"CRM.",steps:["Actualizar estado de todos los contactos.","Contar cuántos diagnósticos hay en pipeline (propuesta enviada).","Estimar caja del mes si se cierran los calientes."],
      done:"CRM actualizado + forecast calculado.",msgs:[]
     },
     {n:3,t:"Métricas semana 2",time:"15:00 – 15:30",dur:30,badges:["li"],
      launch:"Hoja Métricas del CRM.",
      steps:["Registrar todos los números de la semana en la hoja de métricas diarias.","¿Cuál canal tuvo mejor tasa de respuesta esta semana?"],
      done:"Métricas semana 2 registradas.",msgs:[]
     }
   ],
   renata:[
     {n:1,t:"15 sitios para semana 3",time:"08:30 – 10:30",dur:120,badges:["li"],
      launch:"Lista prospectos semana 3.",steps:["Revisar 15 sitios con el proceso fijo. Hallazgos guardados para el lunes."],
      done:"15 hallazgos listos para semana 3.",msgs:[]
     },
     {n:2,t:"Diagnóstico: detalles finales",time:"11:00 – 12:00",dur:60,badges:["li"],
      launch:"Diagnóstico en proceso.",steps:["Si el diagnóstico está al 80%+: terminar. Si está al 60%: planificar horas semana 3."],
      done:"Diagnóstico terminado o con plan de cierre semana 3.",msgs:[]
     },
     {n:3,t:"Página Diagnóstico sitio web — copy completo",time:"15:00 – 16:30",dur:90,badges:["web"],
      launch:"WordPress → página Diagnóstico.",
      steps:["Actualizar el título H1 a: 'Diagnóstico Ejecutivo Digital para saber si su sitio web está afectando ventas, campañas o continuidad.'","Actualizar la sección 'Este servicio es para' con el copy correcto del kit comercial.","Eliminar 'Nuestro sitio está bien' como objeción abierta — reemplazar por la versión correcta.","Agregar el bloque de precio con el abono explicado claramente."],
      done:"Página Diagnóstico actualizada con copy correcto.",msgs:[]
     }
   ]
  },

  // ── SEMANA 3 ──
  {d:11,w:3,name:"Lunes",
   focus:"Esta semana se entrega. La entrega bien hecha vende la activación sola.",
   mvd_dani:"Mínimo viable: 15 LinkedIn + confirmar reunión de entrega.",
   mvd_renata:"Mínimo viable: diagnóstico terminado y listo para presentar.",
   sync:"12:00 · 15 min · ¿Diagnóstico listo? ¿Reunión de entrega agendada?",
   dani:[
     {n:1,t:"20 LinkedIn nuevos",time:"09:00 – 11:00",dur:120,badges:["li"],
      launch:"app.linkedin.com/sales.",steps:["Proceso estándar."],done:"20 enviados.",msgs:[]
     },
     {n:2,t:"Confirmar reunión de entrega + asegurar decisor",time:"11:30 – 12:00",dur:30,badges:["li","wa"],
      launch:"CRM → cliente con diagnóstico listo.",
      steps:[
        "Confirmar la reunión de entrega.",
        "CLAVE: asegurarse de que esté el decisor (Gerente General o equivalente) — no solo el técnico.",
        "Mensaje: 'Para aprovechar la reunión al máximo, ¿podría incluir también a [nombre del GG/responsable final]? La presentación está orientada a decisiones de negocio.'"
      ],
      done:"Reunión confirmada con decisor.",msgs:[]
     },
     {n:3,t:"Clasificar pipeline y priorizar calientes",time:"15:00 – 16:00",dur:60,badges:["li"],
      launch:"CRM.",steps:["Revisar todos los contactos calientes. ¿Quiénes están más cerca de una propuesta?","Planificar reuniones semana 3."],
      done:"Pipeline priorizado para semana 3.",msgs:[]
     }
   ],
   renata:[
     {n:1,t:"8 sitios para Dani",time:"08:30 – 10:00",dur:90,badges:["li"],
      launch:"Lista prospectos.",steps:["Proceso fijo."],done:"8 hallazgos enviados.",msgs:[]
     },
     {n:2,t:"Finalizar diagnóstico para entrega",time:"11:00 – 13:00",dur:120,badges:["li"],
      launch:"Diagnóstico en proceso.",
      steps:["Revisar que los hallazgos estén completos: evidencia + impacto negocio + acción recomendada.","Verificar que el roadmap de 3 fases está claro.","Preparar los 3 puntos más críticos para la presentación (no más de 3)."],
      done:"Diagnóstico 100% listo para presentar.",msgs:[]
     },
     {n:3,t:"5 sitios adicionales + responder preguntas técnicas",time:"15:00 – 16:00",dur:60,badges:["li"],
      launch:"CRM + mensajes pendientes.",steps:["5 sitios más para pipeline.","Si hay preguntas técnicas de prospectos: responder."],
      done:"Sitios adicionales + respuestas enviadas.",msgs:[]
     }
   ]
  },

  {d:12,w:3,name:"Martes",
   focus:"La reunión de entrega. No es solo mostrar hallazgos — es cerrar la activación.",
   mvd_dani:"Mínimo viable: ejecutar la entrega y proponer la activación esa tarde.",
   mvd_renata:"Mínimo viable: presentar el portal con claridad y recomendar la activación.",
   sync:"Antes de la reunión · 5 min de alineación.",
   dani:[
     {n:1,t:"15 LinkedIn + preparar reunión de entrega",time:"09:00 – 10:30",dur:90,badges:["li"],
      launch:"app.linkedin.com/sales.",steps:["15 mensajes. No forzar 20 si hay reunión importante."],
      done:"15 enviados.",msgs:[]
     },
     {n:2,t:"ENTREGA del diagnóstico — cierre comercial",time:"Según agenda",dur:0,badges:["li"],
      launch:"Demo del portal + evidencia lista. Link de reunión abierto.",
      steps:[
        "Deja que Renata haga la parte técnica (min 5–15).",
        "Tú tomas el cierre: después de la presentación técnica: 'Basándonos en lo que vieron, el siguiente paso natural es la Activación Técnica. Eso cubre la corrección de todos estos problemas en 2–3 semanas.'",
        "Presenta el precio: '$900.000 + IVA. Y como ya realizaron el diagnóstico, los $350.000 se aplican como abono. El saldo sería $550.000.'",
        "Pregunta directa: '¿Con qué fecha podríamos coordinar el inicio?'",
        "Si piden tiempo: '¿Qué necesitan para decidir? Les preparo un resumen ejecutivo de 1 página.'"
      ],
      done:"Reunión de entrega ejecutada + propuesta de activación enviada esa tarde.",
      msgs:[{label:"Propuesta activación post-entrega",body:"Asunto: Propuesta Activación Técnica — [Empresa]\n\nEstimado/a [Nombre],\n\nGracias por la reunión de hoy. Según los hallazgos del diagnóstico, los pasos recomendados son:\n\nACTIVACIÓN TÉCNICA COMPLETA — $900.000 + IVA\n· Corrección de todos los problemas detectados\n· Implementación de seguridad prioritaria\n· Estabilización del entorno técnico\n· Documentación técnica entregada\n· Plazo: 2-3 semanas\n\nABONO DIAGNÓSTICO: los $350.000 + IVA ya pagados se descuentan del total. Saldo: $550.000 + IVA.\n\n¿Les parece coordinar el inicio esta semana o la próxima?\n\n[Nombre]\n2B Consulting"}]
     },
     {n:3,t:"Pedir referido al cliente post-entrega",time:"16:30 – 17:00",dur:30,badges:["rc"],
      launch:"WhatsApp o email al cliente.",
      steps:["Después de la reunión de entrega: si quedaron satisfechos, pedir referido directamente.","Mensaje corto y natural."],
      done:"Mensaje de referido enviado.",
      msgs:[{label:"Pedir referido post-entrega",body:"Hola [Nombre], fue muy buena la reunión hoy. Me alegra que el diagnóstico haya dado claridad sobre lo que estaba pasando.\n\nSi conoce algún colega o contacto que pueda estar en la misma situación — empresa con sitio activo y dudas sobre si está funcionando bien — agradeceríamos la referencia. Cuidamos mucho a los clientes que llegan recomendados."}]
     }
   ],
   renata:[
     {n:1,t:"Últimos detalles del diagnóstico",time:"08:30 – 09:30",dur:60,badges:["li"],
      launch:"Portal del diagnóstico.",steps:["Revisión final. Verificar que los hallazgos críticos tienen evidencia visual clara."],
      done:"Diagnóstico 100% listo.",msgs:[]
     },
     {n:2,t:"PRESENTAR el portal en la reunión",time:"Según agenda",dur:0,badges:["li"],
      launch:"Pantalla compartida con el portal.",
      steps:[
        "Presenta con claridad. No te pierdas en tecnicismos.",
        "Por cada hallazgo crítico: '1 — qué encontramos, 2 — qué significa para el negocio de ustedes, 3 — qué debería corregirse primero.'",
        "Muestra el roadmap de 3 fases. Di: 'La Fase 1 es lo que la Activación Técnica cubriría.'",
        "Cierra tu parte con: 'En resumen: el sitio tiene [N] puntos que están afectando [ventas/campañas/continuidad]. La buena noticia es que todos son corregibles.'"
      ],
      done:"Presentación ejecutada. Dani toma el cierre.",msgs:[]
     },
     {n:3,t:"Iniciar nuevo diagnóstico si se vendió uno nuevo",time:"15:00 – 17:00",dur:120,badges:["li"],
      launch:"CRM → diagnósticos pagados pendientes.",steps:["Si hay nuevo diagnóstico vendido: empezar la revisión técnica.","Si no: revisar 8 sitios para pipeline."],
      done:"Nuevo diagnóstico iniciado o sitios revisados.",msgs:[]
     }
   ]
  },

  {d:13,w:3,name:"Miércoles",
   focus:"No parar la prospección por estar entregando. Son tareas paralelas.",
   mvd_dani:"Mínimo viable: 15 LinkedIn + post LinkedIn.",
   mvd_renata:"Mínimo viable: 8 sitios + diagnóstico avanzado.",
   sync:"12:00 · 15 min · ¿Activación confirmada? ¿Nuevo diagnóstico en proceso?",
   dani:[
     {n:1,t:"20 LinkedIn",time:"09:00 – 11:00",dur:120,badges:["li"],launch:"app.linkedin.com/sales.",steps:["Proceso estándar."],done:"20 enviados.",msgs:[]},
     {n:2,t:"Post LinkedIn #3 — caso anónimo",time:"11:30 – 12:00",dur:30,badges:["li"],
      launch:"LinkedIn → Crear publicación.",
      steps:["Usa el diagnóstico que acaban de entregar como base (anonimizado, sin datos identificables).","Formato: 'Esto es lo que encontramos esta semana. Y lo que significaba para el negocio.' + captura anonimizada del portal."],
      done:"Post publicado.",
      msgs:[{label:"Post tipo 3 — caso real anonimizado",body:"Esta semana entregamos un diagnóstico técnico a una empresa de [sector].\n\nLo que encontramos:\n[Hallazgo 1 — en lenguaje de negocio]\n[Hallazgo 2 — en lenguaje de negocio]\n\nNadie en la empresa lo sabía. El sitio se veía bien. Funcionaba visualmente.\n\nPero había fallas en la base técnica que podían estar afectando [formularios / campañas / correos].\n\nAsí se ve la entrega 👇\n[captura del portal anonimizada]\n\nNo es un PDF. Es un portal ejecutivo con evidencia real, prioridades y un roadmap de acción.\n\n#diagnósticoweb #consultoriadigitalchile #sitiowebempresas"}]
     },
     {n:3,t:"Seguimiento propuesta de activación",time:"15:00 – 16:00",dur:60,badges:["wa"],
      launch:"CRM → propuesta activación enviada.",
      steps:["Seguimiento día +2 a la propuesta de activación enviada ayer.","Si no responden en 5 días: llamada directa de 5 min."],
      done:"Seguimiento enviado.",msgs:[]
     }
   ],
   renata:[
     {n:1,t:"8 sitios para Dani",time:"08:30 – 10:30",dur:120,badges:["li"],launch:"Lista prospectos.",steps:["Proceso fijo."],done:"8 hallazgos enviados.",msgs:[]},
     {n:2,t:"Diagnóstico #2 en proceso",time:"11:00 – 13:00",dur:120,badges:["li"],launch:"Diagnóstico #2.",steps:["Continuar revisión."],done:"Diagnóstico #2 avanzado.",msgs:[]},
     {n:3,t:"Buscar 5 emails públicos",time:"15:00 – 15:30",dur:30,badges:["em"],launch:"CRM.",steps:["Proceso fijo."],done:"5 emails encontrados.",msgs:[]}
   ]
  },

  {d:14,w:3,name:"Jueves",
   focus:"Seguimientos activos y proponer el plan mensual.",
   mvd_dani:"Mínimo viable: 15 LinkedIn + seguimientos.",
   mvd_renata:"Mínimo viable: 8 sitios + diagnóstico.",
   sync:"12:00 · 15 min",
   dani:[
     {n:1,t:"20 LinkedIn",time:"09:00 – 11:00",dur:120,badges:["li"],launch:"app.linkedin.com/sales.",steps:["Proceso estándar."],done:"20 enviados.",msgs:[]},
     {n:2,t:"Proponer plan mensual al cliente con activación",time:"11:30 – 12:00",dur:30,badges:["wa"],
      launch:"WhatsApp al cliente con activación en proceso.",
      steps:["Si ya están en la activación: mencionar naturalmente el plan mensual.","No vender todavía — solo plantar la idea.","'Una vez terminada la activación, muchos clientes optan por continuar con un plan de gestión mensual para que el entorno no vuelva a degradarse.'"],
      done:"Mensaje enviado.",
      msgs:[{label:"Plantear plan mensual (semana 3)",body:"Hola [Nombre], ¿cómo va todo?\n\nQuería comentarle que una vez terminada la activación, muchos clientes optan por continuar con gestión técnica mensual para mantener el entorno estable y seguir mejorando progresivamente.\n\nTenemos planes desde $380.000 + IVA/mes. Si le interesa revisarlo una vez que terminemos la activación, con gusto se lo presento."}]
     },
     {n:3,t:"Seguimientos día 7 — contactos de semana 2",time:"15:00 – 16:00",dur:60,badges:["li"],launch:"CRM.",steps:["Contactos de semana 2 que no respondieron en 7+ días: último mensaje antes del cierre limpio."],done:"Seguimientos día 7 enviados.",msgs:[]
     }
   ],
   renata:[
     {n:1,t:"10 sitios para Dani",time:"08:30 – 10:30",dur:120,badges:["li"],launch:"Lista.",steps:["Proceso fijo."],done:"10 hallazgos.",msgs:[]},
     {n:2,t:"Diagnóstico #2 — finalizar",time:"11:00 – 13:00",dur:120,badges:["li"],launch:"Diagnóstico.",steps:["Terminar revisión. Organizar hallazgos. Preparar presentación."],done:"Diagnóstico #2 listo.",msgs:[]},
     {n:3,t:"Preparar propuesta plan mensual para cliente activación",time:"15:00 – 16:00",dur:60,badges:["li"],launch:"Propuesta mensual.",steps:["Definir qué plan recomendar según el perfil del cliente (Base, Profesional o Enterprise).","Preparar el alcance mensual específico para esa empresa."],done:"Propuesta mensual lista.",msgs:[]}
   ]
  },

  {d:15,w:3,name:"Viernes",
   focus:"Revisar semana 3. Medir caja real cobrada vs proyectada.",
   mvd_dani:"Mínimo viable: CRM actualizado + métricas.",
   mvd_renata:"Mínimo viable: 15 sitios para semana 4.",
   sync:"15:30 · 20 min · ¿Caja cobrada? ¿Activación cerrada? ¿Plan mensual propuesto?",
   dani:[
     {n:1,t:"10 emails directos",time:"09:00 – 10:00",dur:60,badges:["em"],launch:"CRM.",steps:["Proceso fijo."],done:"10 emails.",msgs:[]},
     {n:2,t:"CRM completo",time:"11:00 – 12:00",dur:60,badges:["li"],launch:"CRM.",steps:["Actualizar todo."],done:"CRM actualizado.",msgs:[]},
     {n:3,t:"Métricas + caja real",time:"15:00 – 15:30",dur:30,badges:["li"],launch:"CRM.",steps:["Contar caja cobrada vs proyectada. ¿Vamos bien o hay que ajustar?"],done:"Métricas y caja registradas.",msgs:[]}
   ],
   renata:[
     {n:1,t:"15 sitios para semana 4",time:"08:30 – 10:30",dur:120,badges:["li"],launch:"Lista.",steps:["Proceso fijo."],done:"15 hallazgos listos.",msgs:[]},
     {n:2,t:"Diagnóstico: estado y entregas pendientes",time:"11:00 – 12:00",dur:60,badges:["li"],launch:"Diagnósticos.",steps:["¿Qué queda por entregar? ¿Hay nueva reunión de entrega que agendar?"],done:"Estado claro de todas las entregas.",msgs:[]},
     {n:3,t:"Sitio: Nosotros + Contacto",time:"15:00 – 16:30",dur:90,badges:["web"],launch:"WordPress.",steps:["Actualizar perfiles del equipo con los textos del kit comercial.","Actualizar página Contacto: título a 'Solicite un Diagnóstico Ejecutivo Digital', agregar campo selector de servicio, cambiar botón a 'Solicitar revisión inicial'."],done:"Nosotros y Contacto actualizados.",msgs:[]}
   ]
  },

  // ── SEMANA 4 ──
  {d:16,w:4,name:"Lunes",focus:"Última semana. Cerrar lo que está abierto y construir mes 2.",
   mvd_dani:"Mínimo viable: 20 LinkedIn + pipeline mes 2 iniciado.",
   mvd_renata:"Mínimo viable: 10 sitios + diagnóstico avanzado.",
   sync:"12:00 · 15 min",
   dani:[
     {n:1,t:"20 LinkedIn",time:"09:00 – 11:00",dur:120,badges:["li"],launch:"app.linkedin.com/sales.",steps:["Proceso."],done:"20 enviados.",msgs:[]},
     {n:2,t:"Confirmar reuniones pendientes",time:"11:30 – 12:00",dur:30,badges:["wa"],launch:"CRM.",steps:["Todas las reuniones sin confirmar: confirmar hoy."],done:"Reuniones confirmadas.",msgs:[]},
     {n:3,t:"Pipeline mes 2: cargar 20 prospectos nuevos",time:"15:00 – 16:00",dur:60,badges:["li"],launch:"Sales Navigator.",steps:["Cargar 20 prospectos nuevos en el CRM con etiqueta 'Mes 2'. No contactar aún.","Renata revisa sus sitios la próxima semana."],done:"20 prospectos mes 2 cargados.",msgs:[]}
   ],
   renata:[
     {n:1,t:"10 sitios para Dani",time:"08:30 – 10:30",dur:120,badges:["li"],launch:"Lista.",steps:["Proceso."],done:"10 hallazgos.",msgs:[]},
     {n:2,t:"Diagnósticos/activaciones en proceso",time:"11:00 – 13:00",dur:120,badges:["li"],launch:"Diagnósticos.",steps:["Avanzar o entregar lo que está en proceso."],done:"Avanzado o entregado.",msgs:[]},
     {n:3,t:"Definir capacidad real mes 2",time:"15:00 – 16:00",dur:60,badges:["li"],launch:"Notas.",steps:["¿Cuántos diagnósticos puedes manejar en paralelo sin bajar calidad?","¿Cuántas horas/semana quedan disponibles para diagnósticos vs prospección?","Comunicar a Dani el cupo máximo de diagnósticos en mes 2."],done:"Capacidad mes 2 definida y comunicada a Dani.",msgs:[]}
   ]
  },

  {d:17,w:4,name:"Martes",focus:"Reuniones y cierres. Proponer plan mensual.",
   mvd_dani:"Mínimo viable: ejecutar reunión + propuesta plan mensual.",
   mvd_renata:"Mínimo viable: 8 sitios + diagnóstico.",
   sync:"Antes de reunión.",
   dani:[
     {n:1,t:"15 LinkedIn + respuestas",time:"09:00 – 10:30",dur:90,badges:["li"],launch:"app.linkedin.com/sales.",steps:["Proceso."],done:"15 enviados.",msgs:[]},
     {n:2,t:"Reunión si existe + propuesta plan mensual",time:"Según agenda",dur:0,badges:["li"],launch:"Demo del portal.",steps:["Ejecutar reunión con mismo proceso.","Proponer plan mensual al cliente con activación completada."],done:"Reunión ejecutada + plan mensual propuesto.",msgs:[]},
     {n:3,t:"Seguimiento indecisos — ¿cerrar o mes 2?",time:"15:00 – 16:00",dur:60,badges:["wa"],launch:"CRM indecisos.",steps:["Para cada indeciso: ¿hay fecha de decisión? Si no la hay: preguntar directamente.","Si llevan 10+ días sin respuesta: ofrecerles el mes 2 como salida digna."],done:"Indecisos clasificados: cerrar ahora o mes 2.",msgs:[{label:"Indeciso — mes 2",body:"Hola [Nombre], entiendo que quizás el timing no es el ideal ahora.\n\nSi le parece bien, los agendo para una conversación en [mes siguiente]. El diagnóstico seguirá disponible al mismo precio.\n\n¿Le parece?"}]}
   ],
   renata:[
     {n:1,t:"10 sitios",time:"08:30 – 10:30",dur:120,badges:["li"],launch:"Lista.",steps:["Proceso."],done:"10 hallazgos.",msgs:[]},
     {n:2,t:"Reunión si existe",time:"Según agenda",dur:0,badges:["li"],launch:"Demo.",steps:["Presentar con claridad y recomendar activación."],done:"Presentado.",msgs:[]},
     {n:3,t:"Diagnósticos/activaciones",time:"15:00 – 17:00",dur:120,badges:["li"],launch:"Diagnósticos.",steps:["Avanzar."],done:"Avanzado.",msgs:[]}
   ]
  },

  {d:18,w:4,name:"Miércoles",focus:"Post + seguimientos. Mantener el motor activo.",
   mvd_dani:"Mínimo viable: 15 LinkedIn + post LinkedIn.",
   mvd_renata:"Mínimo viable: 8 sitios + diagnóstico.",
   sync:"12:00 · 15 min",
   dani:[
     {n:1,t:"20 LinkedIn",time:"09:00 – 11:00",dur:120,badges:["li"],launch:"app.linkedin.com/sales.",steps:["Proceso."],done:"20 enviados.",msgs:[]},
     {n:2,t:"Post LinkedIn #4",time:"11:30 – 12:00",dur:30,badges:["li"],launch:"LinkedIn.",steps:["4to post de la semana. Puede ser comparación: 'antes vs después de la activación.'"],done:"Post publicado.",msgs:[]},
     {n:3,t:"Seguimientos día 3 y 7",time:"15:00 – 16:00",dur:60,badges:["li","wa"],launch:"CRM.",steps:["Seguimientos correspondientes por fecha."],done:"Enviados.",msgs:[]}
   ],
   renata:[
     {n:1,t:"10 sitios para Dani",time:"08:30 – 10:30",dur:120,badges:["li"],launch:"Lista.",steps:["Proceso."],done:"10 hallazgos.",msgs:[]},
     {n:2,t:"Diagnósticos",time:"11:00 – 13:00",dur:120,badges:["li"],launch:"En proceso.",steps:["Avanzar."],done:"Avanzado.",msgs:[]},
     {n:3,t:"Sitio web: Infraestructura + Optimización",time:"15:00 – 16:00",dur:60,badges:["web"],launch:"WordPress.",steps:["Actualizar títulos y copy de estas páginas según la guía de cambios.","Estas son prioridad 5 — hacer lo que sea posible en 1 hora."],done:"Lo posible en 1 hora.",msgs:[]}
   ]
  },

  {d:19,w:4,name:"Jueves",focus:"Último empuje de cierres. Pedir referidos.",
   mvd_dani:"Mínimo viable: 15 LinkedIn + pedir 3 referidos.",
   mvd_renata:"Mínimo viable: 10 sitios mes 2 + diagnóstico.",
   sync:"12:00 · 15 min",
   dani:[
     {n:1,t:"20 LinkedIn",time:"09:00 – 11:00",dur:120,badges:["li"],launch:"app.linkedin.com/sales.",steps:["Proceso."],done:"20 enviados.",msgs:[]},
     {n:2,t:"Seguimiento propuestas — pedir fecha decisión",time:"11:30 – 12:00",dur:30,badges:["wa"],launch:"CRM propuestas abiertas.",steps:["Para cada propuesta sin respuesta: preguntar fecha concreta de decisión.","No ofrezcer descuento. Solo pedir la fecha."],done:"Todas las propuestas tienen fecha de decisión o están cerradas.",msgs:[]},
     {n:3,t:"Pedir referidos a toda la red cálida",time:"15:00 – 16:00",dur:60,badges:["rc"],launch:"CRM red cálida.",steps:["Contactar personas de la red cálida que no respondieron ni refirieron en el mes.","Mensaje muy corto: ¿conoces a alguien?"],done:"10 mensajes de referido enviados.",msgs:[{label:"Pedir referido — red cálida mes",body:"Hola [Nombre], ¿cómo estás?\n\nCerramos nuestro primer mes de diagnósticos técnicos y me acordé de preguntarte: ¿conoces alguna empresa que pueda necesitar una revisión del estado técnico de su sitio web?\n\nSi nos refieren y se cierra, les pasamos $35.000 de comisión. Sin compromiso."}]}
   ],
   renata:[
     {n:1,t:"10 sitios prospectos mes 2",time:"08:30 – 10:30",dur:120,badges:["li"],launch:"Lista mes 2.",steps:["Revisar los prospectos que cargó Dani para mes 2.","Guardar hallazgos listos para arrancar semana 1 del mes siguiente."],done:"10 hallazgos para mes 2 listos.",msgs:[]},
     {n:2,t:"Diagnóstico/activación — estado final",time:"11:00 – 13:00",dur:120,badges:["li"],launch:"En proceso.",steps:["Terminar todo lo que se pueda cerrar este mes."],done:"Todo lo cerrable está terminado.",msgs:[]},
     {n:3,t:"Sitio web: revisión final",time:"15:00 – 16:00",dur:60,badges:["web"],launch:"Sitio web en incógnito.",steps:["Revisar el sitio completo como si fueras un prospecto.","¿El precio del diagnóstico está correcto? ¿El H1 de la home está cambiado? ¿Los CTAs están unificados? ¿La página Continuidad tiene 3 planes?","Si hay algo mal: corregirlo ahora."],done:"Sitio revisado y corregido.",msgs:[]}
   ]
  },

  {d:20,w:4,name:"Viernes",focus:"CIERRE. Medir, celebrar y definir mes 2.",
   mvd_dani:"Mínimo viable: contar los 3 números clave y definir 1 aprendizaje.",
   mvd_renata:"Mínimo viable: inventario de entregas y capacidad mes 2.",
   sync:"15:00 · 30 min · REVISIÓN COMPLETA DEL MES. Celebrar avances.",
   dani:[
     {n:1,t:"Métricas completas del mes",time:"09:00 – 10:00",dur:60,badges:["li"],
      launch:"CRM → Hoja de métricas.",
      steps:["Contar por canal: mensajes enviados, respuestas, reuniones, propuestas, diagnósticos cerrados.","¿Qué canal tuvo mejor tasa? ¿Qué mensaje funcionó más? ¿Qué sector respondió mejor?","Llenar la hoja de Resumen Financiero del CRM con los números reales."],
      done:"Métricas completas del mes registradas.",msgs:[]
     },
     {n:2,t:"CRM final + programar mes 2",time:"11:00 – 12:00",dur:60,badges:["li"],
      launch:"CRM.",
      steps:["Etiquetar todos los contactos: Cerrado / Descartado / Reactivar mes 2 / Pipeline mes 2.","Para los de 'Reactivar mes 2': asignar fecha de primer contacto en el próximo mes."],
      done:"CRM cerrado para el mes. Mes 2 programado.",msgs:[]
     },
     {n:3,t:"Definir foco mes 2 + celebrar",time:"15:00 – 16:00",dur:60,badges:["li"],
      launch:"Reunión con Renata (sync de cierre).",
      steps:["Revisar: ¿qué funcionó? ¿qué no? ¿qué cambiaríamos?","Definir la meta del mes 2 basada en lo que aprendieron (no en lo que quisiéramos).",
      "Celebrar lo que se logró. Tres meses sin claridad y ahora hay un sistema funcionando."],
      done:"Foco mes 2 definido. Celebración hecha.",msgs:[]
     }
   ],
   renata:[
     {n:1,t:"Inventario técnico de entregas",time:"08:30 – 10:00",dur:90,badges:["li"],
      launch:"Diagnósticos del mes.",
      steps:["¿Cuántos diagnósticos entregados? ¿Cuántas activaciones? ¿Cuántos planes mensuales activos?","¿Hay algo pendiente de entregar que se debe terminar esta semana?"],
      done:"Inventario completo.",msgs:[]
     },
     {n:2,t:"Capacidad mes 2 confirmada",time:"11:00 – 12:00",dur:60,badges:["li"],
      launch:"Notas de capacidad.",
      steps:["Definir: cuántos diagnósticos/mes puedes manejar sin afectar calidad.","Si hay planes mensuales activos: calcular horas/mes que requieren.","Comunicar a Dani la disponibilidad real para nuevos diagnósticos en mes 2."],
      done:"Capacidad mes 2 definida y comunicada.",msgs:[]
     },
     {n:3,t:"Revisión final del sitio web + cierre",time:"15:00 – 16:00",dur:60,badges:["web"],
      launch:"Sitio en incógnito.",
      steps:["Lista de verificación final: precio correcto, H1 correcto, CTAs unificados, 3 planes en Continuidad, 'es para/no es para' correcto.","Si hay pendientes: anotar para mes 2.","Participar en el sync de cierre con Dani."],
      done:"Sitio web revisado. Cierre del mes hecho.",msgs:[]
     }
   ]
  }
];

var timerInterval = null;
var timerSeconds = 25 * 60;
var timerRunning = false;
var timerIsWork = true;

var savedView = loadViewState();
var progressMap = Object.assign({}, config.progress || {});
var planTaskMap = Object.assign({}, config.planTasks || {});
var liveOps = {
  review: Array.isArray(config.liveOps && config.liveOps.review) ? config.liveOps.review.slice() : [],
  meeting: Array.isArray(config.liveOps && config.liveOps.meeting) ? config.liveOps.meeting.slice() : [],
  diagnostic: Array.isArray(config.liveOps && config.liveOps.diagnostic) ? config.liveOps.diagnostic.slice() : [],
  dani_ready: Array.isArray(config.liveOps && config.liveOps.dani_ready) ? config.liveOps.dani_ready.slice() : [],
  dani_waiting: Array.isArray(config.liveOps && config.liveOps.dani_waiting) ? config.liveOps.dani_waiting.slice() : [],
  reactivation_prepare: Array.isArray(config.liveOps && config.liveOps.reactivation_prepare) ? config.liveOps.reactivation_prepare.slice() : [],
  reactivation_tasks: Array.isArray(config.liveOps && config.liveOps.reactivation_tasks) ? config.liveOps.reactivation_tasks.slice() : []
};
var canCreateTasks = !!config.canCreateTasks;
var crmLinks = config.links || {};
var resourceLinks = config.resources || {};
var currentWho = normalizeWho(savedView.who || 'both');
var currentDay = normalizeDay(savedView.day || config.currentDay || 1);
var currentWeek = normalizeWeek(savedView.week || weekForDay(currentDay) || config.currentWeek || 1);

if (weekForDay(currentDay) !== currentWeek) {
  currentWeek = weekForDay(currentDay);
}

function normalizeWho(who){
  return ['both','dani','renata'].indexOf(who) !== -1 ? who : 'both';
}

function normalizeWeek(week){
  week = parseInt(week, 10);
  return week >= 1 && week <= 4 ? week : 1;
}

function normalizeDay(day){
  day = parseInt(day, 10);
  return findDay(day) ? day : 1;
}

function weekForDay(dayNum){
  var day = findDay(dayNum);
  return day ? day.w : 1;
}

function findDay(dayNum){
  for (var i = 0; i < days.length; i++) {
    if (days[i].d === dayNum) {
      return days[i];
    }
  }
  return null;
}

function loadViewState(){
  try {
    var raw = window.localStorage.getItem(VIEW_STORAGE);
    return raw ? JSON.parse(raw) : {};
  } catch (err) {
    return {};
  }
}

function saveViewState(){
  try {
    window.localStorage.setItem(VIEW_STORAGE, JSON.stringify({
      who: currentWho,
      week: currentWeek,
      day: currentDay
    }));
  } catch (err) {}
}

function isTaskDone(key){
  return !!progressMap[key];
}

function countDayProgress(dayNum){
  var day = findDay(dayNum);
  if (!day) {
    return {done: 0, total: 0};
  }
  var total = 0;
  var done = 0;
  [day.dani, day.renata].forEach(function(group, idx){
    var who = idx === 0 ? 'dani' : 'renata';
    (group || []).forEach(function(task){
      total++;
      if (isTaskDone('check_task_' + dayNum + '_' + who + '_' + task.n)) {
        done++;
      }
    });
  });
  return {done: done, total: total};
}

function dayIsDone(dayNum){
  var stats = countDayProgress(dayNum);
  return stats.total > 0 && stats.done === stats.total;
}

function renderProgressHeader(dayNum){
  var stats = countDayProgress(dayNum);
  var value = document.getElementById('twobPlanProgress');
  var hint = document.getElementById('twobPlanProgressHint');
  if (value) {
    value.textContent = stats.done + ' / ' + stats.total;
  }
  if (hint) {
    hint.textContent = stats.total ? ('Bloques marcados hoy: ' + stats.done + ' de ' + stats.total) : 'No hay bloques definidos para este dia.';
  }
}

function escapeHtml(value){
  return String(value == null ? '' : value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function buildOpsLink(url, label, className){
  if (!url) {
    return '';
  }
  return '<a class="' + (className || 'ops-link') + '" href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(label || 'Abrir') + '</a>';
}

function renderOpsEmpty(message){
  return '<div class="ops-empty">' + escapeHtml(message) + '</div>';
}

function renderReviewOpsItem(item){
  var leadId = parseInt(item && item.id, 10) || 0;
  var actions = '';
  var itemClass = 'ops-item' + ((item && item.hasFinding) ? ' is-ready' : ((item && item.isWarning) ? ' is-warning' : ''));
  var metaBadges = '<div class="ops-meta-row">' +
    '<span class="ops-badge">' + escapeHtml((item && item.originLabel) || 'Sin canal') + '</span>' +
    ((item && item.ageLabel) ? '<span class="ops-badge' + ((item && item.isWarning) ? ' is-warning' : '') + '">' + escapeHtml(item.ageLabel) + '</span>' : '') +
    ((item && item.hasFinding) ? '<span class="ops-badge is-ready">Hallazgo listo</span>' : '') +
  '</div>';

  if (item && item.websiteUrl) {
    actions += buildOpsLink(item.websiteUrl, 'Abrir sitio', 'ops-link');
  }
  if (item && item.leadUrl) {
    actions += buildOpsLink(item.leadUrl, 'Abrir lead', 'ops-link');
  }

  return '<div class="' + itemClass + '" id="ops-review-' + leadId + '">' +
    '<strong>' + escapeHtml(item.name || 'Lead sin nombre') + '</strong>' +
    '<div class="ops-meta">' + escapeHtml(item.company || 'Sin empresa') + '</div>' +
    metaBadges +
    '<div class="ops-meta">Etapa: ' + escapeHtml(item.stageLabel || 'Sin etapa') + '</div>' +
    '<div class="ops-meta">' + escapeHtml(item.websiteUrl || 'Sin sitio web registrado') + '</div>' +
    '<div class="ops-actions">' + actions + '</div>' +
    '<div class="ops-inline-editor">' +
      '<textarea class="ops-textarea" id="finding_input_' + leadId + '" placeholder="Ej: Formulario de contacto no envia confirmacion / dKIM no configurado / SSL vence en 12 dias">' + escapeHtml((item && item.technicalFinding) || '') + '</textarea>' +
      '<div class="ops-inline-footer">' +
        '<span>Guardalo en el lead para que Dani lo vea antes de contactar.</span>' +
        '<button class="ops-btn is-primary" type="button" onclick="saveFinding(this,' + leadId + ')">' + ((item && item.hasFinding) ? 'Actualizar hallazgo' : 'Guardar hallazgo') + '</button>' +
      '</div>' +
      '<div class="ops-inline-status" id="finding_status_' + leadId + '"></div>' +
    '</div>' +
  '</div>';
}

function renderMeetingOpsItem(item){
  var actions = item && item.leadUrl ? buildOpsLink(item.leadUrl, 'Abrir lead', 'ops-link is-primary') : '';
  return '<div class="ops-item">' +
    '<strong>' + escapeHtml(item.name || 'Lead sin nombre') + '</strong>' +
    '<div class="ops-meta">' + escapeHtml(item.company || 'Sin empresa') + '</div>' +
    '<div class="ops-meta">Detalle: ' + escapeHtml(item.stageLabel || 'Sin etapa') + '</div>' +
    '<div class="ops-meta">Próximo seguimiento: ' + escapeHtml(item.nextFollowup || '-') + '</div>' +
    '<div class="ops-actions">' + actions + '</div>' +
  '</div>';
}

function renderDiagnosticOpsItem(item){
  var actions = item && item.leadUrl ? buildOpsLink(item.leadUrl, 'Abrir lead', 'ops-link is-primary') : '';
  return '<div class="ops-item">' +
    '<strong>' + escapeHtml(item.name || 'Lead sin nombre') + '</strong>' +
    '<div class="ops-meta">' + escapeHtml(item.company || 'Sin empresa') + '</div>' +
    '<div class="ops-meta">Estado: ' + escapeHtml(item.statusLabel || 'INTERESADO') + '</div>' +
    '<div class="ops-meta">' + escapeHtml(item.technicalFindingShort || item.technicalFinding || 'Sin hallazgo tecnico aun') + '</div>' +
    '<div class="ops-actions">' + actions + '</div>' +
  '</div>';
}

function renderDaniReadyItem(item){
  var leadId = parseInt(item && item.id, 10) || 0;
  var actions = '';
  if (item && item.websiteUrl) {
    actions += buildOpsLink(item.websiteUrl, 'Abrir sitio', 'ops-link');
  }
  if (item && item.leadUrl) {
    actions += buildOpsLink(item.leadUrl, 'Abrir lead', 'ops-link');
  }
  actions += '<button class="ops-btn is-primary" type="button" onclick="activatePrecontact(this,' + leadId + ')">Contactar y activar</button>';
  return '<div class="ops-item is-ready">' +
    '<strong>' + escapeHtml(item.name || 'Lead sin nombre') + '</strong>' +
    '<div class="ops-meta">' + escapeHtml(item.company || 'Sin empresa') + '</div>' +
    '<div class="ops-meta-row">' +
      '<span class="ops-badge">' + escapeHtml(item.originLabel || 'Sin canal') + '</span>' +
      ((item && item.ageLabel) ? '<span class="ops-badge">' + escapeHtml(item.ageLabel) + '</span>' : '') +
      '<span class="ops-badge is-ready">Listo para contactar</span>' +
    '</div>' +
    '<div class="ops-finding">' + escapeHtml(item.technicalFinding || 'Sin hallazgo tecnico registrado') + '</div>' +
    '<div class="ops-actions">' + actions + '</div>' +
  '</div>';
}

function renderDaniWaitingItem(item){
  var actions = item && item.leadUrl ? buildOpsLink(item.leadUrl, 'Abrir lead', 'ops-link') : '';
  return '<div class="ops-item' + ((item && item.isWarning) ? ' is-warning' : '') + '">' +
    '<strong>' + escapeHtml(item.name || 'Lead sin nombre') + '</strong>' +
    '<div class="ops-meta">' + escapeHtml(item.company || 'Sin empresa') + '</div>' +
    '<div class="ops-meta-row">' +
      '<span class="ops-badge">' + escapeHtml(item.originLabel || 'Sin canal') + '</span>' +
      ((item && item.ageLabel) ? '<span class="ops-badge' + ((item && item.isWarning) ? ' is-warning' : '') + '">' + escapeHtml(item.ageLabel) + '</span>' : '') +
    '</div>' +
    '<div class="ops-meta">' + ((item && item.isWarning) ? 'Atencion: lleva mas de 4 horas esperando hallazgo.' : 'Todavia esperando la revision tecnica de Renata.') + '</div>' +
    '<div class="ops-actions">' + actions + '</div>' +
  '</div>';
}

function renderReactivationPrepareItem(item){
  var actions = item && item.leadUrl ? buildOpsLink(item.leadUrl, 'Preparar este lead', 'ops-link is-primary') : '';
  if (item && item.websiteUrl) {
    actions += buildOpsLink(item.websiteUrl, 'Abrir sitio', 'ops-link');
  }
  return '<div class="ops-item' + ((item && item.hasFinding) ? ' is-ready' : ' is-warning') + '">' +
    '<strong>' + escapeHtml(item.name || 'Lead sin nombre') + '</strong>' +
    '<div class="ops-meta">' + escapeHtml(item.company || 'Sin empresa') + '</div>' +
    '<div class="ops-meta-row">' +
      '<span class="ops-badge' + ((item && item.hasFinding) ? ' is-ready' : ' is-warning') + '">' + escapeHtml((item && item.badge) || 'Pendiente') + '</span>' +
      ((item && item.closedDay) ? '<span class="ops-badge">Cerrado: ' + escapeHtml(item.closedDay) + '</span>' : '') +
    '</div>' +
    '<div class="ops-meta">' + escapeHtml(item.websiteUrl || 'Sin sitio web registrado') + '</div>' +
    '<div class="ops-actions">' + actions + '</div>' +
  '</div>';
}

function renderReactivationTaskItem(item){
  var actions = item && item.leadUrl ? buildOpsLink(item.leadUrl, 'Abrir lead', 'ops-link is-primary') : '';
  return '<div class="ops-item is-warning">' +
    '<strong>' + escapeHtml(item.title || 'Tarea de reactivación') + '</strong>' +
    '<div class="ops-meta">' + escapeHtml(item.company || 'Sin empresa') + '</div>' +
    '<div class="ops-meta-row">' +
      '<span class="ops-badge is-warning">↻ REACTIVACIÓN</span>' +
      ((item && item.channelLabel) ? '<span class="ops-badge">' + escapeHtml(item.channelLabel) + '</span>' : '') +
      ((item && item.due) ? '<span class="ops-badge">Vence: ' + escapeHtml(item.due) + '</span>' : '') +
    '</div>' +
    '<div class="ops-actions">' + actions + '</div>' +
  '</div>';
}

function syncDaniQueuesFromReview(){
  var ready = [];
  var waiting = [];
  (liveOps.review || []).forEach(function(item){
    if (item && item.hasFinding) {
      ready.push(item);
    } else {
      waiting.push(item);
    }
  });
  liveOps.dani_ready = ready;
  liveOps.dani_waiting = waiting;
}

function renderLiveOps(){
  var panel = document.getElementById('liveOpsPanel');
  var review = Array.isArray(liveOps.review) ? liveOps.review : [];
  var meeting = Array.isArray(liveOps.meeting) ? liveOps.meeting : [];
  var diagnostic = Array.isArray(liveOps.diagnostic) ? liveOps.diagnostic : [];
  var reactivationPrepare = Array.isArray(liveOps.reactivation_prepare) ? liveOps.reactivation_prepare : [];
  var reactivationTasks = Array.isArray(liveOps.reactivation_tasks) ? liveOps.reactivation_tasks : [];
  var daniReady;
  var daniWaiting;
  var showRenata;
  var showDani;
  var total = review.length + meeting.length + diagnostic.length + reactivationPrepare.length + reactivationTasks.length;
  var html = '';

  if (!panel) {
    return;
  }

  syncDaniQueuesFromReview();
  daniReady = Array.isArray(liveOps.dani_ready) ? liveOps.dani_ready : [];
  daniWaiting = Array.isArray(liveOps.dani_waiting) ? liveOps.dani_waiting : [];
  showRenata = currentWho !== 'dani';
  showDani = currentWho !== 'renata';

  html += '<div class="ops-head"><div><h3>Datos en vivo del CRM</h3><p>El plan de 20 días ya está aquí; debajo vive la capa operativa real. Cada bloque usa el mismo lead compartido que ve Dani, para que Renata pueda revisar, preparar y registrar contexto sin salir del flujo.</p></div><span class="ops-pill">' + total + ' leads activos</span></div>';
  html += '<div class="ops-sections">';
  if (showRenata) {
    html += '<section class="ops-section"><div class="ops-section-head"><div><h4>Vista Renata</h4><p>Renata revisa pre-contactos y también prepara reactivaciones cerradas antes de que Dani vuelva a entrar en escena.</p></div><span class="ops-pill">' + (review.length + reactivationPrepare.length) + ' en trabajo</span></div><div class="ops-grid">';
    html += '<section class="ops-card"><div class="ops-card-head"><div><h4>Sitios para revisar hoy</h4><p>Solo leads en PRE-CONTACTO. Guardar el hallazgo no saca el lead de esta cola.</p></div><span class="ops-count">' + review.length + '</span></div>' + (review.length ? '<div class="ops-list">' + review.map(renderReviewOpsItem).join('') + '</div>' : renderOpsEmpty('No hay sitios pendientes en esta cola ahora mismo.')) + '</section>';
    html += '<section class="ops-card"><div class="ops-card-head"><div><h4>Reuniones que preparar</h4><p>Leads que ya saltaron a reunion y necesitan contexto tecnico listo antes de hablar con el prospecto.</p></div><span class="ops-count">' + meeting.length + '</span></div>' + (meeting.length ? '<div class="ops-list">' + meeting.map(renderMeetingOpsItem).join('') + '</div>' : renderOpsEmpty('No hay reuniones que preparar en este momento.')) + '</section>';
    html += '<section class="ops-card"><div class="ops-card-head"><div><h4>Diagnosticos en proceso</h4><p>Diagnosticos que ya estan con interes activo o propuesta enviada y requieren seguimiento tecnico.</p></div><span class="ops-count">' + diagnostic.length + '</span></div>' + (diagnostic.length ? '<div class="ops-list">' + diagnostic.map(renderDiagnosticOpsItem).join('') + '</div>' : renderOpsEmpty('No hay diagnosticos en proceso en esta cola ahora mismo.')) + '</section>';
    html += '<section class="ops-card"><div class="ops-card-head"><div><h4>Leads para preparar hoy</h4><p>Primero los que no tienen hallazgo; después los que ya tienen hallazgo pero aún no cierran investigación de canales.</p></div><span class="ops-count">' + reactivationPrepare.length + '</span></div>' + (reactivationPrepare.length ? '<div class="ops-list">' + reactivationPrepare.map(renderReactivationPrepareItem).join('') + '</div>' : renderOpsEmpty('No hay reactivaciones pendientes para preparar ahora mismo.')) + '</section>';
    html += '</div></section>';
  }
  if (showDani) {
    html += '<section class="ops-section"><div class="ops-section-head"><div><h4>Vista Dani</h4><p>Dani ve qué pre-contactos están listos y qué reactivaciones vencen hoy, sin mezclarlo con el ciclo normal.</p></div><span class="ops-pill">' + (daniReady.length + reactivationTasks.length) + ' accionables</span></div><div class="ops-grid">';
    html += '<section class="ops-card"><div class="ops-card-head"><div><h4>Listos para contactar hoy</h4><p>Leads en PRE-CONTACTO que ya tienen hallazgo tecnico cargado y pueden activarse desde aqui.</p></div><span class="ops-count">' + daniReady.length + '</span></div>' + (daniReady.length ? '<div class="ops-list">' + daniReady.map(renderDaniReadyItem).join('') + '</div>' : renderOpsEmpty('Todavia no hay leads listos para activar hoy.')) + '</section>';
    html += '<section class="ops-card"><div class="ops-card-head"><div><h4>Esperando revision de Renata</h4><p>Leads que siguen en PRE-CONTACTO pero aun no tienen hallazgo registrado.</p></div><span class="ops-count">' + daniWaiting.length + '</span></div>' + (daniWaiting.length ? '<div class="ops-list">' + daniWaiting.map(renderDaniWaitingItem).join('') + '</div>' : renderOpsEmpty('No hay leads esperando revision ahora mismo.')) + '</section>';
    html += '<section class="ops-card"><div class="ops-card-head"><div><h4>Reactivaciones de hoy</h4><p>Tareas de reactivación vencidas o para hoy. LinkedIn ya no aparece aquí.</p></div><span class="ops-count">' + reactivationTasks.length + '</span></div>' + (reactivationTasks.length ? '<div class="ops-list">' + reactivationTasks.map(renderReactivationTaskItem).join('') + '</div>' : renderOpsEmpty('No hay tareas de reactivación pendientes para hoy.')) + '</section>';
    html += '<section class="ops-card"><div class="ops-card-head"><div><h4>Acceso rapido</h4><p>Si quieres ver todos los pre-contactos o editar un lead completo, abre la lista del CRM en esa vista.</p></div><span class="ops-count">CRM</span></div><div class="ops-list"><div class="ops-item"><strong>Ir a la cola completa</strong><div class="ops-meta">Abre la lista filtrada por PRE-CONTACTO para revisar o activar desde la ficha completa.</div><div class="ops-actions">' + buildOpsLink(crmLinks.leadsPreContact || crmLinks.leads, 'Abrir Pre-contactos', 'ops-link is-primary') + buildOpsLink(crmLinks.leads, 'Abrir todos los leads', 'ops-link') + '</div></div></div></section>';
    html += '</div></section>';
  }
  html += '</div>';
  panel.innerHTML = html;
}

function persistFinding(leadId, technicalFinding){
  var body = new URLSearchParams();
  body.append('action', 'twob_crm_daily_plan_save_finding');
  body.append('nonce', config.nonce || '');
  body.append('lead_id', String(leadId || 0));
  body.append('technical_finding', technicalFinding || '');
  return fetch(config.ajaxUrl || window.ajaxurl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
    body: body.toString()
  }).then(function(response){
    return response.json();
  }).then(function(json){
    if (!json || !json.success) {
      throw new Error(json && json.data && json.data.message ? json.data.message : 'No se pudo guardar el hallazgo.');
    }
    return json.data || {};
  });
}

function persistActivation(leadId, firstContactChannel){
  var body = new URLSearchParams();
  body.append('action', 'twob_crm_daily_plan_activate_precontact');
  body.append('nonce', config.nonce || '');
  body.append('lead_id', String(leadId || 0));
  body.append('first_contact_channel', firstContactChannel || '');
  return fetch(config.ajaxUrl || window.ajaxurl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
    body: body.toString()
  }).then(function(response){
    return response.json();
  }).then(function(json){
    if (!json || !json.success) {
      throw new Error(json && json.data && json.data.message ? json.data.message : 'No se pudo activar el lead.');
    }
    return json.data || {};
  });
}

function saveFinding(btn, leadId){
  var input = document.getElementById('finding_input_' + leadId);
  var status = document.getElementById('finding_status_' + leadId);
  var value = input ? String(input.value || '').trim() : '';

  if (!value) {
    if (status) {
      status.className = 'ops-inline-status is-error';
      status.textContent = 'Escribe un hallazgo antes de guardar.';
    }
    return;
  }

  if (status) {
    status.className = 'ops-inline-status';
    status.textContent = 'Guardando hallazgo en el CRM...';
  }
  btn.disabled = true;
  btn.textContent = 'Guardando...';

  persistFinding(leadId, value).then(function(){
    (liveOps.review || []).forEach(function(item){
      if (parseInt(item && item.id, 10) === parseInt(leadId, 10)) {
        item.hasFinding = true;
        item.technicalFinding = value;
        item.technicalFindingShort = value.length > 60 ? value.slice(0, 57) + '...' : value;
        item.isWarning = false;
      }
    });
    syncDaniQueuesFromReview();
    window.setTimeout(function(){ renderLiveOps(); }, 180);
  }).catch(function(err){
    btn.disabled = false;
    btn.textContent = 'Guardar hallazgo';
    if (status) {
      status.className = 'ops-inline-status is-error';
      status.textContent = err && err.message ? err.message : 'No se pudo guardar el hallazgo.';
    }
  });
}

function activatePrecontact(btn, leadId){
  var lead = (liveOps.review || []).filter(function(item){
    return parseInt(item && item.id, 10) === parseInt(leadId, 10);
  })[0] || null;
  var leadName = lead && lead.name ? lead.name : 'este lead';
  var leadCompany = lead && lead.company ? lead.company : 'esta empresa';
  var firstContactChannel = lead && lead.originChannel ? lead.originChannel : '';

  if (!window.confirm('¿Confirmas que enviaste el primer mensaje a ' + leadName + ' de ' + leadCompany + '?')) {
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Activando...';

  persistActivation(leadId, firstContactChannel).then(function(){
    liveOps.review = (liveOps.review || []).filter(function(item){
      return parseInt(item && item.id, 10) !== parseInt(leadId, 10);
    });
    syncDaniQueuesFromReview();
    renderLiveOps();
  }).catch(function(err){
    btn.disabled = false;
    btn.textContent = 'Contactar y activar';
    window.alert(err && err.message ? err.message : 'No se pudo activar el lead.');
  });
}

function taskRecordKey(dayNum, who, taskNumber){
  return 'task_' + dayNum + '_' + who + '_' + taskNumber;
}

function findTaskRef(dayNum, who, taskNumber){
  var day = findDay(dayNum);
  var group;
  var found = null;
  if (!day) {
    return null;
  }
  group = who === 'renata' ? (day.renata || []) : (day.dani || []);
  group.forEach(function(task){
    if (!found && parseInt(task.n, 10) === parseInt(taskNumber, 10)) {
      found = task;
    }
  });
  return found ? {day: day, task: found, key: taskRecordKey(dayNum, who, taskNumber)} : null;
}

function taskRecord(dayNum, who, taskNumber){
  return planTaskMap[taskRecordKey(dayNum, who, taskNumber)] || null;
}

function taskText(task){
  return [task.t || '', task.launch || ''].concat(task.steps || []).join(' ').toLowerCase();
}

function hasAny(text, needles){
  return needles.some(function(needle){
    return text.indexOf(needle) !== -1;
  });
}

function safeUrl(url){
  if (url) {
    return url;
  }
  return crmLinks.dashboard || '#';
}

function taskPrimaryResource(task){
  var text = taskText(task);
  var launch = String(task.launch || '').toLowerCase();

  if (launch.indexOf('app.linkedin.com/sales') !== -1 || hasAny(text, ['sales navigator', 'busqueda de leads', 'búsqueda de leads'])) {
    return {label: 'Abrir Sales Navigator', url: safeUrl(resourceLinks.salesNavigator)};
  }
  if (hasAny(text, ['whatsapp'])) {
    return {label: 'Abrir WhatsApp', url: safeUrl(resourceLinks.whatsapp)};
  }
  if (hasAny(text, ['google docs', 'documento nuevo', 'brief'])) {
    return {label: 'Abrir Google Docs', url: safeUrl(resourceLinks.googleDocs)};
  }
  if (hasAny(text, ['google sheets', 'hoja de metricas', 'hoja de métricas', 'resumen financiero'])) {
    return {label: 'Abrir Google Sheets', url: safeUrl(resourceLinks.googleSheets)};
  }
  if (hasAny(text, ['pagespeed'])) {
    return {label: 'Abrir PageSpeed', url: safeUrl(resourceLinks.pageSpeed)};
  }
  if (hasAny(text, ['mxtoolbox', 'dkim', 'spf'])) {
    return {label: 'Abrir MXToolbox', url: safeUrl(resourceLinks.mxToolbox)};
  }
  if (hasAny(text, ['wordpress', 'elementor'])) {
    return {label: 'Abrir WordPress', url: safeUrl(resourceLinks.siteAdmin || crmLinks.siteAdmin)};
  }
  if (hasAny(text, ['sitio web', 'sitio en incognito', 'sitio en incógnito'])) {
    return {label: 'Abrir sitio web', url: safeUrl(resourceLinks.siteHome)};
  }
  if (hasAny(text, ['linkedin'])) {
    return {label: 'Abrir LinkedIn', url: safeUrl(resourceLinks.linkedin)};
  }
  if (hasAny(text, ['crm'])) {
    return {label: 'Abrir CRM', url: safeUrl(crmLinks.dashboard)};
  }
  return {label: 'Abrir recurso', url: safeUrl(crmLinks.dashboard)};
}

function taskCrmFocus(task){
  var text = taskText(task);

  if (hasAny(text, ['respuesta', 'respondio', 'respondió', 'interesado', 'interesada'])) {
    return {label: 'Abrir respuestas', url: safeUrl(crmLinks.responses || crmLinks.interactions)};
  }
  if (hasAny(text, ['reunion', 'reunión', 'llamada', 'agenda'])) {
    return {label: 'Abrir calendario', url: safeUrl(crmLinks.calendar || crmLinks.tasksVisible)};
  }
  if (hasAny(text, ['propuesta'])) {
    return {label: 'Abrir propuestas', url: safeUrl(crmLinks.proposals || crmLinks.tasksVisible)};
  }
  if (hasAny(text, ['cobro', 'caja', 'pago'])) {
    return {label: 'Abrir cobros', url: safeUrl(crmLinks.payments || crmLinks.reports || crmLinks.dashboard)};
  }
  if (hasAny(text, ['riesgo', 'enfriar'])) {
    return {label: 'Abrir riesgo', url: safeUrl(crmLinks.risk || crmLinks.leadsFollowup)};
  }
  if (hasAny(text, ['pipeline', 'mes 2', 'agencia', 'agencias'])) {
    return {label: 'Abrir pipeline', url: safeUrl(crmLinks.pipeline || crmLinks.leads)};
  }
  if (hasAny(text, ['metrica', 'métrica', 'report', 'resumen financiero'])) {
    return {label: 'Abrir reportes', url: safeUrl(crmLinks.reports || crmLinks.dashboard)};
  }
  if (hasAny(text, ['seguimiento', 'follow up', 'followup'])) {
    return {label: 'Abrir seguimiento', url: safeUrl(crmLinks.leadsFollowup || crmLinks.tasksVisible)};
  }
  if (hasAny(text, ['interaccion', 'interacción'])) {
    return {label: 'Abrir interacciones', url: safeUrl(crmLinks.interactions)};
  }
  if (hasAny(text, ['red calida', 'red cálida', 'linkedin', 'prospectos', 'contactos', 'lead'])) {
    return {label: 'Abrir leads', url: safeUrl(crmLinks.leadsToday || crmLinks.leads)};
  }
  return {label: 'Abrir tareas visibles', url: safeUrl(crmLinks.tasksVisible || crmLinks.tasks)};
}

function inferTaskType(task){
  var text = taskText(task);

  if (hasAny(text, ['cobro', 'caja', 'pago'])) {
    return 'collection';
  }
  if (hasAny(text, ['reunion', 'reunión', 'llamada', 'agendada', 'agendamiento', 'entrega'])) {
    return 'meeting';
  }
  if (hasAny(text, ['propuesta', 'cotizacion', 'cotización'])) {
    return 'proposal';
  }
  if (hasAny(text, ['post linkedin', 'crear publicacion', 'crear publicación'])) {
    return 'linkedin_post';
  }
  if (hasAny(text, ['brief', 'contenido'])) {
    return 'content_creation';
  }
  if (hasAny(text, ['hosting', 'ssl', 'dkim', 'spf'])) {
    return 'hosting_review';
  }
  if (hasAny(text, ['wordpress', 'elementor', 'sitio web', 'pagespeed', 'mxtoolbox', 'diagnostico', 'diagnóstico', 'activacion', 'activación'])) {
    return 'support';
  }
  if (hasAny(text, ['seguimiento', 'follow up', 'followup', 'respuesta', 'respondio', 'respondió', 'reactivar', 're-contacto'])) {
    return 'followup';
  }
  if (hasAny(text, ['linkedin', 'whatsapp', 'contactos', 'red calida', 'red cálida', 'agencia', 'agencias', 'prospectos', 'referido'])) {
    return 'contact_lead';
  }
  return 'other';
}

function inferTaskCategory(task){
  var type = inferTaskType(task);
  if (['linkedin_post', 'content_creation', 'web_optimization'].indexOf(type) !== -1) {
    return 'marketing';
  }
  if (['hosting_review', 'collection', 'support', 'other'].indexOf(type) !== -1) {
    return 'operational';
  }
  return 'commercial';
}

function inferTaskPriority(task){
  var text = taskText(task);
  if (hasAny(text, ['critico', 'crítico', 'urgente', 'respuesta', 'respondio', 'respondió', 'reunion', 'reunión', 'propuesta', 'cobro', 'caliente', 'entrega'])) {
    return 'high';
  }
  if (hasAny(text, ['post', 'brief', 'lista', 'metrica', 'métrica', 'inventario', 'resumen'])) {
    return 'low';
  }
  return 'medium';
}

function parseStartTime(timeLabel){
  var match = String(timeLabel || '').match(/(\d{2}:\d{2})/);
  return match ? match[1] : '';
}

function buildPlanTaskPayload(task, who, dayNum){
  var type = inferTaskType(task);
  var category = inferTaskCategory(task);
  var resource = taskPrimaryResource(task);
  var crmFocus = taskCrmFocus(task);
  var lines = [
    'Bloque del plan diario: Día ' + dayNum + ' · ' + (who === 'dani' ? 'Dani' : 'Renata'),
    'Objetivo: ' + (task.t || '')
  ];

  if (task.launch) {
    lines.push('Empieza aquí: ' + task.launch);
  }
  if (task.steps && task.steps.length) {
    lines.push('Pasos:');
    task.steps.forEach(function(step, idx){
      lines.push((idx + 1) + '. ' + step);
    });
  }
  if (task.done) {
    lines.push('Criterio de cierre: ' + task.done);
  }
  if (task.msgs && task.msgs.length) {
    lines.push('Mensajes listos: ' + task.msgs.map(function(message){ return message.label; }).join(' | '));
  }
  if (resource && resource.url) {
    lines.push('Recurso sugerido: ' + resource.label + ' -> ' + resource.url);
  }
  if (crmFocus && crmFocus.url) {
    lines.push('Foco CRM sugerido: ' + crmFocus.label + ' -> ' + crmFocus.url);
  }

  return {
    task_key: taskRecordKey(dayNum, who, task.n),
    day_num: dayNum,
    who: who,
    title: (who === 'dani' ? 'Dani' : 'Renata') + ' · Día ' + dayNum + ' · ' + task.t,
    description: lines.join('\n'),
    category: category,
    task_type: type,
    priority: inferTaskPriority(task),
    assignee: who,
    start_time: parseStartTime(task.time),
    duration: parseInt(task.dur || 30, 10) || 30
  };
}

function buildActionLink(action, className){
  if (!action || !action.url) {
    return '';
  }
  return '<a class="' + className + '" href="' + escapeHtml(action.url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(action.label || 'Abrir') + '</a>';
}

function renderPlanTaskStatus(taskKey){
  var record = planTaskMap[taskKey];
  if (!record || !record.id) {
    return '<div class="task-status" id="status_' + taskKey + '">Este bloque aún no tiene tarea CRM creada.</div>';
  }
  return '<div class="task-status is-ok" id="status_' + taskKey + '"><strong>' + escapeHtml(record.statusLabel || 'Pendiente') + '</strong>' + (record.due ? ' · vence ' + escapeHtml(record.due) : '') + ' · <a href="' + escapeHtml(record.editUrl || record.panelUrl || '#') + '" target="_blank" rel="noopener noreferrer">Abrir tarea</a></div>';
}

function bindButtonToTask(btn, record){
  var openUrl = record && (record.editUrl || record.panelUrl);
  if (!btn || !openUrl) {
    return;
  }
  btn.disabled = false;
  btn.textContent = 'Abrir tarea CRM';
  btn.onclick = function(ev){
    if (ev && typeof ev.preventDefault === 'function') {
      ev.preventDefault();
    }
    if (ev && typeof ev.stopPropagation === 'function') {
      ev.stopPropagation();
    }
    window.open(openUrl, '_blank', 'noopener');
    return false;
  };
}

function syncTopbarState(){
  document.querySelectorAll('.who-btn').forEach(function(btn){
    btn.classList.toggle('active', btn.getAttribute('data-who') === currentWho);
  });
  document.querySelectorAll('.wsb').forEach(function(btn){
    btn.classList.toggle('active', parseInt(btn.getAttribute('data-week'), 10) === currentWeek);
  });
}

function buildDayNav(week){
  var nav = document.getElementById('dayNav');
  var dayNames = ['Lun','Mar','Mie','Jue','Vie'];
  if (!nav) {
    return;
  }
  nav.innerHTML = '';
  days.filter(function(day){ return day.w === week; }).forEach(function(day){
    var btn = document.createElement('button');
    btn.className = 'dnb' + (day.d === currentDay ? ' active' : '') + (dayIsDone(day.d) ? ' done' : '');
    btn.setAttribute('type', 'button');
    btn.setAttribute('data-day', day.d);
    btn.innerHTML = '<span class="dw">' + dayNames[(day.d - 1) % 5] + '</span>' + day.d;
    btn.addEventListener('click', function(){
      selectDay(day.d);
    });
    nav.appendChild(btn);
  });
}

function buildWeekSummary(week){
  var wi = weekInfo[week - 1];
  var el = document.getElementById('weekSummary');
  if (!wi || !el) {
    return;
  }
  var goals = wi.goals.map(function(g){
    return '<div class="wg"><div class="wg-n">' + g.n + '</div><div class="wg-l">' + g.l + '</div></div>';
  }).join('');
  el.innerHTML = '<h2 class="ws-title">' + wi.title + '</h2><p class="ws-desc">' + wi.desc + '</p><div class="ws-goals">' + goals + '</div>';
}

function buildDay(dayNum){
  var day = findDay(dayNum);
  if (!day) {
    return;
  }

  var dayNames = ['Lunes','Martes','Miércoles','Jueves','Viernes'];
  var nm = dayNames[(dayNum - 1) % 5];
  document.getElementById('dayTitle').textContent = 'Día ' + dayNum + ' — ' + nm;
  document.getElementById('dayFocus').textContent = day.focus;

  var html = '';
  var showDani = currentWho !== 'renata';
  var showRenata = currentWho !== 'dani';
  var gridClass = currentWho === 'dani' ? 'single-dani' : currentWho === 'renata' ? 'single-renata' : '';

  html += '<div class="people-grid ' + gridClass + '">';

  if (showDani) {
    html += '<div class="col-dani">';
    html += '<div class="person-header ph-dani"><div class="person-avatar">D</div><div><div class="person-name">Dani</div><div class="person-role">Comercial · Alianzas · Contenido</div></div><div class="ph-energy">5h efectivas/día</div></div>';
    html += '<div class="task-list">';
    day.dani.forEach(function(task){ html += buildTask(task, 'dani', dayNum); });
    if (day.mvd_dani) {
      html += '<div class="tc"><div class="tc-head" style="cursor:default;background:#fff8ec;padding:12px 16px"><div class="mvd" style="margin:0;padding:0;background:none;border:none"><h4><span>⚡</span> Mínimo viable hoy</h4><p>' + day.mvd_dani + '</p></div></div></div>';
    }
    html += '</div></div>';
  }

  if (showRenata) {
    html += '<div class="col-renata">';
    html += '<div class="person-header ph-renata"><div class="person-avatar">R</div><div><div class="person-name">Renata</div><div class="person-role">Técnica · Hallazgos · Diagnósticos</div></div><div class="ph-energy">5h efectivas/día</div></div>';
    html += '<div class="task-list">';
    day.renata.forEach(function(task){ html += buildTask(task, 'renata', dayNum); });
    if (day.mvd_renata) {
      html += '<div class="tc"><div class="tc-head" style="cursor:default;background:#fff8ec;padding:12px 16px"><div class="mvd" style="margin:0;padding:0;background:none;border:none"><h4><span>⚡</span> Mínimo viable hoy</h4><p>' + day.mvd_renata + '</p></div></div></div>';
    }
    html += '</div></div>';
  }

  html += '</div>';

  if (day.sync) {
    html += '<div class="sync-bar"><div class="sync-icon">⟳</div><div class="sync-time">SYNC DIARIO</div><div class="sync-text">' + day.sync + '</div></div>';
  }

  document.getElementById('dayContent').innerHTML = html;
  syncTaskChecks();
  renderProgressHeader(dayNum);
  renderLiveOps();
}

function buildTask(task, who, dayNum){
  var bid = 'task_' + dayNum + '_' + who + '_' + task.n;
  var planTaskKey = taskRecordKey(dayNum, who, task.n);
  var numClass = who === 'dani' ? 'num-dani' : 'num-renata';
  var resourceAction = taskPrimaryResource(task);
  var crmAction = taskCrmFocus(task);
  var existingRecord = taskRecord(dayNum, who, task.n);

  var badges = (task.badges || []).map(function(badge){
    var map = {li:'LinkedIn',wa:'WhatsApp',rc:'Red cálida',ag:'Alianzas',em:'Email',web:'Sitio web'};
    var cls = {li:'tb-li',wa:'tb-wa',rc:'tb-rc',ag:'tb-ag',em:'tb-em',web:'tb-web'};
    return '<span class="tb ' + cls[badge] + '">' + map[badge] + '</span>';
  }).join('');

  var steps = (task.steps || []).map(function(step, idx){
    return '<div class="step"><span class="step-n">' + (idx + 1) + '</span><div>' + step + '</div></div>';
  }).join('');

  var messages = '';
  (task.msgs || []).forEach(function(message, idx){
    var mid = 'msg_' + bid + '_' + idx;
    messages += '<button class="msg-toggle" type="button" onclick="toggleMsg(\'' + mid + '\')" id="btn_' + mid + '">Ver mensaje: ' + message.label + '</button>';
    messages += '<div class="msg-box" id="' + mid + '"><div class="msg-content">' + message.body.replace(/\[([^\]]+)\]/g,'<span class="msg-ph">[$1]</span>').replace(/\n/g,'<br>') + '</div><div class="msg-foot"><span style="font-size:11px;color:var(--li)">Reemplaza el texto destacado antes de enviar</span><button class="copy-mini" type="button" onclick="cpMsg(this,\'' + mid + '\')">Copiar</button></div></div>';
  });

  var launchHtml = task.launch ? '<div class="step-label">Empieza aquí</div><div class="steps"><div class="step"><span class="step-n">→</span><div><strong>Abre:</strong> ' + task.launch + '</div></div></div>' : '';
  var doneHtml = task.done ? '<div class="done-box">✓ Estás lista cuando: <strong>' + task.done + '</strong></div>' : '';
  var key = 'check_' + bid;
  var actionsHtml = '<div class="step-label">Acciones rápidas</div><div class="task-actions-panel"><p class="task-actions-copy">Abre el recurso exacto, salta al foco correcto del CRM o convierte este bloque en una tarea real para que no se pierda.</p><div class="task-actions-grid">';

  actionsHtml += buildActionLink(resourceAction, 'task-action-link');
  actionsHtml += buildActionLink(crmAction, 'task-action-link is-muted');
  if (existingRecord && existingRecord.id) {
    actionsHtml += buildActionLink({label: 'Abrir tarea CRM', url: existingRecord.editUrl || existingRecord.panelUrl || '#'}, 'task-action-link is-primary');
  } else if (canCreateTasks) {
    actionsHtml += '<button class="task-action-btn is-primary" type="button" onclick="createPlanTask(this,' + dayNum + ',\'' + who + '\',' + task.n + ')">Crear tarea CRM</button>';
  }
  actionsHtml += '</div>' + renderPlanTaskStatus(planTaskKey) + '</div>';

  var html = '<div class="tc" id="' + bid + '">';
  html += '<div class="tc-head" onclick="toggleTask(\'' + bid + '\')">';
  html += '<div class="tc-num ' + numClass + '">' + task.n + '</div>';
  html += '<div class="tc-info"><div class="tc-title">' + task.t + '</div><div class="tc-time">⏱ ' + task.time + (task.dur ? ' · ' + task.dur + ' min' : '') + '</div></div>';
  html += '<div class="tc-badges">' + badges + '</div>';
  html += '<div class="tc-check" data-key="' + key + '" onclick="toggleCheck(event,\'' + key + '\',' + dayNum + ',\'' + who + '\')"></div>';
  html += '<span class="tc-arrow" id="arr_' + bid + '">▾</span>';
  html += '</div>';
  html += '<div class="tc-body" id="body_' + bid + '">';
  html += launchHtml;
  if (steps) {
    html += '<div class="step-label">Pasos exactos</div><div class="steps">' + steps + '</div>';
  }
  if (messages) {
    html += '<div class="step-label">Mensajes listos</div>' + messages;
  }
  html += doneHtml;
  html += actionsHtml;
  html += '</div></div>';
  return html;
}

function syncTaskChecks(){
  document.querySelectorAll('.tc-check').forEach(function(el){
    var key = el.getAttribute('data-key');
    var done = isTaskDone(key);
    el.classList.toggle('done', done);
    el.textContent = done ? '✓' : '';
    el.classList.remove('is-saving');
  });
}

function toggleTask(id){
  var body = document.getElementById('body_' + id);
  var arrow = document.getElementById('arr_' + id);
  if (!body) {
    return;
  }
  var open = body.classList.contains('open');
  body.classList.toggle('open', !open);
  if (arrow) {
    arrow.classList.toggle('open', !open);
  }
}

function toggleMsg(id){
  var el = document.getElementById(id);
  if (el) {
    el.classList.toggle('open');
  }
}

function copyText(text){
  if (navigator.clipboard && navigator.clipboard.writeText) {
    return navigator.clipboard.writeText(text);
  }
  return new Promise(function(resolve, reject){
    var textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', 'readonly');
    textarea.style.position = 'absolute';
    textarea.style.left = '-9999px';
    document.body.appendChild(textarea);
    textarea.select();
    try {
      document.execCommand('copy');
      document.body.removeChild(textarea);
      resolve();
    } catch (err) {
      document.body.removeChild(textarea);
      reject(err);
    }
  });
}

function cpMsg(btn,id){
  var box = document.getElementById(id);
  if (!box) {
    return;
  }
  var txt = box.querySelector('.msg-content').innerText;
  copyText(txt).then(function(){
    btn.textContent = '✓ Copiado';
    btn.classList.add('ok');
    setTimeout(function(){
      btn.textContent = 'Copiar';
      btn.classList.remove('ok');
    }, 1800);
  }).catch(function(){
    alert('No pude copiar el mensaje.');
  });
}

function persistProgress(taskKey, checked){
  var body = new URLSearchParams();
  body.append('action', 'twob_crm_daily_plan_toggle');
  body.append('nonce', config.nonce || '');
  body.append('task_key', taskKey);
  body.append('checked', checked ? '1' : '0');
  return fetch(config.ajaxUrl || window.ajaxurl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
    body: body.toString()
  }).then(function(response){
    return response.json();
  }).then(function(json){
    if (!json || !json.success) {
      throw new Error(json && json.data && json.data.message ? json.data.message : 'No se pudo guardar el progreso.');
    }
    return json.data || {};
  });
}

function persistPlanTask(payload){
  var body = new URLSearchParams();
  body.append('action', 'twob_crm_daily_plan_create_task');
  body.append('nonce', config.nonce || '');
  Object.keys(payload || {}).forEach(function(key){
    body.append(key, payload[key]);
  });
  return fetch(config.ajaxUrl || window.ajaxurl, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
    body: body.toString()
  }).then(function(response){
    return response.json();
  }).then(function(json){
    if (!json || !json.success) {
      throw new Error(json && json.data && json.data.message ? json.data.message : 'No se pudo crear la tarea en el CRM.');
    }
    return json.data || {};
  });
}

function createPlanTask(btn, dayNum, who, taskNumber){
  var ref = findTaskRef(dayNum, who, taskNumber);
  var payload;
  var statusEl;
  if (!ref || !ref.task) {
    alert('No pude ubicar ese bloque del plan.');
    return;
  }

  payload = buildPlanTaskPayload(ref.task, who, dayNum);
  statusEl = document.getElementById('status_' + payload.task_key);
  btn.disabled = true;
  btn.textContent = 'Creando...';
  if (statusEl) {
    statusEl.className = 'task-status';
    statusEl.textContent = 'Creando tarea en el CRM...';
  }

  persistPlanTask(payload).then(function(data){
    var record = data.record || null;
    if (record && record.taskKey) {
      planTaskMap[record.taskKey] = record;
    }
    if (statusEl) {
      statusEl.outerHTML = renderPlanTaskStatus(payload.task_key);
    }
    if (record) {
      bindButtonToTask(btn, record);
    } else {
      btn.disabled = false;
      btn.textContent = 'Crear tarea CRM';
    }
  }).catch(function(err){
    btn.disabled = false;
    btn.textContent = 'Crear tarea CRM';
    if (statusEl) {
      statusEl.className = 'task-status is-error';
      statusEl.textContent = err && err.message ? err.message : 'No se pudo crear la tarea.';
    } else {
      alert(err && err.message ? err.message : 'No se pudo crear la tarea.');
    }
  });
}

function toggleCheck(e,key,dayNum,who){
  if (e && typeof e.stopPropagation === 'function') {
    e.stopPropagation();
  }
  var el = document.querySelector('[data-key="' + key + '"]');
  if (!el) {
    return;
  }
  var wasDone = isTaskDone(key);
  var next = !wasDone;

  if (next) {
    progressMap[key] = 1;
  } else {
    delete progressMap[key];
  }

  el.classList.add('is-saving');
  el.classList.toggle('done', next);
  el.textContent = next ? '✓' : '';
  renderProgressHeader(dayNum);
  buildDayNav(currentWeek);

  persistProgress(key, next).then(function(){
    el.classList.remove('is-saving');
  }).catch(function(err){
    if (wasDone) {
      progressMap[key] = 1;
    } else {
      delete progressMap[key];
    }
    el.classList.remove('is-saving');
    el.classList.toggle('done', wasDone);
    el.textContent = wasDone ? '✓' : '';
    renderProgressHeader(dayNum);
    buildDayNav(currentWeek);
    alert(err && err.message ? err.message : 'No se pudo guardar el check.');
  });
}

function selectDay(dayNum){
  currentDay = normalizeDay(dayNum);
  currentWeek = weekForDay(currentDay);
  syncTopbarState();
  buildDayNav(currentWeek);
  buildWeekSummary(currentWeek);
  buildDay(currentDay);
  saveViewState();
}

function setWho(who){
  currentWho = normalizeWho(who);
  syncTopbarState();
  buildDay(currentDay);
  saveViewState();
}

function setWeek(week){
  week = normalizeWeek(week);
  currentWeek = week;
  if (weekForDay(currentDay) !== currentWeek) {
    var firstDay = days.find(function(day){ return day.w === currentWeek; });
    currentDay = firstDay ? firstDay.d : currentDay;
  }
  syncTopbarState();
  buildDayNav(currentWeek);
  buildWeekSummary(currentWeek);
  buildDay(currentDay);
  saveViewState();
}

function goToSuggestedDay(){
  selectDay(normalizeDay(config.currentDay || 1));
}

// ══ POMODORO ══
function openTimer(){document.getElementById('timerOverlay').classList.add('open')}
function closeTimer(){document.getElementById('timerOverlay').classList.remove('open')}

function startTimer(){
  if(timerRunning){
    clearInterval(timerInterval);
    timerRunning = false;
    document.getElementById('timerStartBtn').textContent = 'Iniciar';
    return;
  }
  timerRunning = true;
  document.getElementById('timerStartBtn').textContent = 'Pausar';
  timerInterval = setInterval(function(){
    timerSeconds--;
    if(timerSeconds <= 0){
      clearInterval(timerInterval);
      timerRunning = false;
      timerIsWork = !timerIsWork;
      timerSeconds = timerIsWork ? 25 * 60 : 5 * 60;
      document.getElementById('timerStartBtn').textContent = 'Iniciar';
      document.getElementById('timerPhaseLabel').textContent = timerIsWork ? 'Bloque de trabajo' : 'Descanso';
      document.getElementById('timerSubLabel').textContent = timerIsWork ? 'Enfócate - sin redes sociales ni WhatsApp' : 'Levántate, toma agua, muévete.';
      try {
        var audio = new Audio('data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEARKwAAIhYAQACABAAZGF0YQAAAAA=');
        audio.play();
      } catch (err) {}
    }
    var m = Math.floor(timerSeconds / 60);
    var s = timerSeconds % 60;
    document.getElementById('timerDisplay').textContent = (m < 10 ? '0' : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }, 1000);
}

function resetTimer(){
  clearInterval(timerInterval);
  timerRunning = false;
  timerIsWork = true;
  timerSeconds = 25 * 60;
  document.getElementById('timerDisplay').textContent = '25:00';
  document.getElementById('timerStartBtn').textContent = 'Iniciar';
  document.getElementById('timerPhaseLabel').textContent = 'Bloque de trabajo';
  document.getElementById('timerSubLabel').textContent = 'Enfócate - sin redes sociales ni WhatsApp';
}

// expose handlers used by inline buttons
window.toggleTask = toggleTask;
window.toggleMsg = toggleMsg;
window.cpMsg = cpMsg;
window.toggleCheck = toggleCheck;
window.setWho = setWho;
window.setWeek = setWeek;
window.saveFinding = saveFinding;
window.activatePrecontact = activatePrecontact;
window.openTimer = openTimer;
window.closeTimer = closeTimer;
window.startTimer = startTimer;
window.resetTimer = resetTimer;
window.goToSuggestedDay = goToSuggestedDay;

syncTopbarState();
buildWeekSummary(currentWeek);
buildDayNav(currentWeek);
buildDay(currentDay);
})();
JS;
	}
}
